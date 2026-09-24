<?php
declare(strict_types=1);

namespace App\Survey\Application;

use App\Core\Crs\CoordinateTransformationService;
use App\Core\Error\ApiError;
use App\Survey\Domain\Adjustment\CompassRuleAdjustment;
use App\Survey\Domain\Adjustment\TransitRuleAdjustment;
use App\Survey\Domain\AreaCalculator;
use App\Survey\Domain\ClosureCalculator;
use App\Survey\Domain\ClosureResult;
use App\Survey\Domain\ComputeCrsGuard;
use App\Survey\Domain\TraverseComputer;
use PDO;

/**
 * TASK-090, TASK-091, TASK-094 — Survey Computation Application Service.
 *
 * Coordinates traverse calculation, closure metrics, shoelace and PostGIS area cross-checks,
 * compute-CRS guards, snapshot persistence, replay determinism, and adjustments.
 */
class SurveyComputationService
{
    public const ENGINE_VERSION = 'survey-1.0.0';

    private PDO $pdo;
    private TraverseComputer $traverseComputer;
    private ClosureCalculator $closureCalculator;
    private AreaCalculator $areaCalculator;
    private ComputeCrsGuard $crsGuard;
    private CoordinateTransformationService $transformationService;

    public function __construct(
        PDO $pdo,
        ?TraverseComputer $traverseComputer = null,
        ?ClosureCalculator $closureCalculator = null,
        ?AreaCalculator $areaCalculator = null,
        ?ComputeCrsGuard $crsGuard = null,
        ?CoordinateTransformationService $transformationService = null
    ) {
        $this->pdo = $pdo;
        $this->traverseComputer = $traverseComputer ?? new TraverseComputer();
        $this->closureCalculator = $closureCalculator ?? new ClosureCalculator();
        $this->areaCalculator = $areaCalculator ?? new AreaCalculator();
        $this->crsGuard = $crsGuard ?? new ComputeCrsGuard();
        $this->transformationService = $transformationService ?? new CoordinateTransformationService($pdo);
    }

