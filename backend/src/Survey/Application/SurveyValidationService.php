<?php
declare(strict_types=1);

namespace App\Survey\Application;

use App\Core\Error\ApiError;
use App\Parcels\Domain\OverlapDetector;
use App\Survey\Domain\AreaCalculator;
use App\Survey\Domain\ClosureCalculator;
use App\Survey\Domain\ClosureResult;
use App\Survey\Domain\ComputeCrsGuard;
use App\Survey\Domain\CourseValidator;
use App\Survey\Domain\TraverseComputer;
use PDO;

class SurveyValidationService
{
    public function __construct(
        private PDO $pdo,
        private CourseValidator $courseValidator,
        private ClosureCalculator $closureCalculator,
        private AreaCalculator $areaCalculator,
        private ComputeCrsGuard $crsGuard,
        private OverlapDetector $overlapDetector,
        private TraverseComputer $traverseComputer
    ) {
    }

    /**
     * Validate a parcel, its current technical description, computation, and geometry.
     *
     * @param string $parcelId
     * @param array $options
     * @return array
     */
    public function validateParcel(string $parcelId, array $options = []): array
    {
        // 1. Load Parcel
        $pStmt = $this->pdo->prepare('SELECT * FROM app.parcels WHERE id = :id AND deleted_at IS NULL');
        $pStmt->execute([':id' => $parcelId]);
        $parcel = $pStmt->fetch(PDO::FETCH_ASSOC);
        if ($parcel === false) {
            throw new ApiError('NOT_FOUND', 'Parcel not found.', 404);
        }

        // 2. Load Current Technical Description
        $tdStmt = $this->pdo->prepare('SELECT * FROM app.technical_descriptions WHERE parcel_id = :pid AND is_current = true');
        $tdStmt->execute([':pid' => $parcelId]);
        $td = $tdStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // 3. Load Current Computation
        $comp = null;
        if (!empty($parcel['current_computation_id'])) {
            $compStmt = $this->pdo->prepare(
                'SELECT c.*, crs.code AS crs_code, crs.srid AS crs_srid, ST_AsGeoJSON(c.geom) AS geom_geojson '
                . 'FROM app.parcel_computations c '
                . 'LEFT JOIN ref.crs_registry crs ON crs.id = c.compute_crs_id '
                . 'WHERE c.id = :cid'
            );
            $compStmt->execute([':cid' => $parcel['current_computation_id']]);
            $comp = $compStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($comp === null && $td !== null) {
            // Find latest computation for this TD
            $compStmt = $this->pdo->prepare(
                'SELECT c.*, crs.code AS crs_code, crs.srid AS crs_srid, ST_AsGeoJSON(c.geom) AS geom_geojson '
                . 'FROM app.parcel_computations c '
                . 'LEFT JOIN ref.crs_registry crs ON crs.id = c.compute_crs_id '
                . 'WHERE c.technical_description_id = :tdid '
                . 'ORDER BY c.computed_at DESC LIMIT 1'
            );
            $compStmt->execute([':tdid' => $td['id']]);
            $comp = $compStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $checks = [];

        // Check 1: Technical Description Parsed and Confirmed (VR-TD-CONFIRMED)
        if ($td === null) {
            $checks[] = [
                'id'       => 'technical_description',
                'name'     => 'Technical Description',
                'rule'     => 'VR-TD-CONFIRMED',
                'status'   => 'FAIL',
                'severity' => 'blocking',
                'message'  => 'No active technical description attached to parcel.',
            ];
        } elseif ($td['parser_status'] !== 'CONFIRMED') {
            $checks[] = [
                'id'       => 'technical_description',
                'name'     => 'Technical Description Confirmation',
                'rule'     => 'VR-TD-CONFIRMED',
                'status'   => 'FAIL',
                'severity' => 'blocking',
                'message'  => sprintf('Technical description (Rev %d) is %s; must be confirmed before submission.', $td['revision'], $td['parser_status']),
            ];
        } else {
            $checks[] = [
                'id'       => 'technical_description',
                'name'     => 'Technical Description',
                'rule'     => 'VR-TD-CONFIRMED',
                'status'   => 'PASS',
                'severity' => 'info',
                'message'  => sprintf('Technical description (Rev %d) is confirmed.', $td['revision']),
            ];
        }

        // Load Tie Points & Tie Lines
        $tiePoint = null;
        $tieLines = [];
        $courses = [];

        if ($td !== null) {
            $tpStmt = $this->pdo->prepare(
                'SELECT tp.*, cp.point_name, cp.status AS cp_status, ST_Y(cp.geom) AS cp_lat, ST_X(cp.geom) AS cp_lon '
                . 'FROM app.tie_points tp '
                . 'LEFT JOIN app.survey_control_points cp ON cp.id = tp.control_point_id '
                . 'WHERE tp.technical_description_id = :tdid ORDER BY tp.sequence ASC LIMIT 1'
            );
            $tpStmt->execute([':tdid' => $td['id']]);
            $tiePoint = $tpStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $cStmt = $this->pdo->prepare('SELECT * FROM app.technical_description_courses WHERE technical_description_id = :tdid ORDER BY seq ASC');
            $cStmt->execute([':tdid' => $td['id']]);
            $courses = $cStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Check 2: Tie Point Found (VR-TIE-FOUND)
        if ($tiePoint === null) {
            $checks[] = [
                'id'       => 'tie_point_found',
                'name'     => 'Tie Point Monument',
                'rule'     => 'VR-TIE-FOUND',
                'status'   => 'FAIL',
                'severity' => 'blocking',
                'message'  => 'No tie point monument attached to technical description.',
            ];
        } else {
            $checks[] = [
                'id'       => 'tie_point_found',
                'name'     => 'Tie Point Monument',
                'rule'     => 'VR-TIE-FOUND',
                'status'   => 'PASS',
                'severity' => 'info',
                'message'  => sprintf('Tie point monument attached: %s.', $tiePoint['point_name'] ?? $tiePoint['adhoc_name'] ?? 'Tie Point'),
            ];
        }

        // Check 3: Tie Point Verified (VR-19)
        if ($tiePoint !== null) {
            $isVerified = ($tiePoint['cp_status'] ?? $tiePoint['as_used_status'] ?? '') === 'VERIFIED';
            if ($isVerified) {
                $checks[] = [
                    'id'       => 'tie_point_verified',
                    'name'     => 'Tie Point Verification',
                    'rule'     => 'VR-19',
                    'status'   => 'PASS',
                    'severity' => 'info',
                    'message'  => sprintf('Tie point %s is verified in the national geodetic network.', $tiePoint['point_name'] ?? $tiePoint['adhoc_name']),
                ];
            } else {
                $checks[] = [
                    'id'       => 'tie_point_verified',
                    'name'     => 'Tie Point Verification',
                    'rule'     => 'VR-19',
                    'status'   => 'WARN',
                    'severity' => 'warning',
                    'message'  => sprintf('Tie point %s is unverified; persisted as a review warning for approval.', $tiePoint['point_name'] ?? $tiePoint['adhoc_name'] ?? 'monument'),
                ];
            }
        }

        // Check 4: Minimum Vertex Count (VR-10)
        $courseCount = count($courses);
        if ($courseCount < 3) {
            $checks[] = [
                'id'       => 'course_count',
                'name'     => 'Minimum Course Count',
                'rule'     => 'VR-10',
                'status'   => 'FAIL',
                'severity' => 'blocking',
                'message'  => sprintf('Parcel boundary has %d courses; at least 3 courses are required to form a polygon.', $courseCount),
            ];
        } else {
            $checks[] = [
                'id'       => 'course_count',
                'name'     => 'Minimum Course Count',
                'rule'     => 'VR-10',
                'status'   => 'PASS',
                'severity' => 'info',
                'message'  => sprintf('Boundary contains %d courses forming a closed ring.', $courseCount),
            ];
        }

        // Check 5: Bearing Reference and Course Bearings (VR-01, VR-02, VR-03, VR-07, VR-08, VR-09)
        $bRef = $td['bearing_reference'] ?? 'GRID';
        if ($bRef !== 'GRID') {
            $checks[] = [
                'id'       => 'bearing_reference',
                'name'     => 'Bearing Reference',
                'rule'     => 'VR-BEARING-REF',
                'status'   => 'FAIL',
                'severity' => 'blocking',
                'message'  => sprintf('Bearing reference is %s; must be GRID for standard cadastral boundary computation.', $bRef),
            ];
        } else {
            $checks[] = [
                'id'       => 'bearing_reference',
                'name'     => 'Bearing Reference',
                'rule'     => 'VR-BEARING-REF',
                'status'   => 'PASS',
                'severity' => 'info',
                'message'  => 'Bearing reference is GRID.',
            ];
        }

        if ($courseCount >= 3) {
            $valResult = $this->courseValidator->validate($courses);
            if (!$valResult['valid']) {
                $firstErr = $valResult['errors'][0];
                $checks[] = [
                    'id'       => 'course_syntax',
                    'name'     => 'Course Syntax & Geometry Rules',
                    'rule'     => $firstErr['rule'],
                    'status'   => 'FAIL',
                    'severity' => 'blocking',
                    'message'  => sprintf('Course syntax failure: %s (Course %d)', $firstErr['message'], $firstErr['course_seq']),
                    'details'  => $valResult['errors'],
                ];
            } else {
                $hasCollinear = false;
                $hasReversed = false;
                foreach ($valResult['warnings'] as $w) {
                    if ($w['rule'] === 'VR-08') $hasCollinear = true;
                    if ($w['rule'] === 'VR-09') $hasReversed = true;
                }

                if ($hasCollinear || $hasReversed) {
                    $checks[] = [
                        'id'       => 'course_syntax',
                        'name'     => 'Course Geometry Integrity',
                        'rule'     => $hasReversed ? 'VR-09' : 'VR-08',
                        'status'   => 'WARN',
                        'severity' => 'warning',
                        'message'  => $hasReversed ? 'Reversed backtrack course detected in boundary sequence.' : 'Consecutive collinear courses detected.',
                        'details'  => $valResult['warnings'],
                    ];
                } else {
                    $checks[] = [
                        'id'       => 'course_syntax',
                        'name'     => 'Course Syntax & Geometry Rules',
                        'rule'     => 'VR-01',
                        'status'   => 'PASS',
                        'severity' => 'info',
                        'message'  => 'All boundary course bearings and distances conform to survey standards.',
                    ];
                }
            }
        }

        // Check 6: Compute CRS and Area of Use (VR-20)
        if ($comp !== null) {
            $computeCrsId = $comp['compute_crs_id'] ?? null;
            $computeCrsCode = $comp['crs_code'] ?? 'EPSG:3123';
            $crsStmt = $this->pdo->prepare('SELECT * FROM ref.crs_registry WHERE id = :id OR code = :code LIMIT 1');
            $crsStmt->execute([':id' => $computeCrsId ?: 0, ':code' => $computeCrsCode]);
            $crsInfo = $crsStmt->fetch(PDO::FETCH_ASSOC);

            if ($crsInfo === false) {
                $checks[] = [
                    'id'       => 'crs_area_of_use',
                    'name'     => 'Compute Coordinate System',
                    'rule'     => 'VR-20',
                    'status'   => 'FAIL',
                    'severity' => 'blocking',
                    'message'  => sprintf('CRS %s is not registered in the system.', $computeCrsCode),
                ];
            } elseif (!$crsInfo['is_projected']) {
                $checks[] = [
                    'id'       => 'crs_area_of_use',
                    'name'     => 'Compute Coordinate System',
                    'rule'     => 'VR-20',
                    'status'   => 'FAIL',
                    'severity' => 'blocking',
                    'message'  => sprintf('CRS %s is not a projected coordinate system.', $crsInfo['code']),
                ];
            } else {
                if ($tiePoint !== null && !empty($tiePoint['cp_lat']) && !empty($tiePoint['cp_lon'])) {
                    try {
                        $this->crsGuard->assertWithinAreaOfUse($crsInfo, (float) $tiePoint['cp_lat'], (float) $tiePoint['cp_lon']);
                        $checks[] = [
                            'id'       => 'crs_area_of_use',
                            'name'     => 'Compute Coordinate System',
                            'rule'     => 'VR-20',
                            'status'   => 'PASS',
                            'severity' => 'info',
                            'message'  => sprintf('Compute CRS %s (%s) is within declared area of use.', $crsInfo['code'], $crsInfo['name']),
                        ];
                    } catch (\InvalidArgumentException $e) {
                        $checks[] = [
                            'id'       => 'crs_area_of_use',
                            'name'     => 'Compute Coordinate System',
                            'rule'     => 'VR-20',
                            'status'   => 'FAIL',
                            'severity' => 'blocking',
                            'message'  => $e->getMessage(),
                        ];
                    }
                } else {
                    $checks[] = [
                        'id'       => 'crs_area_of_use',
                        'name'     => 'Compute Coordinate System',
                        'rule'     => 'VR-20',
                        'status'   => 'PASS',
                        'severity' => 'info',
                        'message'  => sprintf('Compute CRS %s (%s) is valid for projected coordinate geometry.', $crsInfo['code'], $crsInfo['name']),
                    ];
                }
            }
        } else {
            $checks[] = [
                'id'       => 'computation_exists',
                'name'     => 'Traverse Computation',
                'rule'     => 'VR-COMP-EXISTS',
                'status'   => 'FAIL',
                'severity' => 'blocking',
                'message'  => 'No computation has been executed for this parcel.',
            ];
        }

        // Check 7: Traverse Closure (VR-11, VR-12)
        if ($comp !== null) {
            $linErr = (float) $comp['linear_error_m'];
            $precDenom = $comp['relative_precision_denominator'] !== null ? (float) $comp['relative_precision_denominator'] : INF;
            $closureStatus = $comp['closure_status'] ?? 'INDETERMINATE';

            if ($closureStatus === ClosureResult::STATUS_WITHIN_TOLERANCE) {
                $checks[] = [
                    'id'       => 'traverse_closure',
                    'name'     => 'Traverse Closure Tolerance',
                    'rule'     => 'VR-11',
                    'status'   => 'PASS',
                    'severity' => 'info',
                    'message'  => sprintf(
                        'Traverse closure is within tolerance (LE: %.4fm, Precision: %s).',
                        $linErr,
                        $precDenom === INF ? '1:INF' : '1:' . (int) round($precDenom)
                    ),
                ];
            } else {
                $checks[] = [
                    'id'       => 'traverse_closure',
                    'name'     => 'Traverse Closure Tolerance',
                    'rule'     => 'VR-11',
                    'status'   => 'FAIL',
                    'severity' => 'blocking',
                    'message'  => sprintf(
                        'Traverse linear error %.4fm exceeds maximum tolerance (precision: %s).',
                        $linErr,
                        $precDenom === INF ? '1:INF' : '1:' . (int) round($precDenom)
                    ),
                ];
            }
        }

        // Check 8: Geometry Validity & Simplicity (VR-13, VR-14)
        $geomWkt = null;
        if (!empty($parcel['geom'])) {
            $geomWkt = $this->pdo->query("SELECT ST_AsText('{$parcel['geom']}')")->fetchColumn() ?: null;
        } elseif (!empty($comp['geom_geojson'])) {
            $geomWkt = $comp['geom_geojson'];
        }

        if ($comp !== null && !empty($comp['geom_geojson'])) {
            $gisStmt = $this->pdo->prepare('SELECT ST_IsValid(ST_GeomFromGeoJSON(:g)) AS is_valid, ST_IsSimple(ST_GeomFromGeoJSON(:g)) AS is_simple');
            $gisStmt->execute([':g' => $comp['geom_geojson']]);
            $gisCheck = $gisStmt->fetch(PDO::FETCH_ASSOC);

            if (!$gisCheck['is_valid'] || !$gisCheck['is_simple']) {
                $checks[] = [
                    'id'       => 'geometry_validity',
                    'name'     => 'Geometry Topology & Simplicity',
                    'rule'     => !$gisCheck['is_simple'] ? 'VR-13' : 'VR-14',
                    'status'   => 'FAIL',
                    'severity' => 'blocking',
                    'message'  => !$gisCheck['is_simple'] ? 'Boundary polygon intersects itself (VR-13).' : 'Polygon geometry is topologically invalid (VR-14).',
                ];
            } else {
                $checks[] = [
                    'id'       => 'geometry_validity',
                    'name'     => 'Geometry Topology & Simplicity',
                    'rule'     => 'VR-14',
                    'status'   => 'PASS',
                    'severity' => 'info',
                    'message'  => 'Computed boundary polygon is topologically valid and non-self-intersecting.',
                ];
            }
        }

        // Check 9: Area Computed & Plausible (VR-15)
        if ($comp !== null) {
            $cArea = (float) $comp['computed_area_sqm'];
            if ($cArea <= 0.0 || $cArea > 100000000.0) { // > 10,000 ha
                $checks[] = [
                    'id'       => 'area_plausibility',
                    'name'     => 'Area Plausibility',
                    'rule'     => 'VR-15',
                    'status'   => 'FAIL',
                    'severity' => 'blocking',
                    'message'  => sprintf('Computed area %.2f sqm is outside plausible cadastral bounds (1 sqm to 10,000 ha).', $cArea),
                ];
            } else {
                $checks[] = [
                    'id'       => 'area_plausibility',
                    'name'     => 'Area Plausibility',
                    'rule'     => 'VR-15',
                    'status'   => 'PASS',
                    'severity' => 'info',
                    'message'  => sprintf('Computed planar area is %.2f sqm.', $cArea),
                ];
            }
        }

        // Check 10: Area vs Source Area & PostGIS Area (VR-16, VR-17)
        if ($comp !== null) {
            $cArea = (float) $comp['computed_area_sqm'];
            $pArea = $comp['postgis_area_sqm'] !== null ? (float) $comp['postgis_area_sqm'] : null;
            $sArea = $comp['source_area_sqm'] !== null ? (float) $comp['source_area_sqm'] : ($parcel['source_area_sqm'] !== null ? (float) $parcel['source_area_sqm'] : null);

            $areaComparison = $this->areaCalculator->compareAreas($cArea, $sArea, $pArea);

            if ($sArea !== null && $sArea > 0) {
                $diffPct = abs((float) $areaComparison['diff_source_pct']);
                if ($diffPct > 2.0) {
                    $checks[] = [
                        'id'       => 'area_reconciliation',
                        'name'     => 'Area Comparison vs Source',
                        'rule'     => 'VR-16',
                        'status'   => 'WARN',
                        'severity' => 'warning',
                        'message'  => sprintf('Computed area differs from source area by %.4f%% (%.2f sqm). Discrepancy > 2%% flagged for review.', $diffPct, abs((float) $areaComparison['diff_source_sqm'])),
                    ];
                } elseif ($diffPct > 0.5) {
                    $checks[] = [
                        'id'       => 'area_reconciliation',
                        'name'     => 'Area Comparison vs Source',
                        'rule'     => 'VR-16',
                        'status'   => 'WARN',
                        'severity' => 'warning',
                        'message'  => sprintf('Computed area differs from source area by %.4f%% (%.2f sqm).', $diffPct, abs((float) $areaComparison['diff_source_sqm'])),
                    ];
                } else {
                    $checks[] = [
                        'id'       => 'area_reconciliation',
                        'name'     => 'Area Comparison vs Source',
                        'rule'     => 'VR-16',
                        'status'   => 'PASS',
                        'severity' => 'info',
                        'message'  => sprintf('Computed area matches source area within 0.5%% (diff: %.2f sqm).', abs((float) $areaComparison['diff_source_sqm'])),
                    ];
                }
            }
        }

        // Check 11: Overlap Detection against Active Parcels (VR-18)
        $geomForOverlap = null;
        if (!empty($parcel['geom'])) {
            $gStmt = $this->pdo->prepare('SELECT ST_AsGeoJSON(geom) FROM app.parcels WHERE id = :id');
            $gStmt->execute([':id' => $parcelId]);
            $geomForOverlap = $gStmt->fetchColumn() ?: null;
        } elseif ($comp !== null && !empty($comp['geom_geojson'])) {
            $geomForOverlap = $comp['geom_geojson'];
        }

        $overlapResult = $this->overlapDetector->detectOverlaps($parcelId, $geomForOverlap);

        if ($overlapResult['has_significant_overlap']) {
            $count = count($overlapResult['overlapping_parcels']);
            $checks[] = [
                'id'       => 'parcel_overlap',
                'name'     => 'Cadastral Boundary Overlap',
                'rule'     => 'VR-18',
                'status'   => 'WARN',
                'severity' => 'warning',
                'message'  => sprintf(
                    'Overlap detected with %d active parcel(s) (total overlap: %.2f sqm). Warnings will be carried forward to reviewers.',
                    $count,
                    $overlapResult['total_overlap_area_sqm']
                ),
                'details'  => $overlapResult['overlapping_parcels'],
            ];
        } else {
            $checks[] = [
                'id'       => 'parcel_overlap',
                'name'     => 'Cadastral Boundary Overlap',
                'rule'     => 'VR-18',
                'status'   => 'PASS',
                'severity' => 'info',
                'message'  => 'No boundary overlap detected with active cadastral parcels.',
            ];
        }

        // Consolidate status
        $blockingFailures = [];
        $warnings = [];

        foreach ($checks as $c) {
            if ($c['status'] === 'FAIL') {
                $blockingFailures[] = $c;
            } elseif ($c['status'] === 'WARN') {
                $warnings[] = $c;
            }
        }

        $passed = empty($blockingFailures);
        $canSubmit = $passed;

        $resultPayload = [
            'parcel_id'          => $parcelId,
            'computation_id'     => $comp ? (int) $comp['id'] : null,
            'passed'             => $passed,
            'can_submit'         => $canSubmit,
            'blocking_count'     => count($blockingFailures),
            'warning_count'      => count($warnings),
            'blocking_failures'  => $blockingFailures,
            'warnings'           => $warnings,
            'checks'             => $checks,
            'area_comparison'    => [
                'computed_sqm'   => $comp ? (float) $comp['computed_area_sqm'] : null,
                'postgis_sqm'    => $comp && $comp['postgis_area_sqm'] !== null ? (float) $comp['postgis_area_sqm'] : null,
                'source_sqm'     => $parcel['source_area_sqm'] !== null ? (float) $parcel['source_area_sqm'] : null,
                'note'           => AreaCalculator::VALIDATION_AID_NOTE,
            ],
            'overlap_summary'    => $overlapResult,
            'validated_at'       => date('c'),
        ];

        // Persist validation result to current computation if exists
        if ($comp !== null) {
            $upd = $this->pdo->prepare('UPDATE app.parcel_computations SET validation_result = :res::jsonb WHERE id = :cid');
            $upd->execute([
                ':res' => json_encode($resultPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':cid' => $comp['id'],
            ]);
        }

        return $resultPayload;
    }
}
