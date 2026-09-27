<?php
declare(strict_types=1);

namespace App\ImportExport\Application;

/**
 * TASK-127 — what one completed export produced.
 *
 * Returned by the service and turned into an HTTP response by the controller.
 * The provenance fields are echoed back so the caller can see, without parsing
 * the artefact, whether PII was included and how many rows went out.
 */
final class ExportResult
{
    public function __construct(
        public readonly int $jobId,
        public readonly string $format,
        public readonly string $crsCode,
        public readonly string $filename,
        public readonly string $contentType,
        public readonly string $body,
        public readonly int $rowCount,
        public readonly bool $piiIncluded,
        public readonly int $redactedFieldCount,
        public readonly int $byteSize,
    ) {
    }
}
