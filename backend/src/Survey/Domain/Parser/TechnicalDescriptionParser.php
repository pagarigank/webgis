<?php
declare(strict_types=1);

namespace App\Survey\Domain\Parser;

use App\Survey\Domain\Bearing;
use App\Survey\Domain\Distance;
use InvalidArgumentException;

/**
 * Pure domain Technical Description Parser (TASK-082).
 *
 * Tokenizes and extracts cadastral survey technical descriptions:
 * - Tie point names (BLLM, MBM, PBM, etc.)
 * - Tie line bearing & distance
 * - Point of beginning (POB)
 * - Boundary courses (sequence, bearing, distance, to-point)
 * - Source character spans and per-field confidence scores
 * - Unresolved issue flagging with VR-* error rules
 */
final class TechnicalDescriptionParser
{
    /**
     * Parse free-form technical description text.
     *
     * @param string $text
     * @param string $sourceType
     * @param string $distanceUnitHint
     * @return array{
     *     parser_status: string,
     *     tie_point_name: ?string,
     *     tie_lines: array<int,array<string,mixed>>,
     *     point_of_beginning_label: ?string,
     *     courses: array<int,array<string,mixed>>,
     *     area_sqm_claimed: ?float,
     *     overall_confidence: float
     * }
     */
    public function parse(string $text, string $sourceType = 'PASTED_TEXT', string $distanceUnitHint = 'm'): array
    {
        $raw = $text;
        $courses = [];
        $tieLines = [];
        $tiePointName = null;
        $pobLabel = '1';
        $claimedArea = null;

        // 1. Extract Claimed Area if present
        if (preg_match('/containing\s+an\s+area\s+of\s+.*?(?:[\(\s])([0-9,]+(?:\.[0-9]+)?)\s*(?:\)\s*)?(?:square\s+meters|sq\.?\s*m\.?)/i', $raw, $am)) {
            $numStr = str_replace(',', '', $am[1]);
            $claimedArea = (float) $numStr;
        } elseif (preg_match('/([0-9,]+(?:\.[0-9]+)?)\s*(?:square\s+meters|sq\.?\s*m\.?)/i', $raw, $am)) {
            $numStr = str_replace(',', '', $am[1]);
            $claimedArea = (float) $numStr;
        }

        // 2. Extract Tie Point and Tie Line
        // Pattern A: "being S. 45 deg. 12' E., 120.50 m. from BLLM No. 1"
        if (preg_match('/being\s+([NS]\.?\s*\d+[^,;]+?[EW]\.?)[,\s]+([\d\.]+)\s*([a-zA-Z\.]*)\s+from\s+([^,;\n]+)/iu', $raw, $tm, PREG_OFFSET_CAPTURE)) {
            $bearingStr = self::cleanSurveyToken($tm[1][0]);
            $distVal = (float) $tm[2][0];
            $unitStr = self::cleanUnitToken($tm[3][0], $distanceUnitHint);
            $tpCandidate = trim($tm[4][0]);
            $tiePointName = self::normalizePointLabel($tpCandidate);

            $parsedBearing = self::tryParseBearing($bearingStr);
            $parsedDistance = self::tryParseDistance($distVal, $unitStr);

            $tieLines[] = [
                'from_point' => $tiePointName,
                'to_point' => $pobLabel,
                'bearing' => $parsedBearing['data'],
                'distance' => $parsedDistance['data'],
                'confidence' => ($parsedBearing['ok'] && $parsedDistance['ok']) ? 0.95 : 0.60,
                'source_span' => [
                    'start' => $tm[0][1],
                    'end' => $tm[0][1] + strlen($tm[0][0]),
                ],
            ];
        }
        // Pattern B: "From BLLM #1, S 45°12' E, 120.50 m to point 1"
        elseif (preg_match('/from\s+([A-Z0-9\s#\.\-]+?)[,\s]+(?:thence\s+)?([NS]\.?\s*\d+[^,;]+?[EW]\.?)[,\s]+([\d\.]+)\s*([a-zA-Z\.]*)\s*(?:to\s+(?:point|corner|mark)?\s*(\d+|\w+))?/iu', $raw, $tm, PREG_OFFSET_CAPTURE)) {
            $tpCandidate = trim($tm[1][0]);
            $tiePointName = self::normalizePointLabel($tpCandidate);
            $bearingStr = self::cleanSurveyToken($tm[2][0]);
            $distVal = (float) $tm[3][0];
            $unitStr = self::cleanUnitToken($tm[4][0] ?? '', $distanceUnitHint);
            if (!empty($tm[5][0])) {
                $pobLabel = trim($tm[5][0]);
            }

            $parsedBearing = self::tryParseBearing($bearingStr);
            $parsedDistance = self::tryParseDistance($distVal, $unitStr);

            $tieLines[] = [
                'from_point' => $tiePointName,
                'to_point' => $pobLabel,
                'bearing' => $parsedBearing['data'],
                'distance' => $parsedDistance['data'],
                'confidence' => ($parsedBearing['ok'] && $parsedDistance['ok']) ? 0.95 : 0.60,
                'source_span' => [
                    'start' => $tm[0][1],
                    'end' => $tm[0][1] + strlen($tm[0][0]),
                ],
            ];
        }

        // 3. Extract Courses
        // Patterns for course lines:
        // "thence N. 25 deg. 30' E., 45.20 m. to point 2"
        // "1-2: N 25°30'00" E, 45.20 m"
        // "thence S ??°??' E, 30.00 m to point 3"
        $lines = preg_split('/[;\n\r]+/', $raw);
        $offsetAccumulator = 0;
        $courseSeq = 1;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            $lineLen = strlen($line);

            if ($trimmed === '') {
                $offsetAccumulator += $lineLen + 1;
                continue;
            }

            // Skip pure preamble lines that are not courses and not tie lines
            if (preg_match('/^(?:A\s+parcel|Bounded\s+on|Situated\s+in|Beginning\s+at|Containing\s+an\s+area)/i', $trimmed) && !preg_match('/thence/i', $trimmed) && !preg_match('/\d+\s*-\s*\d+\s*:/', $trimmed)) {
                $offsetAccumulator += $lineLen + 1;
                continue;
            }

            // A line may have multiple "thence ... to point X" clauses separated by commas
            $clauses = preg_split('/(?=thence|\b\d+\s*-\s*\d+\s*:)/i', $trimmed);

            foreach ($clauses as $clause) {
                $cl = trim($clause);
                if ($cl === '') {
                    continue;
                }

                // Check if this clause is actually a boundary course (must have bearing-like or distance-like content)
                if (!preg_match('/thence/i', $cl) && !preg_match('/\d+\s*-\s*\d+\s*:/', $cl)) {
                    continue;
                }

                // Compute start and end offsets inside original text
                $startPos = strpos($raw, $cl, $offsetAccumulator);
                if ($startPos === false) {
                    $startPos = $offsetAccumulator;
                }
                $endPos = $startPos + strlen($cl);

                // Detect destination point label
                $toPointLabel = (string) ($courseSeq + 1);
                if (preg_match('/to\s+(?:point|corner|mark)\s*["\']?(\d+|\w+)["\']?/i', $cl, $pm)) {
                    $toPointLabel = $pm[1];
                } elseif (preg_match('/point\s+of\s+beginning/i', $cl)) {
                    $toPointLabel = $pobLabel;
                } elseif (preg_match('/(\d+)\s*-\s*(\d+)\s*:/', $cl, $pm)) {
                    $toPointLabel = $pm[2];
                }

                $fromPointLabel = (string) $courseSeq;
                if (preg_match('/(\d+)\s*-\s*(\d+)\s*:/', $cl, $pm)) {
                    $fromPointLabel = $pm[1];
                }

                // Check for noisy OCR tokens like "??" or missing angles
                $isNoisy = str_contains($cl, '?') || str_contains($cl, '??');

                // Extract bearing string: "\b[NS] ... [EW]\b" with mandatory degrees digit
                $bearingFound = null;
                $bearingOk = false;
                $bearingIssues = [];
                $matchedBearingToken = null;

                if (preg_match('/\b([NS]\.?\s*\d+[^,;]+?[EW]\.?)\b/iu', $cl, $bm)) {
                    $matchedBearingToken = $bm[1];
                    $bStr = self::cleanSurveyToken($bm[1]);
                    $res = self::tryParseBearing($bStr);
                    $bearingFound = $res['data'];
                    $bearingOk = $res['ok'];
                    if (!$res['ok']) {
                        $bearingIssues[] = [
                            'field' => 'bearing',
                            'rule' => 'VR-01',
                            'message' => $res['error'],
                        ];
                    }
                } else {
                    $bearingIssues[] = [
                        'field' => 'bearing',
                        'rule' => 'VR-01',
                        'message' => 'Bearing could not be read.',
                    ];
                }

                // Extract distance string: look in the text after the bearing
                $distanceFound = null;
                $distanceOk = false;
                $distanceIssues = [];

                $textForDistance = $cl;
                if ($matchedBearingToken !== null) {
                    $pos = strpos($cl, $matchedBearingToken);
                    if ($pos !== false) {
                        $textForDistance = substr($cl, $pos + strlen($matchedBearingToken));
                    }
                }

                if (preg_match('/([\d]+(?:\.[\d]+)?)\s*([a-zA-Z\.]*)/u', $textForDistance, $dm)) {
                    $dVal = (float) $dm[1];
                    $uStr = self::cleanUnitToken($dm[2] ?? '', $distanceUnitHint);
                    $res = self::tryParseDistance($dVal, $uStr);
                    $distanceFound = $res['data'];
                    $distanceOk = $res['ok'];
                    if (!$res['ok']) {
                        $distanceIssues[] = [
                            'field' => 'distance',
                            'rule' => 'VR-04',
                            'message' => $res['error'],
                        ];
                    }
                } else {
                    $distanceIssues[] = [
                        'field' => 'distance',
                        'rule' => 'VR-04',
                        'message' => 'Distance could not be read.',
                    ];
                }

                $issues = array_merge($bearingIssues, $distanceIssues);
                $resolved = empty($issues) && !$isNoisy && $bearingOk && $distanceOk;

                $confidence = 0.98;
                if ($isNoisy) {
                    $confidence = 0.40;
                } elseif (!$bearingOk || !$distanceOk) {
                    $confidence = 0.50;
                }

                $courses[] = [
                    'seq' => $courseSeq,
                    'from_point_label' => $fromPointLabel,
                    'to_point_label' => $toPointLabel,
                    'bearing' => $bearingFound,
                    'distance' => $distanceFound,
                    'extraction_method' => $sourceType,
                    'confidence' => $confidence,
                    'source_span' => [
                        'start' => $startPos,
                        'end' => $endPos,
                    ],
                    'resolved' => $resolved,
                    'issues' => $issues,
                ];

                $courseSeq++;
            }

            $offsetAccumulator += $lineLen + 1;
        }