    /**
     * Compute and persist parcel traverse from confirmed technical description.
     */
    public function calculateForParcel(
        string $parcelId,
        int $techDescId,
        int|string $computeCrsIdentifier,
        array $tolerances = [],
        ?int $userId = null
    ): array {
        // 1. Verify parcel existence
        $pStmt = $this->pdo->prepare('SELECT id, lot_number, source_area_sqm FROM app.parcels WHERE id = :id AND deleted_at IS NULL');
        $pStmt->execute([':id' => $parcelId]);
        $parcel = $pStmt->fetch(PDO::FETCH_ASSOC);
        if ($parcel === false) {
            throw new ApiError('NOT_FOUND', 'Parcel not found', 404);
        }

        // 2. Resolve compute CRS and verify it is projected
        $computeCrs = $this->transformationService->resolveCrs($computeCrsIdentifier);
        if (!$computeCrs['is_projected']) {
            throw new ApiError('CRS_UNSUPPORTED', 'Compute CRS must be a projected coordinate reference system (e.g. PRS92 Zone I-V).', 422);
        }

        // 3. Load Technical Description
        $tdStmt = $this->pdo->prepare('SELECT * FROM app.technical_descriptions WHERE id = :id AND parcel_id = :pid');
        $tdStmt->execute([':id' => $techDescId, ':pid' => $parcelId]);
        $td = $tdStmt->fetch(PDO::FETCH_ASSOC);
        if ($td === false) {
            throw new ApiError('NOT_FOUND', 'Technical description revision not found for this parcel.', 404);
        }

        // Enforce grid bearing reference guard (TASK-091)
        $bearingRef = $td['bearing_reference'] ?? 'GRID';
        try {
            $this->crsGuard->assertGridBearingReference($bearingRef);
        } catch (\InvalidArgumentException $e) {
            throw new ApiError('VALIDATION_FAILED', $e->getMessage(), 422);
        }

        // 4. Resolve Tie Point coordinates in Compute CRS
        $tpStmt = $this->pdo->prepare(
            'SELECT tp.*, cp.point_name, cp.easting AS cp_easting, cp.northing AS cp_northing, '
            . 'cp.native_crs_id AS cp_crs_id, cp.status AS cp_status, '
            . 'ST_Y(cp.geom) AS cp_lat, ST_X(cp.geom) AS cp_lon '
            . 'FROM app.tie_points tp '
            . 'LEFT JOIN app.survey_control_points cp ON cp.id = tp.control_point_id '
            . 'WHERE tp.technical_description_id = :td_id '
            . 'ORDER BY tp.sequence ASC'
        );
        $tpStmt->execute([':td_id' => $techDescId]);
        $tiePointRows = $tpStmt->fetchAll(PDO::FETCH_ASSOC);

        $primaryTp = $tiePointRows[0] ?? null;
        $tiePointWarnings = [];
        $tiePointData = [];
        $tpEasting = null;
        $tpNorthing = null;

        if ($primaryTp !== null) {
            $hasCp = !empty($primaryTp['control_point_id']) && $primaryTp['cp_easting'] !== null && $primaryTp['cp_northing'] !== null;
            $hasAsUsed = ($primaryTp['as_used_easting'] !== null && $primaryTp['as_used_northing'] !== null && ((float) $primaryTp['as_used_easting'] != 0 || (float) $primaryTp['as_used_northing'] != 0));

            if ($hasCp) {
                $cpCrsId = (int) $primaryTp['cp_crs_id'];
                $cpLat = $primaryTp['cp_lat'] !== null ? (float) $primaryTp['cp_lat'] : null;
                $cpLon = $primaryTp['cp_lon'] !== null ? (float) $primaryTp['cp_lon'] : null;

                // Area-of-use guard (VR-20)
                if ($cpLat !== null && $cpLon !== null) {
                    try {
                        $this->crsGuard->assertWithinAreaOfUse($computeCrs, $cpLat, $cpLon);
                    } catch (\InvalidArgumentException $e) {
                        throw new ApiError('VALIDATION_FAILED', $e->getMessage(), 422);
                    }
                }

                // Check if tie point is unverified (VR-19)
                if (($primaryTp['cp_status'] ?? '') !== 'VERIFIED') {
                    $tiePointWarnings[] = [
                        'rule'     => 'VR-19',
                        'severity' => 'warning',
                        'message'  => sprintf('Tie point %s is unverified.', $primaryTp['point_name'] ?? 'BLLM'),
                    ];
                }

                // Transform tie point to compute CRS if different
                if ($cpCrsId === (int) $computeCrs['id']) {
                    $tpEasting = (float) $primaryTp['cp_easting'];
                    $tpNorthing = (float) $primaryTp['cp_northing'];
                } else {
                    $tx = $this->transformationService->transform(
                        [['x' => (float) $primaryTp['cp_easting'], 'y' => (float) $primaryTp['cp_northing']]],
                        $cpCrsId,
                        (int) $computeCrs['id'],
                        ['persist_log' => false]
                    );
                    $tpEasting = $tx['transformed'][0]['x'];
                    $tpNorthing = $tx['transformed'][0]['y'];
                }

                $tiePointData = [
                    'name'             => $primaryTp['point_name'] ?? 'Tie Point',
                    'as_used_easting'  => round($tpEasting, 4),
                    'as_used_northing' => round($tpNorthing, 4),
                    'as_used_status'   => $primaryTp['cp_status'] ?? 'UNVERIFIED',
                ];
            } elseif ($hasAsUsed) {
                $tpEasting = (float) $primaryTp['as_used_easting'];
                $tpNorthing = (float) $primaryTp['as_used_northing'];
                $tiePointData = [
                    'name'             => $primaryTp['adhoc_name'] ?? 'Tie Point',
                    'as_used_easting'  => round($tpEasting, 4),
                    'as_used_northing' => round($tpNorthing, 4),
                    'as_used_status'   => $primaryTp['as_used_status'] ?? 'UNVERIFIED',
                ];
                if (($primaryTp['as_used_status'] ?? '') !== 'VERIFIED') {
                    $tiePointWarnings[] = [
                        'rule'     => 'VR-19',
                        'severity' => 'warning',
                        'message'  => sprintf('Tie point %s is unverified.', $primaryTp['adhoc_name'] ?? 'Tie Point'),
                    ];
                }
            }
        }

        if ($tpEasting === null || $tpNorthing === null) {
            // Local assumed origin if tie point not linked
            $tpEasting = 500000.0;
            $tpNorthing = 1000000.0;
            $tiePointData = [
                'name'             => 'Assumed Datum (500000, 1000000)',
                'as_used_easting'  => $tpEasting,
                'as_used_northing' => $tpNorthing,
                'as_used_status'   => 'UNVERIFIED',
            ];
            $tiePointWarnings[] = [
                'rule'     => 'VR-19',
                'severity' => 'warning',
                'message'  => 'No verified tie point monument attached; assumed local origin used.',
            ];
        }

        // Load tie lines
        $tieLines = [];
        $tlStmt = $this->pdo->prepare('SELECT * FROM app.tie_lines WHERE technical_description_id = :td_id ORDER BY seq ASC');
        $tlStmt->execute([':td_id' => $techDescId]);
        $tieLineRows = $tlStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($tieLineRows as $tl) {
            $dist = $tl['distance_m'] ?? $tl['original_distance'] ?? null;
            if ($dist !== null && (float) $dist > 0) {
                $tieLines[] = [
                    'bearing'  => (string) ($tl['normalized_bearing'] ?? $tl['original_bearing'] ?? ''),
                    'distance' => (float) $dist,
                ];
            }
        }

        // 5. Load Courses from app.technical_description_courses
        $cStmt = $this->pdo->prepare('SELECT * FROM app.technical_description_courses WHERE technical_description_id = :tdid ORDER BY seq ASC');
        $cStmt->execute([':tdid' => $techDescId]);
        $courses = $cStmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($courses) < 3) {
            throw new ApiError('VALIDATION_FAILED', 'VR-10: At least 3 courses are required to form a parcel boundary.', 422);
        }

