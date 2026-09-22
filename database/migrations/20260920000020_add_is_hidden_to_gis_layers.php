<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddIsHiddenToGisLayers extends AbstractMigration
{
    public function change(): void
    {
        $this->table('app.gis_layers')
            ->addColumn('is_hidden', 'boolean', ['default' => false, 'comment' => 'Soft-hide from the map layer switcher; still visible in admin lists'])
            ->update();
    }
}