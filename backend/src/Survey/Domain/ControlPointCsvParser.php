<?php
declare(strict_types=1);

namespace App\Survey\Domain;

use App\Core\Error\ApiError;

/**
 * TASK-126 - pure CSV parsing and per-row validation for control point import.
 *
 * Contains no database I/O: it turns CSV text into normalised rows plus a
 * per-row error list. Everything that needs the database (CRS resolution,
 * coordinate derivation, area-of-use, duplicate detection) is the caller's job,
 * which keeps this unit-testable without a fixture.
 *
 * Design notes:
 *
 *  - A bad row is REPORTED, never dropped and never coerced. Every row that
 *    carries data comes back with a `row_number` the user can find in their own
 *    file, plus the fields that failed. That is the point of per-row
 *    validation: a 200-row schedule with 3 typos must import 197 points and
 *    name the 3 lines to fix, not reject the whole file.
 *  - `status` is deliberately not importable. Status belongs to the verify
 *    workflow (TASK-073), so a CSV column mapping onto it is rejected by
 *    resolveColumns() rather than silently ignored - otherwise a surveyor's
 *    "VERIFIED" column would appear to work while quietly marking unverified
 *    points as verified.
 *  - The CRS is NOT read from the file. The caller declares it (`native_crs`)
 *    and it applies to every row, which is what makes the declaration mean
 *    something.
 */
final class ControlPointCsvParser
{
    /** Hard cap so one request cannot ask to import an unbounded file. */
    public const MAX_ROWS = 5000;

    /** Mirrors ck_cp_point_type on app.survey_control_points. */
    public const POINT_TYPES = [
        'BLLM', 'MBM', 'PBM', 'GCP', 'CONTROL_POINT', 'TIE_POINT', 'REFERENCE_POINT', 'OTHER',
    ];

    /** Importable target fields, in a stable order. */
    private const FIELDS = [
        'point_name', 'point_type', 'monument_type', 'easting', 'northing', 'latitude', 'longitude',
        'elevation', 'source', 'survey_reference', 'accuracy_class', 'accuracy_value_m',
        'description', 'psgc_barangay',
    ];

    /** Target field => default CSV column name. */
    private const DEFAULT_COLUMNS = [
        'point_name'       => 'point_name',
        'point_type'       => 'point_type',
        'monument_type'    => 'monument_type',
        'easting'          => 'easting',
        'northing'         => 'northing',
        'latitude'         => 'latitude',
        'longitude'        => 'longitude',
        'elevation'        => 'elevation',
        'source'           => 'source',
        'survey_reference' => 'survey_reference',
        'accuracy_class'   => 'accuracy_class',
        'accuracy_value_m' => 'accuracy_value_m',
        'description'      => 'description',
        'psgc_barangay'    => 'psgc_barangay',
    ];

    /**
     * Target field => maximum length. Mirrors the column limits in the schema.
     * `description` is a text column with no limit, so it is absent here.
     */
    private const MAX_LENGTHS = [
        'point_name'       => 80,
        'monument_type'    => 60,
        'source'           => 160,
        'survey_reference' => 160,
        'accuracy_class'   => 40,
    ];

    /** Length-limited text fields. */
    private const TEXT_FIELDS = ['monument_type', 'source', 'survey_reference', 'accuracy_class'];

    /** Target fields whose value must be a finite number. */
    private const NUMERIC_FIELDS = [
        'easting', 'northing', 'latitude', 'longitude', 'elevation', 'accuracy_value_m',
    ];

    /**
     * Parse CSV text into validated rows.
     *
     * @param array{field_map?:array<string,mixed>,coordinate_origin?:string,default_point_type?:mixed} $options
     *
     * @return array{
     *     header: array<int,string>,
     *     columns: array<string,string>,
     *     rows: array<int,array{row_number:int,fields:array<string,mixed>,errors:array<string,string>}>
     * }
     */
    public static function parse(string $csv, array $options = []): array
    {
        $origin = self::resolveOrigin($options['coordinate_origin'] ?? null);
        $defaultType = self::resolveDefaultType($options['default_point_type'] ?? null);
        $columns = self::resolveColumns($options['field_map'] ?? []);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new ApiError('IMPORT_INVALID', 'The CSV upload could not be read.', 422);
        }