        $inputCourses = [];
        foreach ($courses as $c) {
            $bearingStr = $c['normalized_bearing'] ?? $c['original_bearing'] ?? '';
            $distM = (float) ($c['distance_m'] ?? $c['original_distance']);
            $inputCourses[] = [
                'seq'      => (int) $c['seq'],
                'from'     => (string) ($c['from_point_label'] ?? $c['seq']),
                'to'       => (string) ($c['to_point_label'] ?? ''),
                'bearing'  => $bearingStr,
                'distance' => $distM,
            ];
        }

        // 6. Compute plane traverse
        $traverseResult = $this->traverseComputer->compute(
            ['easting' => $tpEasting, 'northing' => $tpNorthing],
            $tieLines,
            $inputCourses
        );

        // 7. Calculate Closure metrics
        $closureResult = $this->closureCalculator->calculate(
            $traverseResult['closure']['delta_e'],
            $traverseResult['closure']['delta_n'],
            $traverseResult['closure']['perimeter_m'],
            $tolerances
        );

        // 8. Calculate Area via Shoelace
        $shoelaceArea = $this->areaCalculator->computeShoelaceArea($traverseResult['vertices']);

        // 9. Build Polygon in Compute CRS and validate in PostGIS
        $pointsRing = [];
        foreach ($traverseResult['vertices'] as $v) {
            $pointsRing[] = sprintf('%.4f %.4f', $v['easting'], $v['northing']);
        }
        // Close the ring back to the first vertex
        $pointsRing[] = sprintf('%.4f %.4f', $traverseResult['vertices'][0]['easting'], $traverseResult['vertices'][0]['northing']);
        $wktRing = 'POLYGON((' . implode(', ', $pointsRing) . '))';

        $gisStmt = $this->pdo->prepare(
            'SELECT '
            . 'ST_Area(ST_SetSRID(ST_GeomFromText(:wkt), :srid)) AS postgis_area, '
            . 'ST_IsValid(ST_SetSRID(ST_GeomFromText(:wkt), :srid)) AS is_valid, '
            . 'ST_IsSimple(ST_Boundary(ST_SetSRID(ST_GeomFromText(:wkt), :srid))) AS is_simple, '
            . 'ST_AsGeoJSON(ST_Transform(ST_SetSRID(ST_GeomFromText(:wkt), :srid), 4326)) AS geom_geojson'
        );
        $gisStmt->execute([
            ':wkt'  => $wktRing,
            ':srid' => $computeCrs['srid'],
        ]);
        $gisCheck = $gisStmt->fetch(PDO::FETCH_ASSOC);

        if (!$gisCheck['is_valid']) {
            throw new ApiError('GEOMETRY_INVALID', 'VR-14: The computed traverse polygon is topologically invalid in PostGIS.', 422);
        }
        if (!$gisCheck['is_simple']) {
            throw new ApiError('GEOMETRY_INVALID', 'VR-13: The computed traverse boundary intersects itself.', 422);
        }

