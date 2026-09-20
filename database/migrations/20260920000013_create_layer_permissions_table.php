<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateLayerPermissionsTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('app.layer_permissions', ['id' => false, 'primary_key' => ['layer_id', 'role_id']]);
        
        $table
            ->addColumn('layer_id', 'biginteger')
            ->addColumn('role_id', 'biginteger')
            ->addColumn('can_view', 'boolean', ['default' => false])
            ->addColumn('can_create', 'boolean', ['default' => false])
            ->addColumn('can_update', 'boolean', ['default' => false])
            ->addColumn('can_delete', 'boolean', ['default' => false])
            ->addColumn('can_approve', 'boolean', ['default' => false])
            ->addForeignKey('layer_id', 'app.gis_layers', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('role_id', 'app.roles', 'id', ['delete' => 'CASCADE'])
            ->create();
    }
}
