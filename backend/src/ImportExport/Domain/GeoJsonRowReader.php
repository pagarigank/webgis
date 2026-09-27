<?php
declare(strict_types=1);

namespace App\ImportExport\Domain;

use App\Core\Error\ApiError;

/**
 * TASK-122/123 — native PHP GeoJSON reader.
 *
 * Reads a FeatureCollection into rows; a malformed body or a non-FeatureCollection
 * raises IMPORT_INVALID with a useful message rather than a PHP warning.
 */
final class GeoJsonRowReader implements RowReader
{
    public const FORMAT = 'GEOJSON';

    public function supports(string $format): bool
    {
        return strtoupper($format) === self::FORMAT;
    }

    public function read(string $content, string $format, array $options = []): array
    {
        $decoded = json_decode($content, true);
        if (!\is_array($decoded)) {
            throw new ApiError('IMPORT_INVALID', 'The uploaded file is not valid JSON.', 422, [
                'fields' => [['field' => 'file', 'rule' => 'PARSE']],
            ]);
        }

        $features = $decoded['features'] ?? null;
        if (!\is_array($features)) {
            throw new ApiError('IMPORT_INVALID', 'The file is not a GeoJSON FeatureCollection.', 422, [
                'fields' => [['field' => 'file', 'rule' => 'FORMAT']],
            ]);
        }

        $rows = [];
        $n = 0;
        foreach ($features as $feature) {
            $n++;
            if (!\is_array($feature)) {
                $rows[] = ['row_number' => $n, 'raw' => [], 'geometry' => null];
                continue;
            }
            $properties = $feature['properties'] ?? [];
            $geometry   = $feature['geometry'] ?? null;
            $rows[] = [
                'row_number' => $n,
                'raw'        => \is_array($properties) ? $properties : [],
                'geometry'   => \is_array($geometry) ? $geometry : null,
            ];
        }

        return $rows;
    }
}