        $postgisArea = (float) $gisCheck['postgis_area'];
        $sourceArea = (!empty($td['claimed_area_sqm'])) ? (float) $td['claimed_area_sqm'] : ($parcel['source_area_sqm'] !== null ? (float) $parcel['source_area_sqm'] : null);

        // Cross-check area
        $areaComparison = $this->areaCalculator->compareAreas($shoelaceArea, $sourceArea, $postgisArea);

        // Transform vertices to 4326 geographic coordinates
        $vertPoints = [];
        foreach ($traverseResult['vertices'] as $v) {
            $vertPoints[] = ['x' => $v['easting'], 'y' => $v['northing']];
        }
        $vertTx = $this->transformationService->transform(
            $vertPoints,
            $computeCrs['id'],
            'EPSG:4326',
            ['persist_log' => false]
        );

        $enrichedVertices = [];
        for ($i = 0; $i < count($traverseResult['vertices']); $i++) {
            $v = $traverseResult['vertices'][$i];
            $t = $vertTx['transformed'][$i];
            $enrichedVertices[] = [
                'seq'         => $v['seq'],
                'label'       => $v['point_label'],
                'easting'     => $v['easting'],
                'northing'    => $v['northing'],
                'latitude'    => $t['y'],
                'longitude'   => $t['x'],
                'delta_e'     => $v['delta_e'],
                'delta_n'     => $v['delta_n'],
            ];
        }

        // Combine warnings
        $allWarnings = array_merge(
            $tiePointWarnings,
            $closureResult->getWarnings(),
            $areaComparison['warnings']
        );

        // 10. Build Input Snapshot
        $snapshot = [
            'engine_version'           => self::ENGINE_VERSION,
            'technical_description_id' => $techDescId,
            'bearing_reference'        => $bearingRef,
            'compute_crs'              => $computeCrs,
            'tolerances'               => $tolerances,
            'tie_points'               => [$tiePointData],
            'tie_lines'                => $tieLines,
            'courses'                  => $inputCourses,
            'source_area_sqm'          => $sourceArea,
        ];

        // 11. Persist computation to database
        $insComp = $this->pdo->prepare(
            'INSERT INTO app.parcel_computations ('
            . 'parcel_id, technical_description_id, compute_crs_id, method, '
            . 'start_easting, start_northing, close_easting, close_northing, '
            . 'closure_de, closure_dn, linear_error_m, error_azimuth_dd, perimeter_m, '
            . 'relative_precision_denominator, computed_area_sqm, postgis_area_sqm, source_area_sqm, '
            . 'area_diff_sqm, area_diff_pct, closure_status, validation_result, '
            . 'input_snapshot, tolerances, engine_version, is_current, computed_by, geom'
            . ') VALUES ('
            . ':pid, :tdid, :crsid, :method, '
            . ':se, :sn, :ce, :cn, '
            . ':cde, :cdn, :lerr, :az, :perim, '
            . ':prec, :carea, :parea, :sarea, '
            . ':adiff, :apct, :status, :valres::jsonb, '
            . ':snap::jsonb, :tols::jsonb, :ver, false, :uid, '
            . 'ST_SetSRID(ST_GeomFromGeoJSON(:geojson), 4326)'
            . ') RETURNING id'
        );

        $insComp->execute([
            ':pid'     => $parcelId,
            ':tdid'    => $techDescId,
            ':crsid'   => $computeCrs['id'],
            ':method'  => 'TRAVERSE_PLANE',
            ':se'      => $traverseResult['pob']['easting'],
            ':sn'      => $traverseResult['pob']['northing'],
            ':ce'      => $traverseResult['close']['easting'],
            ':cn'      => $traverseResult['close']['northing'],
            ':cde'     => $closureResult->getDeltaE(),
            ':cdn'     => $closureResult->getDeltaN(),
            ':lerr'    => $closureResult->getLinearErrorM(),
            ':az'      => $closureResult->getErrorAzimuthDd(),
            ':perim'   => $closureResult->getPerimeterM(),
            ':prec'    => $closureResult->getRelativePrecisionDenominator(),
            ':carea'   => $shoelaceArea,
            ':parea'   => $postgisArea,
            ':sarea'   => $sourceArea,
            ':adiff'   => $areaComparison['diff_source_sqm'],
            ':apct'    => $areaComparison['diff_source_pct'],
            ':status'  => $closureResult->getStatus(),
            ':valres'  => json_encode(['warnings' => $allWarnings]),
            ':snap'    => json_encode($snapshot),
            ':tols'    => json_encode($tolerances),
            ':ver'     => self::ENGINE_VERSION,
            ':uid'     => $userId,
            ':geojson' => $gisCheck['geom_geojson'],
        ]);

