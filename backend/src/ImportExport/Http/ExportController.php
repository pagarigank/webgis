<?php
declare(strict_types=1);

namespace App\ImportExport\Http;

use App\Core\Error\ApiError;
use App\Core\Http\Request\JsonBodyParser;
use App\Core\Http\Response\Envelope;
use App\RBAC\FeatureScopeResolver;
use App\ImportExport\Application\ExportService;
use App\ImportExport\Domain\ExportProvenance;
use App\ImportExport\Domain\ExportScope;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * TASK-127 — Export API (api.md §10).
 *
 * POST /layers/{layer_id}/exports   stream an export of a layer as a download
 * GET  /exports/formats             the formats, CRS rules and limits the UI needs
 * GET  /exports                     recent entries from the export ledger
 *
 * The response body is the artefact itself rather than a job handle: this
 * codebase has no background runner, and an export either completes inside the
 * request or is refused with EXPORT_TOO_LARGE before any work is done. What
 * happened is still recorded, in `app.export_jobs` and in the audit log, so the
 * ledger remains the place to ask "who took this data, and when".
 *
 * Note the two separate checks this controller makes. `export.execute` is
 * applied by the route as a blanket "may use the export feature at all", and is
 * held by very few roles. The per-layer `can_view` capability is checked here,
 * because holding the former must not be a way to read a layer the caller
 * cannot otherwise see.
 */
final class ExportController
{
    public function __construct(
        private readonly ExportService $service,
        private readonly PDO $pdo,
    ) {
    }

    public function create(Request $request, Response $response, array $args): Response
    {
        $userId = $this->requireUser($request);
        $layerId = (int) ($args['layer_id'] ?? 0);
        $this->requireLayerView($request, $userId, $layerId);

        $input = $this->input($request, $args);
        $scope = ExportScope::fromArray($input);

        $result = $this->service->export(
            $userId,
            $scope,
            (string) ($input['format'] ?? ''),
            isset($input['crs']) ? (string) $input['crs'] : null,
            $request->getAttribute('request_id'),
        );

        $response->getBody()->write($result->body);

        return $response
            ->withHeader('Content-Type', $result->contentType)
            ->withHeader('Content-Disposition', sprintf('attachment; filename="%s"', $result->filename))
            ->withHeader('Content-Length', (string) $result->byteSize)
            // The provenance is echoed as headers too, so the UI can tell the
            // user whether PII was included without parsing the file it just
            // received.
            ->withHeader('X-Export-Job-Id', (string) $result->jobId)
            ->withHeader('X-Export-Format', $result->format)
            ->withHeader('X-Export-Crs', $result->crsCode)
            ->withHeader('X-Export-Row-Count', (string) $result->rowCount)
            ->withHeader('X-Export-Pii-Included', $result->piiIncluded ? 'true' : 'false')
            ->withHeader('X-Export-Redacted-Field-Count', (string) $result->redactedFieldCount)
            ->withHeader('X-Export-Disclaimer', rawurlencode(ExportProvenance::DISCLAIMER))
            ->withStatus(200);
    }

    /**
     * What the export UI needs to build its format picker: which formats exist,
     * which of them may be reprojected, and the ceiling the row cap enforces.
     */
    public function formats(Request $request, Response $response): Response
    {
        $this->requireUser($request);

        $described = [];
        foreach ($this->service->formats() as $format) {
            $writer = $this->service->writerFor($format);
            $crs = $writer->allowedCrs();
            $described[] = [
                'format'         => $format,
                'crs_selectable' => $crs !== null,
                'default_crs'    => $crs ?? 'EPSG:4326',
                'fixed_crs'      => $crs === null ? 'EPSG:4326' : null,
                'filename'       => $writer->filename($this->placeholderDataset($format)),
            ];
        }

        return Envelope::success($response, [
            'formats'         => $described,
            'max_inline_rows' => $this->service->maxInlineRows(),
            'statuses'        => ExportScope::STATUSES,
            'sortable'        => ExportScope::SORTABLE,
            'disclaimer'      => ExportProvenance::DISCLAIMER,
        ]);
    }

