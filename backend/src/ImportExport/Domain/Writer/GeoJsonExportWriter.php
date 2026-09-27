<?php
declare(strict_types=1);

namespace App\ImportExport\Domain\Writer;

use App\ImportExport\Domain\ExportDataset;

/**
 * TASK-127 — GeoJSON writer (RFC 7946).
 *
 * Feature `properties` deliberately carry only the five fields the pre-TASK-127
 * `/features.geojson` route returned (id, status, psgc_barangay, provenance,
 * attributes) so delegating that route to this service is a no-op for clients.
 * The provenance block travels as a top-level foreign member, which RFC 7946
 * permits and which every GeoJSON consumer ignores safely.
 */
final class GeoJsonExportWriter implements ExportWriter
{
    public function format(): string
    {
        return 'GEOJSON';
    }

    /** RFC 7946 fixes the CRS at WGS 84; the caller may not choose another. */
    public function allowedCrs(): ?string
    {
        return null;
    }

    public function write(ExportDataset $dataset): array
    {
        $features = [];
        foreach ($dataset->features as $row) {
            $features[] = [
                'type'       => 'Feature',
                'id'         => $row['id'],
                'geometry'   => $row['geometry'],
                'properties' => [
                    'id'            => $row['id'],
                    'status'        => $row['status'],
                    'psgc_barangay' => $row['psgc_barangay'],
                    'provenance'    => $row['provenance'],
                    'attributes'    => (object) ($row['attributes'] ?? []),
                ],
            ];
        }

        $payload = [
            'type'     => 'FeatureCollection',
            // Foreign member, outside the RFC 7946 required members.
            'x_webgis_export' => $dataset->provenance->toArray(),
            'features' => $features,
        ];

        $body = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
        if ($body === false) {
            $body = json_encode(['type' => 'FeatureCollection', 'features' => []]);
        }

        return ['content_type' => 'application/geo+json', 'body' => (string) $body];
    }

    public function filename(ExportDataset $dataset): string
    {
        return sprintf('layer_%d_features.geojson', $dataset->layerId);
    }
}
