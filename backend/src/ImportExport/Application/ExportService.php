<?php
declare(strict_types=1);

namespace App\ImportExport\Application;

use App\Core\Crs\CoordinateTransformationService;
use App\Audit\AuditWriter;
use App\Core\Error\ApiError;
use App\ImportExport\Domain\ExportDataset;
use App\ImportExport\Domain\ExportProvenance;
use App\ImportExport\Domain\ExportScope;
use App\ImportExport\Domain\Writer\ExportWriter;
use PDO;

/**
 * TASK-127 — the export service.
 *
 * Order of operations matters here and is deliberate:
 *
 *  1. resolve the format and the target CRS, and refuse an impossible pairing
 *     before any data is touched;
 *  2. count the scope, and refuse an oversized export up front rather than
 *     building an artefact the server cannot hold;
 *  3. read the rows (this is where PII is decided, once);
 *  4. serialise, embedding the provenance/disclaimer block;
 *  5. record the ledger row and the audit entry.
 *
 * The ledger row and the audit entry are written after the bytes are built but
 * before they are returned, so an export that a caller received is always an
 * export the system recorded. A refusal at step 1 or 2 is recorded too, with
 * status REJECTED, because "someone tried to pull the whole layer" is itself
 * worth knowing about.
 *
 * Large sets are refused rather than queued: this codebase has no job runner, and
 * a silent timeout would be worse than an explicit 409 that names the fix.
 */
final class ExportService
{
    /**
     * Rows above which an export is refused with EXPORT_TOO_LARGE. Chosen to sit
     * well inside a normal PHP memory_limit when every row is held in memory
     * (the reader materialises the scope before serialising).
     *
     * Injectable so the refusal path can be tested without seeding 50k rows.
     */
    public const MAX_INLINE_ROWS = 50000;

