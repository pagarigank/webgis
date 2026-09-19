<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddGeomToPsgcAreasTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('ref.psgc_areas');
        
        // Use raw SQL to add PostGIS geometry column, as Phinx's built-in column mapping for PostGIS 
        // can be finicky depending on the adapter. 
        // It's safer to just execute ALTER TABLE.
        
        if ($this->isMigratingUp()) {
            $this->execute("ALTER TABLE ref.psgc_areas ADD COLUMN geom geometry(MultiPolygon, 4326)");
            $this->execute("CREATE INDEX psgc_areas_geom_gix ON ref.psgc_areas USING GIST (geom)");
        } else {
            $this->execute("DROP INDEX IF EXISTS ref.psgc_areas_geom_gix");
            $this->execute("ALTER TABLE ref.psgc_areas DROP COLUMN IF EXISTS geom");
        }
    }
}
