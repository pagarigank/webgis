<?php
declare(strict_types=1);

namespace App\Survey\Application;

use App\Audit\AuditWriter;
use App\Core\Error\ApiError;
use App\Survey\Domain\ControlPointCsvParser;
use App\Survey\Domain\CoordinateDerivation;
use PDO;

/**
 * TASK-126 - control point bulk import from CSV.
 *
 * Flow: parse -> resolve the declared CRS -> derive each row's other coordinate
 * pair -> detect duplicates -> report. Nothing is written unless the caller
 * asks to commit.
 *
 * The two acceptance rules this class exists to guarantee:
 *
 *  1. Every imported point is UNVERIFIED. The status is a literal in the INSERT
 *     and is not readable from the file, so a bulk import can never be a way to
 *     mark a point verified without a verifier.
 *
 *  2. Duplicates are FLAGGED, never merged. A row that duplicates an existing
 *     point by name, or that lands within `duplicate_radius_m` of one, is
 *     reported with the point it collides with and is NOT inserted. Silently
 *     skipping it would lose a line the surveyor believed they had loaded;
 *     silently overwriting would destroy a surveyed record. Neither is
 *     recoverable, so the import refuses to choose and shows the collision.
 *
 * Validation is deliberately identical to the single-point CRUD path: rows go
 * through the same ControlPointCoordinateResolver, so an import cannot create a
 * record that POST /control-points would reject.
 */
final class ControlPointImportService
{
    /**
     * Default proximity radius, in metres, for the "same monument entered
     * twice" check. Tie-point monuments are physically distinct objects, so a
     * metre is comfortably below the spacing of real monuments while still
     * catching a re-entered coordinate.
     */
    public const DEFAULT_DUPLICATE_RADIUS_M = 1.0;

