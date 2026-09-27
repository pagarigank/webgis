<?php
declare(strict_types=1);

namespace App\ImportExport\Http;

use App\Core\Error\ApiError;
use App\Core\Http\Request\JsonBodyParser;
use App\Core\Http\Response\Envelope;
use App\ImportExport\Application\ImportJobService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * TASK-122 — Import and export lifecycle API (api.md §10).
 *
 * POST   /imports                  multipart upload + { source_format, target_entity, target_layer_id? }
 * GET    /imports/{id}             status, counts, detected fields/geometry types
 * PUT    /imports/{id}/mapping     { declared_crs, field_mapping?, options? }
 * POST   /imports/{id}/validate    → status VALIDATED, per-row results in staging
 * GET    /imports/{id}/preview     paginated rows with values, geometry and errors
 * GET    /imports/{id}/errors      CSV error report
 * POST   /imports/{id}/commit      Idempotency-Key; { partial: false }
 * DELETE /imports/{id}             cancel and purge staging
 */
final class ImportController
{
    public function __construct(private readonly ImportJobService $service)
    {
    }

    public function create(Request $request, Response $response): Response
    {
        $uid = $this->requireUser($request);

        $files = $request->getUploadedFiles();
        if (empty($files['file'])) {
            throw new ApiError('VALIDATION_FAILED', 'A multipart "file" part is required.', 422, [
                'fields' => [['field' => 'file', 'rule' => 'REQUIRED']],
            ]);
        }
        /** @var \Psr\Http\Message\UploadedFileInterface $uploaded */
        $uploaded = $files['file'];
        if ($uploaded->getError() !== UPLOAD_ERR_OK) {
            throw new ApiError('VALIDATION_FAILED', 'The file upload failed.', 422);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $options = $body['options'] ?? null;
        if (\is_string($options)) {
            $decoded = json_decode($options, true);
            $options = \is_array($decoded) ? $decoded : [];
        }

        $job = $this->service->createJob($uid, [
            'content' => (string) $uploaded->getStream(),
            'name'    => $uploaded->getClientFilename() ?? 'upload',
        ], [
            'target_entity'   => (string) ($body['target_entity'] ?? ''),
            'target_layer_id' => isset($body['target_layer_id']) ? (int) $body['target_layer_id'] : null,
            'options'         => \is_array($options) ? $options : [],
        ]);

        return Envelope::success($response, $job, 201);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        return Envelope::success($response, $this->service->getJob($this->requireUser($request), $this->id($args)));
    }

    public function setMapping(Request $request, Response $response, array $args): Response
    {
        $body = JsonBodyParser::parse($request, true);
        return Envelope::success($response, $this->service->setMapping($this->requireUser($request), $this->id($args), $body));
    }

    public function validate(Request $request, Response $response, array $args): Response
    {
        return Envelope::success($response, $this->service->validateJob($this->requireUser($request), $this->id($args)));
    }

    public function preview(Request $request, Response $response, array $args): Response
    {
        $q = $request->getQueryParams();
        return Envelope::success($response, $this->service->preview(
            $this->requireUser($request),
            $this->id($args),
            (int) ($q['page'] ?? 1),
            (int) ($q['page_size'] ?? 50),
        ));
    }

    public function errors(Request $request, Response $response, array $args): Response
    {
        $csv = $this->service->errorsCsv($this->requireUser($request), $this->id($args));
        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="import-errors.csv"');
    }

    public function commit(Request $request, Response $response, array $args): Response
    {
        $body = JsonBodyParser::parse($request, true);
        $result = $this->service->commit(
            $this->requireUser($request),
            $this->id($args),
            (bool) ($body['partial'] ?? false),
            trim((string) $request->getHeaderLine('Idempotency-Key')),
        );
        return Envelope::success($response, $result);
    }

    public function cancel(Request $request, Response $response, array $args): Response
    {
        $this->service->cancel($this->requireUser($request), $this->id($args));
        return Envelope::success($response, ['cancelled' => true]);
    }

    // ------------------------------------------------------------------

    private function requireUser(Request $request): int
    {
        $uid = (int) ($request->getAttribute('user_id') ?: 0);
        if ($uid <= 0) {
            throw new ApiError('AUTH_REQUIRED', 'Not authenticated.', 401);
        }
        return $uid;
    }

    private function id(array $args): int
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw new ApiError('NOT_FOUND', 'Import job not found.', 404);
        }
        return $id;
    }
}
