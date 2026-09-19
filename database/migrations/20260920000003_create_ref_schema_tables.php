<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateRefSchemaTables extends AbstractMigration
{
    public function change(): void
    {
        // ref.psgc_areas
        $psgcAreas = $this->table('ref.psgc_areas', ['id' => false, 'primary_key' => ['code']]);
        $psgcAreas
            ->addColumn('code', 'string', ['limit' => 10])
            ->addColumn('level', 'string', ['limit' => 20])
            ->addColumn('name', 'string', ['limit' => 255])
            ->addColumn('parent_code', 'string', ['limit' => 10, 'null' => true])
            ->addForeignKey('parent_code', 'ref.psgc_areas', 'code', ['delete' => 'SET_NULL'])
            ->addIndex(['parent_code'])
            ->create();

        // ref.crs_registry
        $crsRegistry = $this->table('ref.crs_registry', ['id' => false, 'primary_key' => ['id']]);
        $crsRegistry
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('srid', 'integer')
            ->addColumn('code', 'string', ['limit' => 50])
            ->addColumn('name', 'string', ['limit' => 255])
            ->addColumn('datum', 'string', ['limit' => 100])
            ->addColumn('zone', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('is_projected', 'boolean', ['default' => true])
            ->addColumn('proj4text', 'text', ['null' => true])
            ->addColumn('wkt', 'text', ['null' => true])
            ->addColumn('area_of_use_note', 'text', ['null' => true])
            ->addColumn('epoch', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('is_historical', 'boolean', ['default' => false])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addIndex(['code'], ['unique' => true])
            ->addIndex(['srid'], ['unique' => true])
            ->create();

        // ref.units
        $units = $this->table('ref.units', ['id' => false, 'primary_key' => ['id']]);
        $units
            ->addColumn('id', 'integer', ['identity' => true])
            ->addColumn('code', 'string', ['limit' => 50])
            ->addColumn('name', 'string', ['limit' => 100])
            ->addColumn('unit_type', 'string', ['limit' => 50]) // e.g. LENGTH, AREA
            ->addColumn('conversion_factor_to_base', 'decimal', ['precision' => 20, 'scale' => 10])
            ->addColumn('is_base', 'boolean', ['default' => false])
            ->addIndex(['code'], ['unique' => true])
            ->create();
    }
}
