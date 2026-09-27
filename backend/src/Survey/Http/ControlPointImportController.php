<?php
declare(strict_types=1);

namespace App\Survey\Http;

use App\Core\Error\ApiError;
use App\Core\Http\Response\Envelope;
use App\Survey\Application\ControlPointImportService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * TASK-126 - control point bulk import.
 *
 * POST /control-points/import
 *
 * Two request shapes, because the two callers are different:
 *
 *   - multipart/form-data with a `file` part, for the browser uploader;
 *   - application/json with a `csv` string, for scripts and CI.
 *
 * Everything else is a form field / JSON key: `native_crs`, `coordinate_origin`,
 * `field_map`, `default_point_type`, `duplicate_radius_m`, `commit`.
 *
 * The response is a per-row report whether or not the import committed, so a
 * caller can always find out which lines failed and why. See
 * ControlPointImportService for why duplicates are reported rather than merged.
 */
final class ControlPointImportController
{
    /** Guards the raw body read below; matches php.ini post_max_size. */
    private const MAX_UPLOAD_BYTES = 8 * 1024 * 1024;

    public function __construct(private readonly ControlPointImportService $service)
    {
    }

    public function import(Request $request, Response $response): Response
    {
        $uid = (int) ($request->getAttribute('user_id') ?: 0);
        if ($uid <= 0) {
            throw new ApiError('UNAUTHORIZED', 'Not authenticated', 401);
        }

        $options = $this->readOptions($request);

        $report = $this->service->import($options['csv'], $options, $uid);

        return Envelope::success($response, $report);
    }

    /**
     * Pull the CSV and the import options out of either request shape.
     *
     * The presence of an uploaded file is what selects the multipart path, not
     * the Content-Type header: the two always travel together from a browser,
     * and trusting the header alone would turn a mislabelled-but-complete
     * request into a confusing "csv is required" error.
     *
     * @return array<string,mixed>
     */
    private function readOptions(Request $request): array
    {
        $files = $request->getUploadedFiles();

        if ($files !== []) {
            $parsed = $request->getParsedBody();
            $options = is_array($parsed) ? $parsed : [];

            $upload = $files['file'] ?? null;
            if (!$upload instanceof UploadedFileInterface) {
                throw new ApiError('IMPORT_INVALID', 'A CSV file part named "file" is required.', 400, [
                    'fields' => [['field' => 'file', 'rule' => 'REQUIRED']],
                ]);
            }
            if ($upload->getError() !== UPLOAD_ERR_OK) {
                throw new ApiError(
                    'IMPORT_INVALID',
                    sprintf('The CSV upload failed (PHP upload error %d).', $upload->getError()),
                    400,
                    ['fields' => [['field' => 'file', 'rule' => 'UPLOAD', 'code' => $upload->getError()]]]
                );
            }

            $size = $upload->getSize();
            if ($size !== null && $size > self::MAX_UPLOAD_BYTES) {
                throw new ApiError('IMPORT_INVALID', 'The CSV upload is too large.', 413, [
                    'fields' => [['field' => 'file', 'rule' => 'MAX_SIZE', 'limit' => self::MAX_UPLOAD_BYTES]],
                ]);
            }

            // Casting the stream rewinds it first, so this does not depend on
            // how much of it has already been consumed.
            $csv = (string) $upload->getStream();
        } else {
            $body = $request->getParsedBody();
            if (!is_array($body)) {
                throw new ApiError('VALIDATION_FAILED', 'Request body must be a JSON object', 400);
            }
            $csv = $body['csv'] ?? null;
            if (!is_string($csv) || trim($csv) === '') {
                throw new ApiError('IMPORT_INVALID', 'A "csv" string is required.', 400, [
                    'fields' => [['field' => 'csv', 'rule' => 'REQUIRED']],
                ]);
            }
            unset($body['csv']);
            $options = $body;
        }

        if (strlen($csv) > self::MAX_UPLOAD_BYTES) {
            throw new ApiError('IMPORT_INVALID', 'The CSV is too large.', 413, [
                'fields' => [['field' => 'csv', 'rule' => 'MAX_SIZE', 'limit' => self::MAX_UPLOAD_BYTES]],
            ]);
        }

        if (isset($options['field_map']) && !is_array($options['field_map'])) {
            throw new ApiError('VALIDATION_FAILED', 'field_map must be an object of target => column', 400, [
                'fields' => ['field_map' => 'must be an object'],
            ]);
        }

        $options['csv']    = $csv;
        $options['commit'] = $this->toBool($options['commit'] ?? false);

        return $options;
    }

    /**
     * Accept the several spellings a form or a shell script will produce for
     * a boolean, because "commit=false" as a string must not become true.
     */
    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (!is_string($value)) {
            return false;
        }
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on', 'commit'], true);
    }
}
