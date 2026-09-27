<?php
declare(strict_types=1);

namespace App\Core\Crs;

use App\Core\Error\ApiError;
use PDO;

/**
 * TASK-095 — Explicit Coordinate Transformation Service.
 *
 * Transforms coordinates via PostGIS ST_Transform and logs transformation events
 * to app.coordinate_transformations with method, parameters, source, accuracy,
 * and operator.
 *
 * AC: No code path transforms historical coordinates implicitly on read;
 * original and transformed coordinates are always maintained and returned separately.
 */
class CoordinateTransformationService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Resolve CRS row by ID, SRID, or Code from ref.crs_registry.
     *
     * @return array{id: int, srid: int, code: string, name: string, is_projected: bool, area_south: ?float, area_west: ?float, area_north: ?float, area_east: ?float}
     */
    public function resolveCrs(int|string $identifier): array
    {
        if (is_numeric($identifier)) {
            $stmt = $this->pdo->prepare('SELECT id, srid, code, name, is_projected, area_south, area_west, area_north, area_east FROM ref.crs_registry WHERE id = :v OR srid = :v LIMIT 1');
            $stmt->execute([':v' => (int) $identifier]);
        } else {
            $stmt = $this->pdo->prepare('SELECT id, srid, code, name, is_projected, area_south, area_west, area_north, area_east FROM ref.crs_registry WHERE UPPER(code) = UPPER(:v) LIMIT 1');
            $stmt->execute([':v' => trim((string) $identifier)]);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ApiError('CRS_UNSUPPORTED', "Coordinate reference system '{$identifier}' is not registered in ref.crs_registry.", 422);
        }

        return [
            'id'           => (int) $row['id'],
            'srid'         => (int) $row['srid'],
            'code'         => (string) $row['code'],
            'name'         => (string) $row['name'],
            'is_projected' => (bool) $row['is_projected'],
            'area_south'   => $row['area_south'] !== null ? (float) $row['area_south'] : null,
            'area_west'    => $row['area_west'] !== null ? (float) $row['area_west'] : null,
            'area_north'   => $row['area_north'] !== null ? (float) $row['area_north'] : null,
            'area_east'    => $row['area_east'] !== null ? (float) $row['area_east'] : null,
        ];
    }

    /**
     * Transform an array of coordinate points from source CRS to target CRS.
     *
     * @param array<int, array{x?: float, y?: float, easting?: float, northing?: float, longitude?: float, latitude?: float}> $coordinates
     * @param array{
     *     entity_type?: string,
     *     entity_id?: string,
     *     method?: string,
     *     parameters?: array,
     *     parameter_source?: string,
     *     accuracy_m?: float,
     *     performed_by?: ?int,
     *     notes?: ?string,
     *     persist_log?: bool
     * } $options
     * @return array{
     *     source_crs: array,
     *     target_crs: array,
     *     original: array<int, array{x: float, y: float}>,
     *     transformed: array<int, array{x: float, y: float}>,
     *     transformation_log_id: ?int
     * }
     */
    public function transform(
        array $coordinates,
        int|string $sourceCrsId,
        int|string $targetCrsId,
        array $options = []
    ): array {
        if (empty($coordinates)) {
            throw new ApiError('VALIDATION_FAILED', 'At least one coordinate pair is required for transformation.', 400);
        }

        $sourceCrs = $this->resolveCrs($sourceCrsId);
        $targetCrs = $this->resolveCrs($targetCrsId);

        $fromSrid = $sourceCrs['srid'];
        $toSrid   = $targetCrs['srid'];

        $original = [];
        $transformed = [];

        // PostGIS point transform statement with explicit ::int casts for SRIDs
        $stmt = $this->pdo->prepare('SELECT ST_X(ST_Transform(ST_SetSRID(ST_MakePoint(:x, :y), :from_srid::int), :to_srid::int)) AS tx, ST_Y(ST_Transform(ST_SetSRID(ST_MakePoint(:x, :y), :from_srid::int), :to_srid::int)) AS ty');

        foreach ($coordinates as $pt) {
            $x = (float) ($pt['x'] ?? $pt['easting'] ?? $pt['longitude'] ?? 0.0);
            $y = (float) ($pt['y'] ?? $pt['northing'] ?? $pt['latitude'] ?? 0.0);

            $original[] = ['x' => $x, 'y' => $y];

            try {
                $stmt->execute([
                    ':x'         => $x,
                    ':y'         => $y,
                    ':from_srid' => $fromSrid,
                    ':to_srid'   => $toSrid,
                ]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row === false || $row['tx'] === null || $row['ty'] === null) {
                    throw new ApiError('VALIDATION_FAILED', "Transformation failed for point ({$x}, {$y}).", 422);
                }
                $transformed[] = [
                    'x' => round((float) $row['tx'], 8),
                    'y' => round((float) $row['ty'], 8),
                ];
            } catch (\PDOException $e) {
                throw new ApiError('VALIDATION_FAILED', "PostGIS ST_Transform failed from SRID {$fromSrid} to {$toSrid}: " . $e->getMessage(), 422);
            }
        }

        $logId = null;
        $persistLog = $options['persist_log'] ?? true;
        if ($persistLog && !empty($options['entity_type']) && !empty($options['entity_id'])) {
            $ins = $this->pdo->prepare(
                'INSERT INTO app.coordinate_transformations '
                . '(entity_type, entity_id, source_crs_id, target_crs_id, method, parameters, parameter_source, accuracy_m, performed_by, notes) '
                . 'VALUES (:etype, :eid, :scrs, :tcrs, :method, :params::jsonb, :psource, :acc, :pby, :notes) RETURNING id'
            );
            $ins->execute([
                ':etype'   => (string) $options['entity_type'],
                ':eid'     => (string) $options['entity_id'],
                ':scrs'    => $sourceCrs['id'],
                ':tcrs'    => $targetCrs['id'],
                ':method'  => (string) ($options['method'] ?? 'POSTGIS_ST_TRANSFORM'),
                ':params'  => json_encode($options['parameters'] ?? ['from_srid' => $fromSrid, 'to_srid' => $toSrid]),
                ':psource' => $options['parameter_source'] ?? 'PostGIS / PROJ',
                ':acc'     => $options['accuracy_m'] ?? 0.05,
                ':pby'     => $options['performed_by'] ?? null,
                ':notes'   => $options['notes'] ?? null,
            ]);
            $logId = (int) $ins->fetchColumn();
        }

        return [
            'source_crs'            => $sourceCrs,
            'target_crs'            => $targetCrs,
            'original'              => $original,
            'transformed'           => $transformed,
            'transformation_log_id' => $logId,
        ];
    }

    /**
     * Record a documented `AFFINE_LOCAL` transformation (origin, scale,
     * rotation) without applying it. TASK-125 uses this for a local/assumed CAD
     * grid: the transformation must be written to `app.coordinate_transformations`
     * **before** any imported geometry is accepted (FR-252, architecture.md §17.2).
     *
     * The CRS is used as both the source and target of the record because a local
     * CAD grid has no registry entry of its own — the record documents how the
     * local coordinates were mapped into the declared CRS.
     *
     * @param array{origin_x?:float|int,origin_y?:float|int,scale?:float|int,rotation_deg?:float|int,accuracy_m?:float|int|null,units?:string} $parameters
     */
    public function recordAffineLocal(
        int $crsId,
        array $parameters,
        ?int $performedBy = null,
        ?string $notes = null,
        string $entityType = 'import_job',
        string $entityId = '',
    ): int {
        $crs = $this->resolveCrs($crsId);

        $ins = $this->pdo->prepare(
            'INSERT INTO app.coordinate_transformations '
            . '(entity_type, entity_id, source_crs_id, target_crs_id, method, parameters, parameter_source, accuracy_m, performed_by, notes) '
            . "VALUES (:etype, :eid, :scrs, :tcrs, 'AFFINE_LOCAL', :params::jsonb, :psource, :acc, :pby, :notes) RETURNING id"
        );
        $ins->execute([
            ':etype'   => $entityType,
            ':eid'     => $entityId,
            ':scrs'    => $crs['id'],
            ':tcrs'    => $crs['id'],
            ':params'  => json_encode($parameters, JSON_THROW_ON_ERROR),
            ':psource' => 'User-declared local CAD grid',
            ':acc'     => $parameters['accuracy_m'] ?? null,
            ':pby'     => $performedBy,
            ':notes'   => $notes,
        ]);

        return (int) $ins->fetchColumn();
    }
}