        // Determine overall status
        $status = 'PARSED';
        if (empty($courses)) {
            $status = 'FAILED';
        } else {
            foreach ($courses as $c) {
                if (!$c['resolved']) {
                    $status = 'PARTIAL';
                    break;
                }
            }
        }

        // Compute overall confidence
        $avgConf = 0.0;
        if (!empty($courses)) {
            $total = 0.0;
            foreach ($courses as $c) {
                $total += $c['confidence'];
            }
            $avgConf = round($total / count($courses), 2);
        }

        return [
            'parser_status' => $status,
            'tie_point_name' => $tiePointName,
            'tie_lines' => $tieLines,
            'point_of_beginning_label' => $pobLabel,
            'courses' => $courses,
            'area_sqm_claimed' => $claimedArea,
            'overall_confidence' => $avgConf,
        ];
    }

    /**
     * Clean up textual surveyor degrees / minutes / seconds strings.
     */
    private static function cleanSurveyToken(string $raw): string
    {
        $s = trim($raw);
        $s = preg_replace('/\s+/', ' ', $s);
        // Replace "deg." / "deg" with "°"
        $s = preg_replace('/deg\.?/i', '°', $s);
        // Replace "min." / "min" with "'"
        $s = preg_replace('/min\.?/i', "'", $s);
        // Replace "sec." / "sec" with '"'
        $s = preg_replace('/sec\.?/i', '"', $s);
        // Remove trailing dots from quadrant letters e.g. "N." -> "N"
        $s = preg_replace('/^([NS])\./i', '$1', $s);
        $s = preg_replace('/([EW])\.$/i', '$1', $s);
        return trim($s);
    }

    private static function cleanUnitToken(string $raw, string $default): string
    {
        $u = strtolower(trim($raw, " .\t\n\r"));
        if ($u === '' || $u === 'm' || $u === 'meters' || $u === 'meter') {
            return 'm';
        }
        if ($u === 'ft' || $u === 'feet' || $u === 'foot') {
            return 'ft';
        }
        if ($u === 'vara' || $u === 'varas') {
            return 'vara';
        }
        if ($u === 'ch' || $u === 'chain' || $u === 'chains') {
            return 'ch';
        }
        return $u !== '' ? $u : $default;
    }

    private static function normalizePointLabel(string $raw): string
    {
        $p = trim($raw);
        $p = preg_replace('/\s+/', ' ', $p);
        return trim($p, " ,;");
    }

    /**
     * @return array{ok: bool, data: ?array<string,mixed>, error: ?string}
     */
    private static function tryParseBearing(string $str): array
    {
        try {
            $bearing = Bearing::parse($str);
            $azimuth = $bearing->toAzimuth();
            return [
                'ok' => true,
                'data' => [
                    'quadrant' => $bearing->getQuadrant(),
                    'deg' => $bearing->getDegrees(),
                    'min' => $bearing->getMinutes(),
                    'sec' => $bearing->getSeconds(),
                    'azimuth_dd' => $azimuth->toDecimalDegrees(),
                    'original' => $str,
                ],
                'error' => null,
            ];
        } catch (InvalidArgumentException $e) {
            return [
                'ok' => false,
                'data' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{ok: bool, data: ?array<string,mixed>, error: ?string}
     */
    private static function tryParseDistance(float $val, string $unit): array
    {
        try {
            $dist = Distance::fromUnit($val, $unit);
            return [
                'ok' => true,
                'data' => [
                    'value' => $val,
                    'unit' => $unit,
                    'meters' => $dist->toMeters(),
                    'original' => sprintf('%.2f %s', $val, $unit),
                ],
                'error' => null,
            ];
        } catch (InvalidArgumentException $e) {
            return [
                'ok' => false,
                'data' => null,
                'error' => $e->getMessage(),
            ];
        }
    }
}
