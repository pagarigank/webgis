<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * TASK-125 — DXF/CAD entity-layer → GIS-layer mapping.
 *
 * A DXF file carries many CAD entity layers (the `Layer` field on each entity)
 * and one CAD source layer, so a single job can legitimately distribute its
 * rows across several target GIS layers. The resolved destination is recorded
 * per staged row; `commit()` inserts each row into `COALESCE(target_layer_id,
 * import_jobs.target_layer_id)`, so every other format keeps its job-level
 * destination unchanged.
 */
final class AddImportRowTargetLayer extends AbstractMigration
{
    public function up(): void
    {
        $this->table('staging.import_job_rows')
            ->addColumn('target_layer_id', 'biginteger', ['null' => true])
            ->update();
    }

    public function down(): void
    {
        $this->table('staging.import_job_rows')->removeColumn('target_layer_id')->update();
    }
}
