<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * TASK-073 — numeric area-of-use bounds on ref.crs_registry.
 *
 * The registry only carries a prose `area_of_use_note`, which cannot be used
 * to reject a control point whose coordinates lie outside its CRS area of use.
 * This migration adds EPSG-style bounding-box columns and seeds them for every
 * CRS the project registry carries: world bounds for the global CRS, and
 * Philippines bounds for the PRS92 / Luzon 1911 national zones. NULL bounds
 * mean "unknown" and disable the check for that row.
 */
final class AddCrsAreaOfUseBounds extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("
            ALTER TABLE ref.crs_registry
                ADD COLUMN area_south numeric(9,6),
                ADD COLUMN area_west  numeric(9,6),
                ADD COLUMN area_north numeric(9,6),
                ADD COLUMN area_east  numeric(9,6)
        ");

        // Approximate EPSG area-of-use bounding box for the Philippines
        // (onshore and offshore) used by the national zones.
        $ph = [4.40, 116.50, 21.50, 127.00];

        $rows = [
            [4326, -90.00, -180.00, 90.00, 180.00],
            [3857, -85.06, -180.00, 85.06, 180.00],
            [3121, ...$ph],
            [3122, ...$ph],
            [3123, ...$ph],
            [3124, ...$ph],
            [3125, ...$ph],
            [25391, ...$ph],
            [25392, ...$ph],
            [25393, ...$ph],
            [25394, ...$ph],
            [25395, ...$ph],
        ];

        foreach ($rows as [$srid, $south, $west, $north, $east]) {
            $this->execute(sprintf(
                'UPDATE ref.crs_registry SET area_south = %.6F, area_west = %.6F, area_north = %.6F, area_east = %.6F WHERE srid = %d',
                $south,
                $west,
                $north,
                $east,
                $srid
            ));
        }
    }

    public function down(): void
    {
        $this->execute("
            ALTER TABLE ref.crs_registry
                DROP COLUMN area_south,
                DROP COLUMN area_west,
                DROP COLUMN area_north,
                DROP COLUMN area_east
        ");
    }
}