    private const MAX_RADIUS_M = 1000.0;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditWriter $audit,
        private readonly ControlPointCoordinateResolver $resolver,
    ) {
    }

    /**
     * Validate a CSV and, when $commit is true, insert the rows that are not
     * duplicates and not invalid.
     *
     * @param array{
     *     native_crs?:mixed,
     *     coordinate_origin?:mixed,
     *     field_map?:array<array-key,mixed>,
     *     default_point_type?:mixed,
     *     duplicate_radius_m?:mixed,
     *     commit?:bool
     * } $options
     *
     * @return array<string,mixed> the import report
     */
    public function import(string $csv, array $options, int $uid): array
    {
        // The CRS is declared by the caller, not sniffed from the file. Without
        // it every easting/northing in the file would be uninterpretable, so it
        // is a hard requirement (mirrors TASK-122's CRS_REQUIRED guard).
        $rawCrs = $options['native_crs'] ?? null;
        if ($rawCrs === null || $rawCrs === '' || (is_string($rawCrs) && trim($rawCrs) === '')) {
            throw new ApiError('CRS_REQUIRED', 'native_crs is required: declare the CRS the CSV coordinates are expressed in.', 400, [
                'fields' => ['native_crs' => 'required'],
            ]);
        }

        $crs = $this->resolver->resolveCrs($rawCrs);
        $this->resolver->assertProjectedCrs($crs);

        $origin = strtoupper(trim((string) ($options['coordinate_origin'] ?? CoordinateDerivation::ORIGIN_PROJECTED)));
        if ($origin === '') {
            $origin = CoordinateDerivation::ORIGIN_PROJECTED;
        }

        $radius = $this->resolveRadius($options['duplicate_radius_m'] ?? null);

        $parsed = ControlPointCsvParser::parse($csv, [
            'field_map'           => is_array($options['field_map'] ?? null) ? $options['field_map'] : [],
            'coordinate_origin'   => $origin,
            'default_point_type'  => $options['default_point_type'] ?? null,
        ]);

        $rows = $parsed['rows'];

        // ---- per-row: derive coordinates, so a row can be judged on its real position ----
        $report = [];
        $candidateIndexes = [];
        foreach ($rows as $i => $row) {
            $entry = [
                'row_number' => $row['row_number'],
                'point_name' => $row['fields']['point_name'] ?? null,
                'point_type' => $row['fields']['point_type'] ?? null,
                'status'     => $row['errors'] === [] ? 'VALID' : 'INVALID',
                'errors'     => $row['errors'],
                'duplicates' => [],
                'fields'     => $row['fields'],
            ];

            if ($row['errors'] === []) {
                try {
                    $entry['coordinates'] = $this->derive($crs, $row['fields'], $origin);
                    $candidateIndexes[] = $i;
                } catch (ApiError $e) {
                    $entry['status'] = 'INVALID';
                    $entry['errors'] = $e->getDetails()['fields'] ?? ['coordinates' => $e->getMessage()];
                }
            }

            $report[$i] = $entry;
        }

        $this->flagPsgc($report, $candidateIndexes);
        $this->flagDuplicateNames($report, $candidateIndexes, $crs);
        $this->flagDuplicateProximity($report, $candidateIndexes, $crs, $radius);

        // ---- commit ----
        $imported = [];
        if (($options['commit'] ?? false) === true) {
            $imported = $this->insertValid($report, $candidateIndexes, $crs, $uid);
        }

        $summary = [
            'total_rows'         => count($rows),
            'valid'              => $this->countStatus($report, 'VALID'),
            'invalid'            => $this->countStatus($report, 'INVALID'),
            'duplicate_name'     => $this->countStatus($report, 'DUPLICATE_NAME'),
            'duplicate_proximity'=> $this->countStatus($report, 'DUPLICATE_PROXIMITY'),
            'imported'           => count($imported),
        ];

        return [
            'committed'          => ($options['commit'] ?? false) === true,
            'native_crs'         => $crs['code'],
            'native_crs_name'    => $crs['name'],
            'coordinate_origin'  => $origin,
            'duplicate_radius_m' => $radius,
            'columns'            => $parsed['columns'],
            'summary'            => $summary,
            'rows'               => array_values(array_map(
                static function (array $entry): array {
                    if (isset($entry['id'])) {
                        $entry['imported_id'] = $entry['id'];
                    }
                    unset($entry['id'], $entry['insert']);
                    return $entry;
                },
                $report
            )),
            'imported_ids'       => array_map(static fn (array $r): int => (int) $r['id'], $imported),
        ];
    }

    /**
     * Derive a row's stored coordinate pair, enforcing the CRS area of use.
     *
     * @param array<string,mixed> $crs
     * @param array<string,mixed> $fields
     * @return array{easting:float,northing:float,latitude:float,longitude:float}
     */
    private function derive(array $crs, array $fields, string $origin): array
    {
        $plan = CoordinateDerivation::plan($fields);
        if (!$plan['valid']) {
            throw new ApiError('VALIDATION_FAILED', 'Invalid coordinate input', 400, ['fields' => $plan['errors']]);
        }

        try {
            return $this->resolver->deriveCoordinates($crs, $plan);
        } catch (ApiError $e) {
            throw $this->resolver->areaOfUseError($crs, $origin);
        }
    }

    /**
     * Reject PSGC codes that are not in ref.psgc_areas.
     *
     * Done up front because the column is a foreign key: letting the INSERT hit
     * an unknown code would abort the whole transaction and lose the good rows,
     * instead of flagging one line.
     *
     * @param array<int,array<string,mixed>> $report
     * @param array<int,int>                 $indexes
     */
    private function flagPsgc(array &$report, array $indexes): void
    {
        $codes = [];
        foreach ($indexes as $i) {
            $code = $report[$i]['fields']['psgc_barangay'] ?? null;
            if ($code !== null) {
                $codes[$code] = true;
            }
        }
        if ($codes === []) {
            return;
        }

        $list = array_keys($codes);
        $ph = implode(', ', array_fill(0, count($list), '?'));
        $stmt = $this->pdo->prepare("SELECT code FROM ref.psgc_areas WHERE code IN ($ph)");
        $stmt->execute($list);
        $known = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);

        foreach ($indexes as $i) {
            $code = $report[$i]['fields']['psgc_barangay'] ?? null;
            if ($code !== null && !isset($known[$code])) {
                $report[$i]['status'] = 'INVALID';
                $report[$i]['errors']['psgc_barangay'] = sprintf('psgc_barangay "%s" is not a known PSGC code', $code);
            }
        }
    }

    /**
     * Flag rows whose (point_name, CRS) already exists, or that repeat a name
     * earlier in the same file.
     *
     * Only LIVE rows count: a soft-deleted point must not block re-importing
     * its replacement, which is the normal "I deleted the bad one" workflow.
     *
     * @param array<int,array<string,mixed>> $report
     * @param array<int,int>                 $indexes
     * @param array<string,mixed>            $crs
     */
    private function flagDuplicateNames(array &$report, array $indexes, array $crs): void
    {
        $names = [];
        foreach ($indexes as $i) {
            $name = $report[$i]['fields']['point_name'] ?? null;
            if ($name !== null) {
                $names[$name] = true;
            }
        }
        if ($names === []) {
            return;
        }

        $list = array_keys($names);
        $ph = implode(', ', array_fill(0, count($list), '?'));
        $sql = "SELECT point_name, id FROM app.survey_control_points "
             . "WHERE native_crs_id = ? AND deleted_at IS NULL AND point_name IN ($ph)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([(int) $crs['id']], $list));

        $existing = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $existing[$r['point_name']] = (int) $r['id'];
        }

        // A name repeated inside one file is a duplicate too - the second
        // occurrence has nothing to merge into and would fail the unique index.
        $seenInFile = [];

        foreach ($indexes as $i) {
            $name = $report[$i]['fields']['point_name'] ?? null;
            if ($name === null) {
                continue;
            }

            if (isset($existing[$name])) {
                $report[$i]['status'] = 'DUPLICATE_NAME';
                $report[$i]['duplicates'][] = [
                    'kind'      => 'existing',
                    'control_point_id' => $existing[$name],
                    'point_name'=> $name,
                    'detail'    => sprintf('a control point named "%s" already exists for %s', $name, $crs['code']),
                ];
                continue;
            }

            if (isset($seenInFile[$name])) {
                $report[$i]['status'] = 'DUPLICATE_NAME';
                $report[$i]['duplicates'][] = [
                    'kind'       => 'in_file',
                    'row_number' => $seenInFile[$name],
                    'point_name' => $name,
                    'detail'     => sprintf('row %d of this file already uses the name "%s"', $seenInFile[$name], $name),
                ];
                continue;
            }

            $seenInFile[$name] = $report[$i]['row_number'];
        }
    }

    /**
     * Flag rows that land within the radius of an existing live point, or of an
     * earlier accepted row in the same file.
     *
     * The database check is a single batched ST_DWithin so a 5000-row file
     * costs one indexed query rather than 5000. The in-file check is computed
     * in PHP with the haversine formula, which agrees with PostGIS geography
     * distance to far better than the metre-scale radius being tested.
     *
     * @param array<int,array<string,mixed>> $report
     * @param array<int,int>                 $indexes
     * @param array<string,mixed>            $crs
     */
    private function flagDuplicateProximity(array &$report, array $indexes, array $crs, float $radius): void
    {
        if ($radius <= 0.0) {
            return;
        }

        $lons = [];
        $lats = [];
        foreach ($indexes as $i) {
            $coords = $report[$i]['coordinates'] ?? null;
            if ($coords === null) {
                continue;
            }
            $lons[] = (float) $coords['longitude'];
            $lats[] = (float) $coords['latitude'];
        }
        if ($lons === []) {
            return;
        }

        // Batched nearest-existing-point lookup. ORDINALITY maps each hit back
        // to the row that produced it.
        $sql = 'SELECT v.ord, cp.id, cp.point_name, c.code, '
             . 'ST_Distance(cp.geom::geography, ST_SetSRID(ST_MakePoint(v.lon, v.lat), 4326)::geography) AS distance_m '
             . 'FROM unnest(?::float8[], ?::float8[]) WITH ORDINALITY AS v(lon, lat, ord) '
             . 'JOIN app.survey_control_points cp '
             . '  ON cp.deleted_at IS NULL '
             . ' AND cp.native_crs_id = ? '
             . ' AND ST_DWithin(cp.geom::geography, ST_SetSRID(ST_MakePoint(v.lon, v.lat), 4326)::geography, ?) '
             . 'LEFT JOIN ref.crs_registry c ON c.id = cp.native_crs_id '
             . 'ORDER BY v.ord, distance_m';

        $stmt = $this->pdo->prepare($sql);
        // The arrays are rendered as PostgreSQL array literals rather than
        // bound as PHP arrays: PDO's array serialisation is driver-dependent,
        // and a literal is unambiguous for `::float8[]`. The values are floats
        // this class produced, so there is nothing to escape.
        $stmt->execute([
            self::arrayLiteral($lons),
            self::arrayLiteral($lats),
            (int) $crs['id'],
            $radius,
        ]);

        // Keep only the closest existing point per row.
        $nearest = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $hit) {
            $ord = (int) $hit['ord'];
            if (!isset($nearest[$ord])) {
                $nearest[$ord] = $hit;
            }
        }

        $positions = array_values(array_filter($indexes, static fn (int $i): bool => isset($report[$i]['coordinates'])));

        foreach ($positions as $position => $i) {
            $ord = $position + 1;

            if (isset($nearest[$ord])) {
                $hit = $nearest[$ord];
                // A row already flagged as a name duplicate keeps that status:
                // it is the more actionable finding, and the summary counts are
                // mutually exclusive. The collision is still reported.
                if ($report[$i]['status'] === 'VALID') {
                    $report[$i]['status'] = 'DUPLICATE_PROXIMITY';
                }
                $report[$i]['duplicates'][] = [
                    'kind'             => 'existing',
                    'control_point_id' => (int) $hit['id'],
                    'point_name'       => $hit['point_name'],
                    'native_crs'       => $hit['code'],
                    'distance_m'       => round((float) $hit['distance_m'], 3),
                    'detail'           => sprintf(
                        'within %.3f m of existing control point "%s" (id %d)',
                        (float) $hit['distance_m'],
                        (string) $hit['point_name'],
                        (int) $hit['id']
                    ),
                ];
                continue;
            }

            // In-file proximity: compare against rows accepted earlier.
            $lat = (float) $report[$i]['coordinates']['latitude'];
            $lon = (float) $report[$i]['coordinates']['longitude'];
            foreach ($positions as $earlierPosition => $j) {
                if ($earlierPosition >= $position) {
                    break;
                }
                // A row that was itself a duplicate cannot be "the point that
                // already exists", so it does not block a later row.
                if ($report[$j]['status'] !== 'VALID') {
                    continue;
                }
                $d = $this->haversine(
                    $lat,
                    $lon,
                    (float) $report[$j]['coordinates']['latitude'],
                    (float) $report[$j]['coordinates']['longitude']
                );
                if ($d <= $radius) {
                    if ($report[$i]['status'] === 'VALID') {
                        $report[$i]['status'] = 'DUPLICATE_PROXIMITY';
                    }
                    $report[$i]['duplicates'][] = [
                        'kind'       => 'in_file',
                        'row_number' => $report[$j]['row_number'],
                        'point_name' => $report[$j]['fields']['point_name'] ?? null,
                        'distance_m' => round($d, 3),
                        'detail'     => sprintf(
                            'within %.3f m of row %d in this file',
                            $d,
                            (int) $report[$j]['row_number']
                        ),
                    ];
                    break;
                }
            }
        }
    }

    /**
     * Insert the rows that are VALID. Rows that are duplicates or invalid are
     * left alone - the report is the deliverable for those.
     *
     * @param array<int,array<string,mixed>> $report
     * @param array<int,int>                 $indexes
     * @param array<string,mixed>            $crs
     * @return array<int,array{id:int}>
     */
    private function insertValid(array &$report, array $indexes, array $crs, int $uid): array
    {
        $sql = 'INSERT INTO app.survey_control_points '
             . '(point_name, point_type, monument_type, easting, northing, elevation, native_crs_id, '
             . ' latitude, longitude, coordinate_origin, datum, zone, source, survey_reference, '
             . ' accuracy_class, accuracy_value_m, description, status, psgc_barangay, created_by, version, geom) '
             . 'VALUES (:name, :type, :monument, :easting, :northing, :elevation, :crs_id, '
             . ' :latitude, :longitude, :origin, :datum, :zone, :source, :survey_ref, '
             . ' :accuracy_cls, :accuracy_val, :description, \'UNVERIFIED\', :psgc, :uid, 1, '
             . ' ST_SetSRID(ST_MakePoint(:geo_lon, :geo_lat), 4326)) '
             . 'RETURNING id';

        $stmt = $this->pdo->prepare($sql);
        $imported = [];

        foreach ($indexes as $i) {
            if ($report[$i]['status'] !== 'VALID') {
                continue;
            }

            $fields = $report[$i]['fields'];
            $coords = $report[$i]['coordinates'];

            try {
                $stmt->execute([
                    ':name'         => $fields['point_name'],
                    ':type'         => $fields['point_type'],
                    ':monument'     => $fields['monument_type'] ?? null,
                    ':easting'      => $coords['easting'],
                    ':northing'     => $coords['northing'],
                    ':elevation'    => $fields['elevation'] ?? null,
                    ':crs_id'       => (int) $crs['id'],
                    ':latitude'     => $coords['latitude'],
                    ':longitude'    => $coords['longitude'],
                    ':origin'       => $fields['coordinate_origin'],
                    ':datum'        => $crs['datum'],
                    ':zone'         => $crs['zone'],
                    ':source'       => $fields['source'] ?? null,
                    ':survey_ref'   => $fields['survey_reference'] ?? null,
                    ':accuracy_cls' => $fields['accuracy_class'] ?? null,
                    ':accuracy_val' => $fields['accuracy_value_m'] ?? null,
                    ':description'  => $fields['description'] ?? null,
                    ':psgc'         => $fields['psgc_barangay'] ?? null,
                    ':uid'          => $uid,
                    ':geo_lon'      => $coords['longitude'],
                    ':geo_lat'      => $coords['latitude'],
                ]);
            } catch (\PDOException $e) {
                // A concurrent writer can claim the name between the duplicate
                // check and the insert. Report it as a duplicate rather than
                // failing the whole import.
                if ($e->getCode() === '23505') {
                    $report[$i]['status'] = 'DUPLICATE_NAME';
                    $report[$i]['duplicates'][] = [
                        'kind'   => 'existing',
                        'detail' => 'the name was claimed by another writer during the import',
                    ];
                    continue;
                }
                if ($e->getCode() === '23503') {
                    $report[$i]['status'] = 'INVALID';
                    $report[$i]['errors']['psgc_barangay'] = 'unknown PSGC code or CRS';
                    continue;
                }
                throw $e;
            }

            $id = (int) $stmt->fetchColumn();
            $report[$i]['id'] = $id;
            $imported[] = ['id' => $id];

            $this->audit->writeFromSession(
                'INSERT',
                'app.survey_control_points',
                (string) $id,
                null,
                $this->auditPayload($fields, $coords, $crs),
                null,
                'Control point imported from CSV (TASK-126)'
            );
        }

        return $imported;
    }

    /**
     * @param array<string,mixed> $fields
     * @param array<string,mixed> $coords
     * @param array<string,mixed> $crs
     * @return array<string,mixed>
     */
    private function auditPayload(array $fields, array $coords, array $crs): array
    {
        return [
            'point_name'        => $fields['point_name'],
            'point_type'        => $fields['point_type'],
            'monument_type'     => $fields['monument_type'] ?? null,
            'easting'           => $coords['easting'],
            'northing'          => $coords['northing'],
            'latitude'          => $coords['latitude'],
            'longitude'         => $coords['longitude'],
            'elevation'         => $fields['elevation'] ?? null,
            'native_crs'        => $crs['code'],
            'coordinate_origin' => $fields['coordinate_origin'],
            'datum'             => $crs['datum'],
            'zone'              => $crs['zone'],
            'source'            => $fields['source'] ?? null,
            'survey_reference'  => $fields['survey_reference'] ?? null,
            'accuracy_class'    => $fields['accuracy_class'] ?? null,
            'accuracy_value_m'  => $fields['accuracy_value_m'] ?? null,
            'description'       => $fields['description'] ?? null,
            'psgc_barangay'     => $fields['psgc_barangay'] ?? null,
            'status'            => 'UNVERIFIED',
        ];
    }

    private function resolveRadius(mixed $raw): float
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT_DUPLICATE_RADIUS_M;
        }
        if (!is_numeric($raw)) {
            throw new ApiError('VALIDATION_FAILED', 'duplicate_radius_m must be a number', 400, [
                'fields' => ['duplicate_radius_m' => 'must be a number'],
            ]);
        }
        $radius = (float) $raw;
        if ($radius < 0 || $radius > self::MAX_RADIUS_M) {
            throw new ApiError(
                'VALIDATION_FAILED',
                sprintf('duplicate_radius_m must be between 0 and %.0f', self::MAX_RADIUS_M),
                400,
                ['fields' => ['duplicate_radius_m' => 'out of range']]
            );
        }
        return $radius;
    }

    /**
     * @param array<int,array<string,mixed>> $report
     */
    private function countStatus(array $report, string $status): int
    {
        $count = 0;
        foreach ($report as $entry) {
            if ($entry['status'] === $status) {
                $count++;
            }
        }
        return $count;
    }

    /** Render a float list as a PostgreSQL `float8[]` literal. */
    private static function arrayLiteral(array $values): string
    {
        return '{' . implode(',', array_map(
            static fn (float $v): string => rtrim(rtrim(sprintf('%.10F', $v), '0'), '.') ?: '0',
            $values
        )) . '}';
    }

    /** Great-circle distance in metres between two WGS 84 positions. */
    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371008.8; // IUGG mean Earth radius
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}
