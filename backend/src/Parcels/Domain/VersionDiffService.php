<?php
declare(strict_types=1);

namespace App\Parcels\Domain;

/**
 * TASK-105 — Version comparison and geometry diff (pure domain).
 *
 * Produces a field-level attribute diff and a geometry diff between two
 * parcel version snapshots. Geometry diff identifies added, removed, and
 * moved vertices by comparing exterior rings vertex-to-vertex; a moved
 * vertex is matched by its ring position and reported with both coordinate
 * sets (FR-148: "a moved vertex is visually identified").
 */
final class VersionDiffService
{
    /**
     * @param array<string,mixed> $oldSnapshot Pre-change snapshot (from audit.parcel_versions)
     * @param array<string,mixed> $newSnapshot Post-change snapshot
     * @param array|null $oldGeom Old geometry as [lng, lat] pairs (exterior ring)
     * @param array|null $newGeom New geometry as [lng, lat] pairs
     * @return array{fields:array<int,array{field:string,old:mixed,new:mixed,changed:bool}>, geometry:array|null}
     */
    public function diff(array $oldSnapshot, array $newSnapshot, ?array $oldGeom, ?array $newGeom): array
    {
        $fields = [];
        $keys = array_unique(array_merge(array_keys($oldSnapshot), array_keys($newSnapshot)));
        sort($keys);
        foreach ($keys as $k) {
            $old = $oldSnapshot[$k] ?? null;
            $new = $newSnapshot[$k] ?? null;
            $changed = $this->stringify($old) !== $this->stringify($new);
            if ($changed) {
                $fields[] = ['field' => $k, 'old' => $old, 'new' => $new, 'changed' => true];
            }
        }

        return ['fields' => $fields, 'geometry' => $this->diffGeometry($oldGeom, $newGeom)];
    }

    /**
     * @param array|null $old [ [lng,lat], ... ] exterior ring (closed or open)
     * @param array|null $new
     * @return array|null {added:[],removed:[],moved:[],old_vertex_count:int,new_vertex_count:int}
     */
    public function diffGeometry(?array $old, ?array $new): ?array
    {
        if ($old === null && $new === null) {
            return null;
        }

        $old = $this->normalizeRing($old);
        $new = $this->normalizeRing($new);

        $oldCount = is_array($old) ? count($old) : 0;
        $newCount = is_array($new) ? count($new) : 0;

        $added = [];
        $removed = [];
        $moved = [];

        // Vertices matched by ring position; position-based pairing is what
        // makes a moved vertex visually identifiable (same corner index,
        // different coordinates).
        $common = min($oldCount, $newCount);
        for ($i = 0; $i < $common; $i++) {
            if (!$this->samePoint($old[$i], $new[$i])) {
                $moved[] = ['index' => $i, 'from' => $old[$i], 'to' => $new[$i]];
            }
        }
        for ($i = $common; $i < $newCount; $i++) {
            $added[] = ['index' => $i, 'point' => $new[$i]];
        }
        for ($i = $common; $i < $oldCount; $i++) {
            $removed[] = ['index' => $i, 'point' => $old[$i]];
        }

        return [
            'added'             => $added,
            'removed'           => $removed,
            'moved'             => $moved,
            'old_vertex_count'  => $oldCount,
            'new_vertex_count'  => $newCount,
        ];
    }

    /**
     * Extract the exterior ring of a GeoJSON Polygon/MultiPolygon as [lng,lat]
     * pairs with the closing point dropped.
     *
     * @return array<int, array{0:float,1:float}>|null
     */
    public function exteriorRing(?array $geometry): ?array
    {
        if ($geometry === null) {
            return null;
        }
        $type = $geometry['type'] ?? null;
        $coords = $geometry['coordinates'] ?? null;

        if ($type === 'Polygon' && is_array($coords) && isset($coords[0]) && is_array($coords[0])) {
            return $this->dropClosingPoint($coords[0]);
        }
        if ($type === 'MultiPolygon' && is_array($coords) && isset($coords[0][0]) && is_array($coords[0][0])) {
            return $this->dropClosingPoint($coords[0][0]);
        }
        return null;
    }

    /**
     * @param array<int, mixed> $ring
     * @return array<int, array{0:float,1:float}>
     */
    private function dropClosingPoint(array $ring): array
    {
        $out = [];
        foreach ($ring as $i => $pt) {
            if (!is_array($pt) || count($pt) < 2) {
                continue;
            }
            $out[] = [(float) $pt[0], (float) $pt[1]];
        }
        if (count($out) > 1 && $this->samePoint($out[0], $out[count($out) - 1])) {
            array_pop($out);
        }
        return $out;
    }

    /** @return array<int, array{0:float,1:float}>|null */
    private function normalizeRing(?array $geom): ?array
    {
        if ($geom === null) {
            return null;
        }
        // Already a ring of [lng,lat] pairs.
        if (isset($geom[0]) && is_array($geom[0]) && count($geom[0]) >= 2 && !isset($geom[0]['type'])) {
            return $this->dropClosingPoint($geom);
        }
        return $this->exteriorRing($geom);
    }

    /**
     * @param array{0:float,1:float} $a
     * @param array{0:float,1:float} $b
     */
    private function samePoint(array $a, array $b): bool
    {
        return abs($a[0] - $b[0]) < 1e-9 && abs($a[1] - $b[1]) < 1e-9;
    }

    private function stringify(mixed $v): string
    {
        if ($v === null) {
            return '<null>';
        }
        return is_scalar($v) ? (string) $v : json_encode($v, JSON_THROW_ON_ERROR);
    }
}