        $computationId = (int) $insComp->fetchColumn();

        // 12. Persist Vertices to app.parcel_vertices
        $insVert = $this->pdo->prepare(
            'INSERT INTO app.parcel_vertices ('
            . 'computation_id, seq, point_label, easting, northing, compute_crs_id, latitude, longitude, is_tie_vertex'
            . ') VALUES (:cid, :seq, :lbl, :e, :n, :crsid, :lat, :lon, false)'
        );

        foreach ($enrichedVertices as $ev) {
            $insVert->execute([
                ':cid'   => $computationId,
                ':seq'   => $ev['seq'],
                ':lbl'   => $ev['label'],
                ':e'     => $ev['easting'],
                ':n'     => $ev['northing'],
                ':crsid' => $computeCrs['id'],
                ':lat'   => $ev['latitude'],
                ':lon'   => $ev['longitude'],
            ]);
        }

        // Bump calculation_version on technical description
        $updTd = $this->pdo->prepare('UPDATE app.technical_descriptions SET calculation_version = calculation_version + 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $updTd->execute([':id' => $techDescId]);

        return [
            'computation_id'      => $computationId,
            'engine_version'      => self::ENGINE_VERSION,
            'compute_crs'         => $computeCrs['code'],
            'tie_points'          => [$tiePointData],
            'vertices'            => $enrichedVertices,
            'closure'             => $closureResult->toArray(),
            'area'                => [
                'computed_sqm'   => $shoelaceArea,
                'postgis_sqm'    => $postgisArea,
                'source_sqm'     => $sourceArea,
                'difference_sqm' => $areaComparison['diff_source_sqm'],
                'difference_pct' => $areaComparison['diff_source_pct'],
                'note'           => AreaCalculator::VALIDATION_AID_NOTE,
            ],
            'warnings'            => $allWarnings,
            'geometry_preview'    => json_decode($gisCheck['geom_geojson'], true),
            'persisted_to_parcel' => false,
        ];
    }

