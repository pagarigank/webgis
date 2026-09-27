<?php
declare(strict_types=1);

namespace App\ImportExport\Domain;

/**
 * TASK-122 — reads a stored import source into raw rows for staging.
 *
 * A reader is format-specific and does no database work and no CRS
 * transformation; it turns bytes into `{row_number, raw, geometry}` records.
 * Geometry is a GeoJSON geometry fragment as decoded from the source, in the
 * source's own coordinates — the declared CRS is applied later by
 * `ImportJobService` when the row is written to `staging.import_job_rows`.
 */
interface RowReader
{
    /**
     * @param array<string,mixed> $options job options (e.g. CSV coordinate columns)
     * @return list<array{row_number:int, raw:array<string,mixed>, geometry:?array<string,mixed>}>
     * @throws \App\Core\Error\ApiError IMPORT_INVALID when the source cannot be read
     */
    public function read(string $content, string $format, array $options = []): array;

    /** Whether this reader handles the given api.md source format. */
    public function supports(string $format): bool;
}
