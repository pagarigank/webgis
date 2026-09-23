<?php
declare(strict_types=1);

namespace App\Survey\Domain;

/**
 * Pure coordinate derivation planning for survey control points (TASK-073).
 *
 * A control point may be entered with either a projected pair (easting /
 * northing in a projected native CRS) or a geographic pair (latitude /
 * longitude in WGS 84). Whichever pair the caller supplies is the ORIGINAL
 * input; the other pair is DERIVED and must be labelled as such in API
 * responses. This class contains no I/O: it validates the raw request fields
 * and returns a plan the HTTP layer executes against PostGIS.
 */
final class CoordinateDerivation
{
    public const ORIGIN_PROJECTED = 'PROJECTED';
    public const ORIGIN_GEOGRAPHIC = 'GEOGRAPHIC';

    /**
     * Decide which coordinate pair is original and which is derived.
     *
     * Recognised input fields: coordinate_origin, easting, northing,
     * latitude, longitude, elevation (all optional at this layer — required
     * fields are reported as errors when missing).
     *
     * @param array<string,mixed> $input raw request fields
     * @return array{
     *     valid: bool,
     *     errors: array<string,string>,
     *     origin: string,
     *     original: array<string,float|null>,
     *     derived: array<string,bool>,
     *     elevation: float|null
     * }
     */
    public static function plan(array $input): array
    {
        $errors = [];

        $origin = strtoupper(trim((string) ($input['coordinate_origin'] ?? self::ORIGIN_PROJECTED)));
        if ($origin === '') {
            $origin = self::ORIGIN_PROJECTED;
        }
        if (!in_array($origin, [self::ORIGIN_PROJECTED, self::ORIGIN_GEOGRAPHIC], true)) {
            $errors['coordinate_origin'] = 'coordinate_origin must be PROJECTED or GEOGRAPHIC';
            $origin = self::ORIGIN_PROJECTED;
        }

        $easting   = self::coord($input, 'easting', $errors);
        $northing  = self::coord($input, 'northing', $errors);
        $latitude  = self::coord($input, 'latitude', $errors);
        $longitude = self::coord($input, 'longitude', $errors);
        $elevation = self::coord($input, 'elevation', $errors);

        if ($latitude !== null && ($latitude < -90.0 || $latitude > 90.0)) {
            $errors['latitude'] = 'latitude must be between -90 and 90';
        }
        if ($longitude !== null && ($longitude < -180.0 || $longitude > 180.0)) {
            $errors['longitude'] = 'longitude must be between -180 and 180';
        }

        // A pair must be given complete: one member without the other is an error.
        if (($easting === null) !== ($northing === null)) {
            $missing = $easting === null ? 'easting' : 'northing';
            $errors[$missing] ??= "{$missing} is required together with northing/easting";
        }
        if (($latitude === null) !== ($longitude === null)) {
            $missing = $latitude === null ? 'latitude' : 'longitude';
            $errors[$missing] ??= "{$missing} is required together with longitude/latitude";
        }

        $hasProjected  = $easting !== null && $northing !== null
            && !isset($errors['easting']) && !isset($errors['northing']);
        $hasGeographic = $latitude !== null && $longitude !== null
            && !isset($errors['latitude']) && !isset($errors['longitude']);

        if (!$hasProjected && !$hasGeographic && $errors === []) {
            if ($origin === self::ORIGIN_PROJECTED) {
                $errors['easting']  = 'easting is required when coordinate_origin is PROJECTED';
                $errors['northing'] = 'northing is required when coordinate_origin is PROJECTED';
            } else {
                $errors['latitude']  = 'latitude is required when coordinate_origin is GEOGRAPHIC';
                $errors['longitude'] = 'longitude is required when coordinate_origin is GEOGRAPHIC';
            }
        }

        // Ambiguity: both complete pairs supplied — the original pair must be unambiguous.
        if ($hasProjected && $hasGeographic) {
            $errors['easting'] = 'Provide either easting/northing or latitude/longitude, not both pairs';
        }

        if ($origin === self::ORIGIN_PROJECTED) {
            $original = ['easting' => $easting, 'northing' => $northing];
            $derived  = [
                'easting'   => false,
                'northing'  => false,
                'latitude'  => true,
                'longitude' => true,
            ];
        } else {
            $original = ['latitude' => $latitude, 'longitude' => $longitude];
            $derived  = [
                'easting'   => true,
                'northing'  => true,
                'latitude'  => false,
                'longitude' => false,
            ];
        }

        return [
            'valid'     => $errors === [],
            'errors'    => $errors,
            'origin'    => $origin,
            'original'  => $original,
            'derived'   => $derived,
            'elevation' => $elevation,
        ];
    }

    /**
     * Read one optional coordinate field. Returns null when absent, records an
     * error and returns null when present but not a finite number.
     *
     * @param array<string,mixed>  $input
     * @param array<string,string> $errors
     */
    private static function coord(array $input, string $key, array &$errors): ?float
    {
        if (!array_key_exists($key, $input) || $input[$key] === null || $input[$key] === '') {
            return null;
        }

        $raw = $input[$key];
        if (is_string($raw)) {
            $raw = trim($raw);
        }
        if (!is_numeric($raw)) {
            $errors[$key] = "{$key} must be a finite number";
            return null;
        }

        $value = (float) $raw;
        if (!is_finite($value)) {
            $errors[$key] = "{$key} must be a finite number";
            return null;
        }

        return $value;
    }
}