    /**
     * Replay a computation from its input_snapshot and assert determinism.
     */
    public function replay(int $computationId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM app.parcel_computations WHERE id = :id');
        $stmt->execute([':id' => $computationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ApiError('NOT_FOUND', 'Computation not found', 404);
        }

        $snapshot = json_decode((string) $row['input_snapshot'], true);
        if (!is_array($snapshot)) {
            throw new ApiError('INTERNAL', 'Computation input snapshot is corrupt.', 500);
        }

        $tp = $snapshot['tie_points'][0] ?? ['as_used_easting' => 500000.0, 'as_used_northing' => 1000000.0];
        $tiePoint = ['easting' => $tp['as_used_easting'], 'northing' => $tp['as_used_northing']];
        $tieLines = $snapshot['tie_lines'] ?? [];
        $courses  = $snapshot['courses'] ?? [];

        // Recompute
        $result = $this->traverseComputer->compute($tiePoint, $tieLines, $courses);

        // Load stored vertices
        $vStmt = $this->pdo->prepare('SELECT seq, easting, northing FROM app.parcel_vertices WHERE computation_id = :cid ORDER BY seq ASC');
        $vStmt->execute([':cid' => $computationId]);
        $storedVertices = $vStmt->fetchAll(PDO::FETCH_ASSOC);

        $matches = true;
        for ($i = 0; $i < count($result['vertices']); $i++) {
            $rv = $result['vertices'][$i];
            $sv = $storedVertices[$i] ?? null;
            if ($sv === null || abs((float) $rv['easting'] - (float) $sv['easting']) > 0.0001
                || abs((float) $rv['northing'] - (float) $sv['northing']) > 0.0001) {
                $matches = false;
                break;
            }
        }

        return [
            'computation_id'   => $computationId,
            'replayed'         => true,
            'matches_original' => $matches,
            'original_closure' => [
                'linear_error_m' => (float) $row['linear_error_m'],
                'perimeter_m'    => (float) $row['perimeter_m'],
            ],
            'replayed_closure' => $result['closure'],
        ];
    }

    /**
     * Create an adjusted computation (Compass or Transit) linked to original base.
     */
    public function adjust(int $baseComputationId, string $method, array $params = [], ?int $userId = null): array
    {
        $method = strtoupper(trim($method));
        if (!in_array($method, ['COMPASS', 'TRANSIT'], true)) {
            throw new ApiError('VALIDATION_FAILED', "Unsupported adjustment method '{$method}'. Allowed: COMPASS, TRANSIT.", 400);
        }

        // Load base computation
        $stmt = $this->pdo->prepare('SELECT * FROM app.parcel_computations WHERE id = :id');
        $stmt->execute([':id' => $baseComputationId]);
        $base = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($base === false) {
            throw new ApiError('NOT_FOUND', 'Base computation not found', 404);
        }

        $snapshot = json_decode((string) $base['input_snapshot'], true);
        $courses = $snapshot['courses'] ?? [];
        $computeCrsId = (int) $base['compute_crs_id'];

        // Load unadjusted vertices
        $vStmt = $this->pdo->prepare('SELECT * FROM app.parcel_vertices WHERE computation_id = :cid ORDER BY seq ASC');
        $vStmt->execute([':cid' => $baseComputationId]);
        $vertices = $vStmt->fetchAll(PDO::FETCH_ASSOC);

        $unadjVertices = [];
        for ($i = 0; $i < count($vertices); $i++) {
            $next = ($i + 1) % count($vertices);
            $dE = (float) $vertices[$next]['easting'] - (float) $vertices[$i]['easting'];
            $dN = (float) $vertices[$next]['northing'] - (float) $vertices[$i]['northing'];
            $unadjVertices[] = [
                'seq'         => (int) $vertices[$i]['seq'],
                'point_label' => (string) $vertices[$i]['point_label'],
                'easting'     => (float) $vertices[$i]['easting'],
                'northing'    => (float) $vertices[$i]['northing'],
                'delta_e'     => $dE,
                'delta_n'     => $dN,
            ];
        }

        $pob = [
            'easting'  => (float) $base['start_easting'],
            'northing' => (float) $base['start_northing'],
        ];

        $adjuster = $method === 'COMPASS' ? new CompassRuleAdjustment() : new TransitRuleAdjustment();
        $adjResult = $adjuster->adjust(
            $pob,
            $unadjVertices,
            $courses,
            (float) $base['closure_de'],
            (float) $base['closure_dn'],
            (float) $base['perimeter_m']
        );

        $adjVertices = $adjResult['adjusted_vertices'];
        $adjShoelace = $this->areaCalculator->computeShoelaceArea($adjVertices);

        // Build adjusted polygon
        $pointsRing = [];
        foreach ($adjVertices as $v) {
            $pointsRing[] = sprintf('%.4f %.4f', $v['easting'], $v['northing']);
        }
        $pointsRing[] = sprintf('%.4f %.4f', $adjVertices[0]['easting'], $adjVertices[0]['northing']);
        $wktRing = 'POLYGON((' . implode(', ', $pointsRing) . '))';

        $computeCrs = $this->transformationService->resolveCrs($computeCrsId);

        $gisStmt = $this->pdo->prepare(
            'SELECT '
            . 'ST_Area(ST_SetSRID(ST_GeomFromText(:wkt), :srid)) AS postgis_area, '
            . 'ST_AsGeoJSON(ST_Transform(ST_SetSRID(ST_GeomFromText(:wkt), :srid), 4326)) AS geom_geojson'
        );
        $gisStmt->execute([':wkt' => $wktRing, ':srid' => $computeCrs['srid']]);
        $gisCheck = $gisStmt->fetch(PDO::FETCH_ASSOC);

        // Transform vertices to 4326
        $vertPoints = [];
        foreach ($adjVertices as $v) {
            $vertPoints[] = ['x' => $v['easting'], 'y' => $v['northing']];
        }
        $vertTx = $this->transformationService->transform(
            $vertPoints,
            $computeCrsId,
            'EPSG:4326',
            ['persist_log' => false]
        );

        $enrichedAdjVertices = [];
        for ($i = 0; $i < count($adjVertices); $i++) {
            $v = $adjVertices[$i];
            $t = $vertTx['transformed'][$i];
            $enrichedAdjVertices[] = [
                'seq'       => $v['seq'],
                'label'     => $v['point_label'],
                'easting'   => $v['easting'],
                'northing'  => $v['northing'],
                'latitude'  => $t['y'],
                'longitude' => $t['x'],
                'delta_e'   => $v['delta_e'],
                'delta_n'   => $v['delta_n'],
            ];
        }

        $adjParams = array_merge($params, [
            'operator'              => $userId,
            'adjusted_at'           => date('c'),
            'original_linear_error' => (float) $base['linear_error_m'],
        ]);

        // Insert new computation record linked to base
        $ins = $this->pdo->prepare(
            'INSERT INTO app.parcel_computations ('
            . 'parcel_id, technical_description_id, compute_crs_id, method, adjustment_method, adjustment_params, base_computation_id, '
            . 'start_easting, start_northing, close_easting, close_northing, closure_de, closure_dn, linear_error_m, error_azimuth_dd, perimeter_m, '
            . 'relative_precision_denominator, computed_area_sqm, postgis_area_sqm, source_area_sqm, closure_status, input_snapshot, tolerances, engine_version, is_current, computed_by, geom'
            . ') VALUES ('
            . ':pid, :tdid, :crsid, :method, :adjm, :adjparams::jsonb, :baseid, '
            . ':se, :sn, :ce, :cn, :cde, :cdn, :lerr, :az, :perim, '
            . ':prec, :carea, :parea, :sarea, :status, :snap::jsonb, :tols::jsonb, :ver, false, :uid, '
            . 'ST_SetSRID(ST_GeomFromGeoJSON(:geojson), 4326)'
            . ') RETURNING id'
        );

        $ins->execute([
            ':pid'       => $base['parcel_id'],
            ':tdid'      => $base['technical_description_id'],
            ':crsid'     => $computeCrsId,
            ':method'    => 'TRAVERSE_ADJUSTED',
            ':adjm'      => $method,
            ':adjparams' => json_encode($adjParams),
            ':baseid'    => $baseComputationId,
            ':se'        => $pob['easting'],
            ':sn'        => $pob['northing'],
            ':ce'        => $pob['easting'],
            ':cn'        => $pob['northing'],
            ':cde'       => 0.0,
            ':cdn'       => 0.0,
            ':lerr'      => 0.0,
            ':az'        => null,
            ':perim'     => (float) $base['perimeter_m'],
            ':prec'      => null,
            ':carea'     => $adjShoelace,
            ':parea'     => (float) $gisCheck['postgis_area'],
            ':sarea'     => $base['source_area_sqm'],
            ':status'    => ClosureResult::STATUS_WITHIN_TOLERANCE,
            ':snap'      => $base['input_snapshot'],
            ':tols'      => $base['tolerances'],
            ':ver'       => self::ENGINE_VERSION,
            ':uid'       => $userId,
            ':geojson'   => $gisCheck['geom_geojson'],
        ]);

        $newCompId = (int) $ins->fetchColumn();

        // Persist adjusted vertices
        $insV = $this->pdo->prepare(
            'INSERT INTO app.parcel_vertices (computation_id, seq, point_label, easting, northing, compute_crs_id, latitude, longitude, is_tie_vertex) '
            . 'VALUES (:cid, :seq, :lbl, :e, :n, :crsid, :lat, :lon, false)'
        );
        foreach ($enrichedAdjVertices as $ev) {
            $insV->execute([
                ':cid'   => $newCompId,
                ':seq'   => $ev['seq'],
                ':lbl'   => $ev['label'],
                ':e'     => $ev['easting'],
                ':n'     => $ev['northing'],
                ':crsid' => $computeCrsId,
                ':lat'   => $ev['latitude'],
                ':lon'   => $ev['longitude'],
            ]);
        }

        return [
            'computation_id'      => $newCompId,
            'base_computation_id' => $baseComputationId,
            'adjustment_method'   => $method,
            'closure'             => [
                'delta_e'            => 0.0,
                'delta_n'            => 0.0,
                'linear_error_m'     => 0.0,
                'relative_precision' => '1:INF',
                'status'             => ClosureResult::STATUS_WITHIN_TOLERANCE,
            ],
            'area' => [
                'computed_sqm' => $adjShoelace,
                'postgis_sqm'  => (float) $gisCheck['postgis_area'],
            ],
            'vertices'            => $enrichedAdjVertices,
            'geometry_preview'    => json_decode($gisCheck['geom_geojson'], true),
            'persisted_to_parcel' => false,
        ];
    }
}