    /**
     * The export ledger. Reads back what `app.export_jobs` recorded, newest
     * first, so an administrator can answer "was this data taken, by whom, was
     * PII in it, and did we refuse any attempts".
     */
    public function history(Request $request, Response $response): Response
    {
        $this->requireUser($request);
        $q = $request->getQueryParams();
        $limit = max(1, min(200, (int) ($q['limit'] ?? 50)));

        $stmt = $this->pdo->prepare(
            'SELECT j.id, j.format, j.status, j.row_count, j.pii_included,
                    j.redacted_field_count, j.filename, j.byte_size, j.request_id,
                    j.requested_at, j.completed_at, j.query_spec,
                    u.username AS requested_by_username,
                    c.code AS crs_code
             FROM app.export_jobs j
             LEFT JOIN app.users u ON u.id = j.requested_by
             LEFT JOIN ref.crs_registry c ON c.id = j.target_crs_id
             ORDER BY j.id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $index => $row) {
            $spec = is_string($row['query_spec'] ?? null) ? json_decode($row['query_spec'], true) : $row['query_spec'];
            $rows[$index]['query_spec'] = is_array($spec) ? $spec : null;
            $rows[$index]['pii_included'] = (bool) $row['pii_included'];
        }

        return Envelope::success($response, ['exports' => $rows, 'limit' => $limit]);
    }

    // ------------------------------------------------------------------

    /**
     * Scope parameters may arrive as a JSON body or as query parameters; both
     * are accepted so the same endpoint serves a fetch() and a plain link
     * download. Query values win only where the body is silent.
     *
     * @return array<string,mixed>
     */
    private function input(Request $request, array $args): array
    {
        $input = [];

        $contentType = strtolower($request->getHeaderLine('Content-Type'));
        if (str_contains($contentType, 'application/json')) {
            $input = JsonBodyParser::parse($request, true);
        } else {
            $body = $request->getParsedBody();
            if (is_array($body)) {
                $input = $body;
            }
        }

        foreach ($request->getQueryParams() as $key => $value) {
            $input[$key] = $value;
        }

        $input['layer_id'] = (int) ($args['layer_id'] ?? 0);
        if (isset($input['format'])) {
            $input['format'] = strtoupper(trim((string) $input['format']));
        }

        return $input;
    }

    private function requireUser(Request $request): int
    {
        $uid = (int) ($request->getAttribute('user_id') ?: 0);
        if ($uid <= 0) {
            throw new ApiError('AUTH_REQUIRED', 'Not authenticated.', 401);
        }

        return $uid;
    }

    private function requireLayerView(Request $request, int $userId, int $layerId): void
    {
        if ($layerId <= 0) {
            throw new ApiError('VALIDATION_FAILED', 'layer_id must be a positive integer.', 422, [
                'fields' => [['field' => 'layer_id', 'rule' => 'REQUIRED']],
            ]);
        }

        $capsule = $request->getAttribute('feature_scope_resolver');
        if (!$capsule instanceof FeatureScopeResolver) {
            throw new ApiError('INTERNAL_ERROR', 'Feature scope resolver missing from container', 500);
        }

        if (!$capsule->getLayerCapabilities($userId, $layerId)['can_view']) {
            throw new ApiError('FORBIDDEN', 'No permission to view features in this layer', 403);
        }
    }

    /**
     * Only the filename pattern is needed to describe a format, and it depends
     * on the layer id, so a zeroed dataset is enough.
     */
    private function placeholderDataset(string $format): \App\ImportExport\Domain\ExportDataset
    {
        $provenance = new ExportProvenance(
            $format,
            'EPSG:4326',
            0,
            false,
            0,
            'system',
            'placeholder',
            new \DateTimeImmutable('@0'),
        );

        return new \App\ImportExport\Domain\ExportDataset(
            $format,
            0,
            'placeholder',
            [],
            [],
            $provenance,
        );
    }
}
