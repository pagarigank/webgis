<?php
declare(strict_types=1);

namespace App\ImportExport\Domain\Writer;

use App\ImportExport\Domain\ExportDataset;

/**
 * TASK-127 — CSV writer.
 *
 * The disclaimer and provenance go in as leading `#` lines: `#` is the least
 * intrusive CSV comment convention, so a spreadsheet still opens the file on the
 * header row while the block stays readable to a human or a `grep`.
 *
 * Geometry is omitted by default, matching the long-standing `/features.csv`
 * contract (an attribute table). Callers that want coordinates opt in with
 * `include_geometry`, which appends a `geometry_wkt` column in the target CRS.
 */
final class CsvExportWriter implements ExportWriter
{
    /** Base columns, in the order the existing CSV endpoint has always used. */
    private const BASE_COLUMNS = [
        'id', 'status', 'psgc_barangay', 'provenance', 'version', 'created_at', 'updated_at',
    ];

    public function format(): string
    {
        return 'CSV';
    }

    public function allowedCrs(): ?string
    {
        return 'EPSG:4326';
    }

    public function write(ExportDataset $dataset): array
    {
        $fp = fopen('php://temp', 'r+');
        if ($fp === false) {
            return ['content_type' => 'text/csv; charset=UTF-8', 'body' => ''];
        }

        // The provenance block is written as plain text rather than through
        // fputcsv: it is not a CSV field, and encoding it as one would wrap the
        // disclaimer in quotes because it contains a comma, which then stops it
        // reading as a comment.
        foreach ($dataset->provenance->toLines() as $line) {
            fwrite($fp, '# ' . str_replace(["\r", "\n"], ' ', $line) . "\n");
        }

        $hasGeometry = $dataset->features !== []
            && array_key_exists('geometry_wkt', $dataset->features[0]);
        $headers = self::BASE_COLUMNS;
        foreach ($dataset->fieldNames as $name) {
            $headers[] = $name;
        }
        if ($hasGeometry) {
            $headers[] = 'geometry_wkt';
        }
        fputcsv($fp, $headers);

        foreach ($dataset->features as $row) {
            $attributes = is_array($row['attributes'] ?? null) ? $row['attributes'] : [];
            $line = [
                $row['id'],
                $row['status'],
                $row['psgc_barangay'] ?? '',
                $row['provenance'] ?? '',
                $row['version'] ?? '',
                $row['created_at'] ?? '',
                $row['updated_at'] ?? '',
            ];
            foreach ($dataset->fieldNames as $name) {
                $value = $attributes[$name] ?? '';
                $line[] = is_array($value) ? (string) json_encode($value) : (string) $value;
            }
            if ($hasGeometry) {
                $line[] = (string) ($row['geometry_wkt'] ?? '');
            }
            fputcsv($fp, $line);
        }

        rewind($fp);
        $body = (string) stream_get_contents($fp);
        fclose($fp);

        return ['content_type' => 'text/csv; charset=UTF-8', 'body' => $body];
    }

    public function filename(ExportDataset $dataset): string
    {
        return sprintf('layer_%d_features.csv', $dataset->layerId);
    }
}
