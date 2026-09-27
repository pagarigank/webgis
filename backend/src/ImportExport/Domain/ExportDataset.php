<?php
declare(strict_types=1);

namespace App\ImportExport\Domain;

/**
 * TASK-127 — the format-independent result of reading an export scope.
 *
 * Writers receive this and nothing else: already-scoped rows, PII already
 * redacted or already cleared to include, geometry already in the target CRS.
 * That is what keeps "was PII redacted?" a single decision made once, in the
 * reader, instead of five decisions that can drift apart.
 */
final class ExportDataset
{
    /**
     * @param list<array<string,mixed>> $features each row with decoded
     *        `attributes` and decoded `geometry` (or geometry_wkt when the
     *        writer asked for a flat geometry column)
     * @param list<string> $fieldNames layer field names in display order
     */
    public function __construct(
        public readonly string $format,
        public readonly int $layerId,
        public readonly string $layerName,
        public readonly array $features,
        public readonly array $fieldNames,
        public readonly ExportProvenance $provenance,
    ) {
    }

    public function count(): int
    {
        return count($this->features);
    }
}
