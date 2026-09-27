<?php
declare(strict_types=1);

namespace App\ImportExport\Domain;

use App\Core\Error\ApiError;

/**
 * TASK-123 — native PHP CSV reader with coordinate-column mapping (FR-175).
 *
 * The first row is the header; each data row becomes
 * `{row_number, raw (header → cell), geometry}`. Coordinate columns are named
 * through the job's `options`:
 *
 *   options.coordinate_columns = { "latitude": "lat", "longitude": "lon" }
 *   options.coordinate_columns = { "x": "easting", "y": "northing" }
 *
 * (the flat `latitude_column`/`longitude_column`/`x_column`/`y_column` keys are
 * also accepted). A row whose coordinate cells are absent or non-numeric keeps
 * a null geometry and is rejected per-row by the lifecycle (VR-32) — it is
 * never silently dropped or coerced.
 */
final class CsvRowReader implements RowReader
{
    public const FORMAT = 'CSV';

    public function supports(string $format): bool
    {
        return strtoupper($format) === self::FORMAT;
    }

    public function read(string $content, string $format, array $options = []): array
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new ApiError('IMPORT_INVALID', 'The CSV upload could not be read.', 422);
        }

        try {
            fwrite($handle, $content);
            rewind($handle);

            $header = fgetcsv($handle);
            if ($header === false || $header === [null] || $header === []) {
                throw new ApiError('IMPORT_INVALID', 'The CSV has no header row.', 422, [
                    'fields' => [['field' => 'file', 'rule' => 'HEADER']],
                ]);
            }

            // Strip a UTF-8 BOM from the first column name.
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]) ?? (string) $header[0];

            $map = $this->coordinateColumns($options);

            $rows = [];
            $n = 0;
            while (($cells = fgetcsv($handle)) !== false) {
                $n++;
                // Skip a fully blank trailing/embedded line.
                if ($cells === [null] || $cells === ['']) {
                    continue;
                }

                $raw = [];
                foreach ($header as $i => $name) {
                    $raw[(string) $name] = $cells[$i] ?? null;
                }

                $rows[] = [
                    'row_number' => $n,
                    'raw'        => $raw,
                    'geometry'   => $this->pointFor($raw, $map),
                ];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<string,mixed> $options
     * @return array{lat:?string,lon:?string,x:?string,y:?string}
     */
    private function coordinateColumns(array $options): array
    {
        $cols = \is_array($options['coordinate_columns'] ?? null) ? $options['coordinate_columns'] : [];

        return [
            'lat' => $this->name($cols['latitude'] ?? $options['latitude_column'] ?? null),
            'lon' => $this->name($cols['longitude'] ?? $options['longitude_column'] ?? null),
            'x'   => $this->name($cols['x'] ?? $options['x_column'] ?? null),
            'y'   => $this->name($cols['y'] ?? $options['y_column'] ?? null),
        ];
    }

    private function name(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string,mixed> $raw
     * @param array{lat:?string,lon:?string,x:?string,y:?string} $map
     * @return array<string,mixed>|null GeoJSON Point, or null when no usable coordinates
     */
    private function pointFor(array $raw, array $map): ?array
    {
        $lat = $this->number($raw, $map['lat']);
        $lon = $this->number($raw, $map['lon']);
        if ($lat !== null && $lon !== null) {
            // GeoJSON x = longitude, y = latitude.
            return ['type' => 'Point', 'coordinates' => [$lon, $lat]];
        }

        $x = $this->number($raw, $map['x']);
        $y = $this->number($raw, $map['y']);
        if ($x !== null && $y !== null) {
            return ['type' => 'Point', 'coordinates' => [$x, $y]];
        }

        return null;
    }

    /**
     * @param array<string,mixed> $raw
     */
    private function number(array $raw, ?string $column): ?float
    {
        if ($column === null || !array_key_exists($column, $raw)) {
            return null;
        }
        $value = $raw[$column];
        if (!\is_string($value) && !\is_int($value) && !\is_float($value)) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }
}
