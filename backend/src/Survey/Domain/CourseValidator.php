<?php
declare(strict_types=1);

namespace App\Survey\Domain;

use InvalidArgumentException;

/**
 * Pure domain course syntax and rule validator (TASK-081).
 *
 * Enforces rules VR-01 through VR-09:
 * - VR-01: Quadrant bearing degrees 0–90; minutes 0–59; seconds 0–59.999 (error)
 * - VR-02: Azimuth 0 <= Az < 360 (error)
 * - VR-03: Quadrant must be one of NE, SE, SW, NW (error)
 * - VR-04: Distance > 0; > 0.01 m (error)
 * - VR-05: Distance > 5,000 m in a single course (warning)
 * - VR-06: Distance unit must be a registered unit (error)
 * - VR-07: Bearing of exactly 0° or 90° with ambiguous quadrant must be cardinal (error)
 * - VR-08: Two consecutive courses with identical bearing (warning)
 * - VR-09: Reversed course: azimuth differs by 180° ± 0.001°, same distance (warning)
 */
final class CourseValidator
{
    /**
     * Validate an array of course specifications.
     *
     * @param array<int,array<string,mixed>> $courses
     * @return array{
     *     valid: bool,
     *     errors: array<int,array{rule: string, field: string, course_seq: int, message: string}>,
     *     warnings: array<int,array{rule: string, field: string, course_seq: int, message: string}>,
     *     validated_courses: array<int,array<string,mixed>>
     * }
     */
    public function validate(array $courses): array
    {
        $errors = [];
        $warnings = [];
        $validatedCourses = [];

        $prevAzimuth = null;
        $prevDistanceMeters = null;

        foreach ($courses as $index => $c) {
            $seq = (int) ($c['seq'] ?? ($index + 1));
            $courseErrors = [];

            // 1. Resolve Bearing
            $bearingObj = null;
            $azimuthDd = null;

            $rawBearing = $c['bearing'] ?? $c['bearing_raw'] ?? $c['original_bearing'] ?? $c['normalized_bearing'] ?? null;
            if (is_string($rawBearing) && trim($rawBearing) !== '') {
                try {
                    $bearingObj = Bearing::parse($rawBearing);
                    $azimuthDd = $bearingObj->toAzimuth()->toDecimalDegrees();
                } catch (InvalidArgumentException $e) {
                    $msg = $e->getMessage();
                    $rule = 'VR-01';
                    if (str_contains($msg, 'VR-07')) {
                        $rule = 'VR-07';
                    } elseif (str_contains($msg, 'VR-03')) {
                        $rule = 'VR-03';
                    } elseif (str_contains($msg, 'VR-02')) {
                        $rule = 'VR-02';
                    }
                    $courseErrors[] = [
                        'rule' => $rule,
                        'field' => 'bearing',
                        'course_seq' => $seq,
                        'message' => $msg,
                    ];
                }
            } elseif (isset($c['quadrant']) || isset($c['bearing_quadrant'])) {
                $quadrant = strtoupper(trim((string) ($c['quadrant'] ?? $c['bearing_quadrant'])));
                $deg = isset($c['deg']) ? (int) $c['deg'] : null;
                $min = isset($c['min']) ? (int) $c['min'] : 0;
                $sec = isset($c['sec']) ? (float) $c['sec'] : 0.0;

                if (!in_array($quadrant, ['NE', 'SE', 'SW', 'NW'], true)) {
                    $courseErrors[] = [
                        'rule' => 'VR-03',
                        'field' => 'quadrant',
                        'course_seq' => $seq,
                        'message' => "VR-03: Quadrant must be one of NE, SE, SW, NW (given: {$quadrant})",
                    ];
                }

                if ($deg === null || $deg < 0 || $deg > 90) {
                    $courseErrors[] = [
                        'rule' => 'VR-01',
                        'field' => 'deg',
                        'course_seq' => $seq,
                        'message' => sprintf('VR-01: Degrees must be 0–90 (given: %s)', $deg ?? 'null'),
                    ];
                }
                if ($min < 0 || $min > 59) {
                    $courseErrors[] = [
                        'rule' => 'VR-01',
                        'field' => 'min',
                        'course_seq' => $seq,
                        'message' => sprintf('VR-01: Minutes must be 0–59 (given: %d)', $min),
                    ];
                }
                if ($sec < 0.0 || $sec >= 60.0) {
                    $courseErrors[] = [
                        'rule' => 'VR-01',
                        'field' => 'sec',
                        'course_seq' => $seq,
                        'message' => sprintf('VR-01: Seconds must be 0–59.999 (given: %f)', $sec),
                    ];
                }

                if (empty($courseErrors)) {
                    try {
                        $bearingObj = Bearing::fromQuadrant($quadrant, $deg, $min, $sec);
                        $azimuthDd = $bearingObj->toAzimuth()->toDecimalDegrees();
                    } catch (InvalidArgumentException $e) {
                        $msg = $e->getMessage();
                        $rule = str_contains($msg, 'VR-07') ? 'VR-07' : 'VR-01';
                        $courseErrors[] = [
                            'rule' => $rule,
                            'field' => 'bearing',
                            'course_seq' => $seq,
                            'message' => $msg,
                        ];
                    }
                }
            } elseif (isset($c['azimuth_dd'])) {
                $az = (float) $c['azimuth_dd'];
                if ($az < 0.0 || $az >= 360.0) {
                    $courseErrors[] = [
                        'rule' => 'VR-02',
                        'field' => 'azimuth_dd',
                        'course_seq' => $seq,
                        'message' => "VR-02: Azimuth must be 0 <= Az < 360 (given: {$az})",
                    ];
                } else {
                    $azimuthDd = $az;
                    $bearingObj = Bearing::fromAzimuth(new Azimuth($az));
                }
            } else {
                $courseErrors[] = [
                    'rule' => 'VR-01',
                    'field' => 'bearing',
                    'course_seq' => $seq,
                    'message' => 'VR-01: Bearing is required.',
                ];
            }

            // 2. Resolve Distance
            $distanceObj = null;
            $distVal = $c['distance'] ?? $c['distance_m'] ?? $c['original_distance'] ?? null;
            $unit = (string) ($c['unit'] ?? $c['original_unit'] ?? 'm');

            if ($distVal === null) {
                $courseErrors[] = [
                    'rule' => 'VR-04',
                    'field' => 'distance',
                    'course_seq' => $seq,
                    'message' => 'VR-04: Distance is required.',
                ];
            } else {
                $distNum = (float) $distVal;
                try {
                    $distanceObj = Distance::fromUnit($distNum, $unit);
                    if ($distanceObj->hasWarning()) {
                        $warnings[] = [
                            'rule' => 'VR-05',
                            'field' => 'distance',
                            'course_seq' => $seq,
                            'message' => (string) $distanceObj->getWarningMessage(),
                        ];
                    }
                } catch (InvalidArgumentException $e) {
                    $msg = $e->getMessage();
                    $rule = str_contains($msg, 'VR-06') ? 'VR-06' : 'VR-04';
                    $courseErrors[] = [
                        'rule' => $rule,
                        'field' => 'distance',
                        'course_seq' => $seq,
                        'message' => $msg,
                    ];
                }
            }

            // 3. Consecutive Course Cross-Checks (VR-08, VR-09)
            if ($azimuthDd !== null && $prevAzimuth !== null && $distanceObj !== null) {
                $distM = $distanceObj->toMeters();

                // VR-08: Collinear check
                $azDiff = abs($azimuthDd - $prevAzimuth);
                if ($azDiff < 1e-4) {
                    $warnings[] = [
                        'rule' => 'VR-08',
                        'field' => 'bearing',
                        'course_seq' => $seq,
                        'message' => sprintf('VR-08: Consecutive courses #%d and #%d have identical bearing (collinear).', $seq - 1, $seq),
                    ];
                }

                // VR-09: Reversed course check (azimuth differs by 180° ± 0.001° and matching distance)
                $oppositeDiff = abs($azDiff - 180.0);
                if ($oppositeDiff < 0.001 && $prevDistanceMeters !== null && abs($distM - $prevDistanceMeters) < 0.01) {
                    $warnings[] = [
                        'rule' => 'VR-09',
                        'field' => 'bearing',
                        'course_seq' => $seq,
                        'message' => sprintf('VR-09: Course #%d reverses previous course #%d with identical distance.', $seq, $seq - 1),
                    ];
                }
            }

            // Record state for next iteration
            if ($azimuthDd !== null) {
                $prevAzimuth = $azimuthDd;
            }
            if ($distanceObj !== null) {
                $prevDistanceMeters = $distanceObj->toMeters();
            }

            foreach ($courseErrors as $err) {
                $errors[] = $err;
            }

            $validatedCourses[] = [
                'seq' => $seq,
                'from_point_label' => (string) ($c['from_point_label'] ?? (string) $seq),
                'to_point_label' => (string) ($c['to_point_label'] ?? (string) ($seq + 1)),
                'bearing' => $bearingObj?->toNormalizedString() ?? ($c['bearing'] ?? null),
                'azimuth_dd' => $azimuthDd,
                'distance_m' => $distanceObj?->toMeters() ?? null,
                'original_distance' => $distVal,
                'original_unit' => $unit,
                'resolved' => empty($courseErrors),
                'errors' => $courseErrors,
            ];
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'validated_courses' => $validatedCourses,
        ];
    }
}