        try {
            fwrite($handle, $csv);
            rewind($handle);

            $header = fgetcsv($handle);
            if ($header === false || $header === [null] || $header === []) {
                throw new ApiError('IMPORT_INVALID', 'The CSV has no header row.', 422, [
                    'fields' => [['field' => 'file', 'rule' => 'HEADER']],
                ]);
            }

            // Strip a UTF-8 BOM from the first column name.
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]) ?? (string) $header[0];
            $header = array_map(static fn ($h) => trim((string) $h), $header);

            $rows = [];
            $seen = 0;
            $lineNo = 0;

            while (($cells = fgetcsv($handle)) !== false) {
                $lineNo++;
                // Skip a fully blank line (usually just the trailing newline).
                if ($cells === [null] || $cells === ['']) {
                    continue;
                }

                $seen++;
                if ($seen > self::MAX_ROWS) {
                    throw new ApiError(
                        'IMPORT_INVALID',
                        sprintf('The CSV has more than %d data rows.', self::MAX_ROWS),
                        422,
                        ['fields' => [['field' => 'file', 'rule' => 'MAX_ROWS', 'limit' => self::MAX_ROWS]]]
                    );
                }

                $raw = [];
                foreach ($header as $i => $name) {
                    $raw[$name] = $cells[$i] ?? null;
                }

                $parsed = self::parseRow($raw, $columns, $origin, $defaultType);

                $rows[] = [
                    // The header is line 1, so the first data row is line 2.
                    // Reporting the physical line keeps the number useful when
                    // the user opens the file to look.
                    'row_number' => $lineNo + 1,
                    'fields'     => $parsed['fields'],
                    'errors'     => $parsed['errors'],
                ];
            }
        } finally {
            fclose($handle);
        }

        return ['header' => $header, 'columns' => $columns, 'rows' => $rows];
    }

    private static function resolveOrigin(mixed $raw): string
    {
        $origin = strtoupper(trim((string) ($raw ?? '')));
        if ($origin === '') {
            return CoordinateDerivation::ORIGIN_PROJECTED;
        }
        if (!in_array($origin, [CoordinateDerivation::ORIGIN_PROJECTED, CoordinateDerivation::ORIGIN_GEOGRAPHIC], true)) {
            throw new ApiError('VALIDATION_FAILED', 'coordinate_origin must be PROJECTED or GEOGRAPHIC', 400, [
                'fields' => ['coordinate_origin' => 'must be PROJECTED or GEOGRAPHIC'],
            ]);
        }
        return $origin;
    }

    private static function resolveDefaultType(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $type = strtoupper(trim((string) $raw));
        if ($type === '') {
            return null;
        }
        if (!in_array($type, self::POINT_TYPES, true)) {
            throw new ApiError(
                'VALIDATION_FAILED',
                'default_point_type must be one of: ' . implode(', ', self::POINT_TYPES),
                400,
                ['fields' => ['default_point_type' => 'not a valid point type']]
            );
        }
        return $type;
    }

    /**
     * Build the target field => CSV column map.
     *
     * Rejects an unknown target field, a blank column name, and anything aimed
     * at `status`.
     *
     * @param array<array-key,mixed> $fieldMap
     * @return array<string,string>
     */
    private static function resolveColumns(array $fieldMap): array
    {
        $columns = self::DEFAULT_COLUMNS;

        foreach ($fieldMap as $field => $column) {
            $field = trim((string) $field);
            if (!in_array($field, self::FIELDS, true)) {
                throw new ApiError(
                    'VALIDATION_FAILED',
                    sprintf('Unknown field_map target "%s"; expected one of: %s', $field, implode(', ', self::FIELDS)),
                    400,
                    ['fields' => ['field_map' => sprintf('unknown target "%s"', $field)]]
                );
            }
            if (!is_string($column) || trim($column) === '') {
                throw new ApiError(
                    'VALIDATION_FAILED',
                    sprintf('field_map["%s"] must name a CSV column.', $field),
                    400,
                    ['fields' => ['field_map' => sprintf('missing column for "%s"', $field)]]
                );
            }
            $columns[$field] = trim($column);
        }

        return $columns;
    }

    /**
     * Validate and normalise one row in a single pass.
     *
     * Errors and fields are produced together on purpose: the caller must never
     * be able to persist a value the same row was told was invalid, and two
     * independent passes over the row could disagree.
     *
     * @param array<string,mixed>  $raw
     * @param array<string,string> $columns
     * @return array{fields:array<string,mixed>,errors:array<string,string>}
     */
    private static function parseRow(array $raw, array $columns, string $origin, ?string $defaultType): array
    {
        $errors = [];
        $fields = [];

        // ---- point name ----
        $name = self::cell($raw, $columns['point_name']);
        if ($name === null) {
            $errors['point_name'] = 'point_name is required';
        } elseif (mb_strlen($name) > self::MAX_LENGTHS['point_name']) {
            $errors['point_name'] = sprintf(
                'point_name must be at most %d characters',
                self::MAX_LENGTHS['point_name']
            );
        } else {
            $fields['point_name'] = $name;
        }

        // ---- length-limited text ----
        foreach (self::TEXT_FIELDS as $field) {
            $value = self::cell($raw, $columns[$field]);
            if ($value === null) {
                continue;
            }
            if (mb_strlen($value) > self::MAX_LENGTHS[$field]) {
                $errors[$field] = sprintf(
                    '%s must be at most %d characters',
                    $field,
                    self::MAX_LENGTHS[$field]
                );
                continue;
            }
            $fields[$field] = $value;
        }

        $description = self::cell($raw, $columns['description']);
        if ($description !== null) {
            $fields['description'] = $description;
        }

        // ---- point type ----
        $type = self::cell($raw, $columns['point_type']);
        if ($type === null) {
            if ($defaultType === null) {
                $errors['point_type'] = 'point_type is required (or set default_point_type)';
            } else {
                $fields['point_type'] = $defaultType;
            }
        } else {
            $upper = strtoupper($type);
            if (!in_array($upper, self::POINT_TYPES, true)) {
                $errors['point_type'] = sprintf(
                    'point_type "%s" is not a valid type; expected one of: %s',
                    $type,
                    implode(', ', self::POINT_TYPES)
                );
            } else {
                $fields['point_type'] = $upper;
            }
        }

        // ---- numeric fields ----
        foreach (self::NUMERIC_FIELDS as $field) {
            $value = self::cell($raw, $columns[$field]);
            if ($value === null) {
                continue;
            }
            if (!is_numeric($value)) {
                $errors[$field] = sprintf('%s must be a number, got "%s"', $field, $value);
                continue;
            }
            if ($field === 'accuracy_value_m' && (float) $value < 0) {
                $errors[$field] = 'accuracy_value_m must not be negative';
                continue;
            }
            $fields[$field] = (float) $value;
        }

        // ---- coordinates: the declared origin pair must be present and complete ----
        $pair = ($origin === CoordinateDerivation::ORIGIN_PROJECTED)
            ? ['easting', 'northing']
            : ['latitude', 'longitude'];
        foreach ($pair as $field) {
            if (!array_key_exists($field, $fields)) {
                $errors[$field] = sprintf('%s is required when coordinate_origin is %s', $field, $origin);
            }
        }

        // A second, complete pair means the file disagrees with the declared
        // origin. Surface it: silently preferring the declared pair would import
        // a coordinate the surveyor did not mean to send.
        $stray = ($origin === CoordinateDerivation::ORIGIN_PROJECTED)
            ? ['latitude', 'longitude']
            : ['easting', 'northing'];
        $present = [];
        foreach ($stray as $field) {
            if (array_key_exists($field, $fields)) {
                $present[] = $field;
            }
        }
        if (count($present) === 2) {
            $label = implode('/', $present);
            $errors[$label] = sprintf(
                'row also supplies %s while coordinate_origin is %s; remove the unused pair',
                $label,
                $origin
            );
        }

        // ---- PSGC ----
        $psgc = self::cell($raw, $columns['psgc_barangay']);
        if ($psgc !== null) {
            if (!preg_match('/^\d{9,12}$/', $psgc)) {
                $errors['psgc_barangay'] = 'psgc_barangay must be a 9-12 digit PSGC code';
            } else {
                $fields['psgc_barangay'] = $psgc;
            }
        }

        $fields['coordinate_origin'] = $origin;

        return ['fields' => $fields, 'errors' => $errors];
    }

    /**
     * Read a cell as a trimmed string, treating blank/whitespace as absent.
     *
     * @param array<string,mixed> $raw
     */
    private static function cell(array $raw, string $column): ?string
    {
        if (!array_key_exists($column, $raw)) {
            return null;
        }
        $value = $raw[$column];
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return ($value === '') ? null : $value;
    }
}
