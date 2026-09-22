<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddExtentToGisLayers extends AbstractMigration
{
    public function change(): void
    {
        $this->table('app.gis_layers')
            ->addColumn('extent', 'geometry', ['null' => true, 'comment' => 'Bounding box for the layer'])
            ->update();
    }
}