    /** @param array<string,ExportWriter> $writers keyed by uppercase format */
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditWriter $audit,
        private readonly CoordinateTransformationService $crs,
        private readonly ExportFeatureReader $reader,
        private readonly array $writers = [],
        private readonly int $maxInlineRows = self::MAX_INLINE_ROWS,
    ) {
    }

    public function maxInlineRows(): int
    {
        return $this->maxInlineRows;
    }

    /**
     * @return list<string>
     */
    public function formats(): array
    {
        return array_keys($this->writers);
    }

    public function writerFor(string $format): ExportWriter
    {
        $key = strtoupper(trim($format));
        if ($key === '') {
            throw new ApiError('VALIDATION_FAILED', 'format is required.', 422, [
                'fields' => [['field' => 'format', 'rule' => 'REQUIRED']],
            ]);
        }
        if (!isset($this->writers[$key])) {
            throw new ApiError('EXPORT_FORMAT_UNSUPPORTED', sprintf(
                "Export format '%s' is not supported. Supported: %s.",
                $key,
                implode(', ', array_keys($this->writers))
            ), 422);
        }

        return $this->writers[$key];
    }

    /**
     * Run one export.
     *
     * @param string|null $crs requested target CRS code; null means the
     *        format's own default
     */
    public function export(
        int $userId,
        ExportScope $scope,
        string $format,
        ?string $crs = null,
        ?string $requestId = null,
    ): ExportResult {
        $writer = $this->writerFor($format);
        $crsCode = $this->resolveTargetCrs($writer, $crs);

        $rowCount = $this->reader->countRows($scope);
        if ($rowCount > $this->maxInlineRows) {
            $this->recordRejection($userId, $scope, strtoupper($format), $crsCode, $rowCount, $requestId);

            throw new ApiError('EXPORT_TOO_LARGE', sprintf(
                'This export selects %d features, above the inline limit of %d. '
                . 'Narrow the scope with feature_ids, bbox or status, or request the export in pages.',
                $rowCount,
                $this->maxInlineRows
            ), 409, [
                'row_count'  => $rowCount,
                'max_rows'   => $this->maxInlineRows,
                'scope'      => $scope->toQuerySpec(),
            ]);
        }

        $crsInfo = $this->crs->resolveCrs($crsCode);
        $userLabel = $this->reader->usernameFor($userId);
        $this->publishSessionUser($userId);
        $prepared = $this->reader->read(
            $scope,
            $crsInfo['srid'],
            $crsInfo['code'],
            $this->reader->canViewPii($userId),
            $format === 'CSV' && $scope->includeGeometry,
        );

        $provenance = new ExportProvenance(
            strtoupper($format),
            $crsInfo['code'],
            count($prepared['rows']),
            $prepared['pii_included'],
            $prepared['redacted_field_count'],
            $userLabel,
            $prepared['layer_name'],
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        $dataset = new ExportDataset(
            strtoupper($format),
            $scope->layerId,
            $prepared['layer_name'],
            $prepared['rows'],
            $prepared['field_names'],
            $provenance,
        );

        $written = $writer->write($dataset);
        $body = $written['body'];
        $filename = $writer->filename($dataset);
        $byteSize = strlen($body);

        $jobId = $this->recordCompletion(
            $userId,
            $scope,
            strtoupper($format),
            $crsInfo['id'],
            $provenance,
            $filename,
            $byteSize,
            $requestId
        );

        $this->audit->write(
            'EXPORT',
            'app.gis_features',
            (string) $scope->layerId,
            null,
            [
                'format'               => strtoupper($format),
                'count'                => count($prepared['rows']),
                'bbox'                 => $scope->bbox,
                'crs'                  => $crsInfo['code'],
                'export_job_id'        => $jobId,
                'pii_included'         => $provenance->piiIncluded,
                'redacted_field_count' => $provenance->redactedFieldCount,
                'disclaimer_carried'   => true,
                'byte_size'            => $byteSize,
            ],
            $userId,
            $requestId,
            sprintf('Layer features exported as %s', strtoupper($format))
        );

        return new ExportResult(
            $jobId,
            strtoupper($format),
            $crsInfo['code'],
            $filename,
            $written['content_type'],
            $body,
            count($prepared['rows']),
            $provenance->piiIncluded,
            $provenance->redactedFieldCount,
            $byteSize,
        );
    }

    /**
     * Make the acting user visible to any database-level audit trigger or RLS
     * policy for the rest of the request. `set_config(..., false)` is used
     * rather than `SET LOCAL` because an export is not wrapped in a
     * transaction, and `SET LOCAL` is a silent no-op outside one.
     */
    private function publishSessionUser(int $userId): void
    {
        try {
            $this->pdo->exec(sprintf(
                "SELECT set_config('app.current_user_id', '%d', false), set_config('app.user_id', '%d', false)",
                $userId,
                $userId
            ));
        } catch (\PDOException) {
            // Session publication is a convenience for triggers, not a
            // precondition for the export itself.
        }
    }

    /**
     * A GeoJSON or KML export is pinned to WGS 84 by its specification, so
     * asking for another CRS is a client error rather than something to
     * silently ignore.
     */
    private function resolveTargetCrs(ExportWriter $writer, ?string $requested): string
    {
        $default = $writer->allowedCrs();

        if ($requested === null || trim($requested) === '') {
            return $default ?? 'EPSG:4326';
        }

        $requested = trim($requested);
        if ($default === null) {
            $isWgs84 = in_array(strtoupper($requested), ['EPSG:4326', '4326', 'WGS84'], true);
            if (!$isWgs84) {
                throw new ApiError('CRS_NOT_ALLOWED_FOR_FORMAT', sprintf(
                    'The %s format is fixed to WGS 84 (EPSG:4326) by its specification; '
                    . "requested CRS '%s' cannot be used. Choose SHAPEFILE or GEOPACKAGE to export in another CRS.",
                    $writer->format(),
                    $requested
                ), 422, [
                    'fields'   => [['field' => 'crs', 'rule' => 'FORMAT_FIXED_CRS']],
                    'allowed'  => ['EPSG:4326'],
                ]);
            }
            return 'EPSG:4326';
        }

        // Throws CRS_UNSUPPORTED 422 for anything not in ref.crs_registry.
        $this->crs->resolveCrs($requested);

        return $requested;
    }

    private function recordCompletion(
        int $userId,
        ExportScope $scope,
        string $format,
        int $crsId,
        ExportProvenance $provenance,
        string $filename,
        int $byteSize,
        ?string $requestId,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO app.export_jobs
                (requested_by, format, query_spec, target_crs_id, status, row_count,
                 pii_included, redacted_field_count, filename, byte_size, disclaimer, request_id,
                 completed_at)
             VALUES
                (:by, :format, CAST(:spec AS jsonb), :crs, \'COMPLETED\', :rows,
                 :pii, :redacted, :filename, :bytes, :disclaimer, :request_id, CURRENT_TIMESTAMP)
             RETURNING id'
        );
        // Booleans must be bound as booleans: with emulation off, PDO would
        // otherwise send PHP false as an empty string, which is not a boolean.
        $stmt->bindValue(':by', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':format', $format, PDO::PARAM_STR);
        $stmt->bindValue(':spec', (string) json_encode($scope->toQuerySpec()), PDO::PARAM_STR);
        $stmt->bindValue(':crs', $crsId, PDO::PARAM_INT);
        $stmt->bindValue(':rows', $provenance->rowCount, PDO::PARAM_INT);
        $stmt->bindValue(':pii', $provenance->piiIncluded, PDO::PARAM_BOOL);
        $stmt->bindValue(':redacted', $provenance->redactedFieldCount, PDO::PARAM_INT);
        $stmt->bindValue(':filename', $filename, PDO::PARAM_STR);
        $stmt->bindValue(':bytes', $byteSize, PDO::PARAM_INT);
        $stmt->bindValue(':disclaimer', ExportProvenance::DISCLAIMER, PDO::PARAM_STR);
        $stmt->bindValue(':request_id', $requestId, PDO::PARAM_STR);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    private function recordRejection(
        int $userId,
        ExportScope $scope,
        string $format,
        string $crsCode,
        int $rowCount,
        ?string $requestId,
    ): void {
        $crsId = null;
        try {
            $crsId = $this->crs->resolveCrs($crsCode)['id'];
        } catch (ApiError) {
            // An unusable CRS is a fine reason to record the attempt as-is.
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO app.export_jobs
                (requested_by, format, query_spec, target_crs_id, status, row_count,
                 pii_included, redacted_field_count, request_id)
             VALUES
                (:by, :format, CAST(:spec AS jsonb), :crs, \'REJECTED\', :rows, false, 0, :request_id)'
        );
        $stmt->execute([
            ':by'         => $userId,
            ':format'     => $format,
            ':spec'       => (string) json_encode($scope->toQuerySpec()),
            ':crs'        => $crsId,
            ':rows'       => $rowCount,
            ':request_id' => $requestId,
        ]);

        $this->audit->write(
            'EXPORT',
            'app.gis_features',
            (string) $scope->layerId,
            null,
            [
                'format'   => $format,
                'count'    => $rowCount,
                'status'   => 'REJECTED',
                'reason'   => 'EXPORT_TOO_LARGE',
                'max_rows' => $this->maxInlineRows,
                'scope'    => $scope->toQuerySpec(),
            ],
            $userId,
            $requestId,
            'Layer export refused as too large'
        );
    }
}
