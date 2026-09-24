<?php
declare(strict_types=1);

namespace App\Parcels\Domain;

use PDO;

class OverlapDetector
{
    public const DEFAULT_SLIVER_THRESHOLD_SQM = 0.05;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Detect overlaps for a parcel or candidate geometry against active parcels.
     *
     * @param string|null $parcelId ID of the parcel to exclude from self-overlap check
     * @param string|null $geom 4326 GeoJSON, WKT, or null to query parcel's own geometry
     * @param float $sliverThresholdSqm Area threshold below which overlap is considered a sliver
     * @return array{
     *   has_overlap: bool,
     *   has_significant_overlap: bool,
     *   total_overlap_area_sqm: float,
     *   sliver_count: int,
     *   overlapping_parcels: array<array{
     *     parcel_id: string,
     *     parcel_code: string,
     *     lot_number: string|null,
     *     status: string,
     *     overlap_area_sqm: float,
     *     overlap_pct: float,
     *     is_sliver: bool
     *   }>
     * }
     */
    public function detectOverlaps(
        ?string $parcelId = null,
        ?string $geom = null,
        float $sliverThresholdSqm = self::DEFAULT_SLIVER_THRESHOLD_SQM
    ): array {
        if ($geom === null && $parcelId !== null) {
            $pStmt = $this->pdo->prepare('SELECT ST_AsGeoJSON(geom) FROM app.parcels WHERE id = :id AND deleted_at IS NULL');
            $pStmt->execute([':id' => $parcelId]);
            $geom = $pStmt->fetchColumn() ?: null;
        }

        if ($geom === null || trim($geom) === '') {
            return [
                'has_overlap'             => false,
                'has_significant_overlap' => false,
                'total_overlap_area_sqm'  => 0.0,
                'sliver_count'            => 0,
                'overlapping_parcels'     => [],
            ];
        }

        $isGeojson = str_starts_with(trim($geom), '{');
        $targetGeomExpr = $isGeojson
            ? 'ST_SetSRID(ST_GeomFromGeoJSON(:geom), 4326)'
            : 'ST_SetSRID(ST_GeomFromText(:geom), 4326)';

        // Query using GIST index on app.parcels.geom
        $sql = "
            WITH target AS (
                SELECT {$targetGeomExpr} AS g
            )
            SELECT 
                p.id::varchar AS parcel_id,
                p.parcel_code,
                p.lot_number,
                p.status,
                ROUND(ST_Area(ST_Intersection(p.geom, target.g)::geography)::numeric, 4) AS overlap_area_sqm,
                ROUND((ST_Area(ST_Intersection(p.geom, target.g)::geography) / NULLIF(ST_Area(target.g::geography), 0) * 100)::numeric, 4) AS overlap_pct
            FROM app.parcels p, target
            WHERE (:exclude_id::text IS NULL OR p.id <> :exclude_id::uuid)
              AND p.deleted_at IS NULL
              AND p.status NOT IN ('ARCHIVED', 'SUPERSEDED')
              AND p.geom IS NOT NULL
              AND ST_Intersects(p.geom, target.g)
              AND ST_Area(ST_Intersection(p.geom, target.g)::geography) > 0.0001
            ORDER BY overlap_area_sqm DESC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':geom'       => $geom,
            ':exclude_id' => $parcelId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $overlappingParcels = [];
        $totalOverlapArea = 0.0;
        $sliverCount = 0;
        $hasSignificant = false;

        foreach ($rows as $r) {
            $area = (float) $r['overlap_area_sqm'];
            $pct = (float) ($r['overlap_pct'] ?? 0.0);
            $isSliver = ($area <= $sliverThresholdSqm);

            if ($isSliver) {
                $sliverCount++;
            } else {
                $hasSignificant = true;
            }

            $totalOverlapArea += $area;

            $overlappingParcels[] = [
                'parcel_id'        => (string) $r['parcel_id'],
                'parcel_code'      => (string) $r['parcel_code'],
                'lot_number'       => $r['lot_number'] !== null ? (string) $r['lot_number'] : null,
                'status'           => (string) $r['status'],
                'overlap_area_sqm' => $area,
                'overlap_pct'      => $pct,
                'is_sliver'        => $isSliver,
            ];
        }

        return [
            'has_overlap'             => count($overlappingParcels) > 0,
            'has_significant_overlap' => $hasSignificant,
            'total_overlap_area_sqm'  => round($totalOverlapArea, 4),
            'sliver_count'            => $sliverCount,
            'overlapping_parcels'     => $overlappingParcels,
        ];
    }
}
