<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * TASK-127 — export service: provenance evidence on the existing job ledger.
 *
 * `app.export_jobs` was created by 20260920000009 as a placeholder for a queued
 * export that stored its artefact in `app.documents`. No code used it, and the
 * service streams inline instead of queueing, so `document_id` stays NULL and
 * `status` moves straight to COMPLETED. What the AC does need is evidence that
 * the export was audited and carried the disclaimer, and that PII was redacted
 * unless the caller was permitted to see it — so those facts are recorded here
 * rather than only in the audit log.
 */
final class AddExportJobProvenance extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('app.export_jobs');

        $table
            ->addColumn('disclaimer', 'text', ['null' => true])
            ->addColumn('pii_included', 'boolean', ['default' => false])
            ->addColumn('redacted_field_count', 'integer', ['default' => 0])
            ->addColumn('filename', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('byte_size', 'biginteger', ['default' => 0])
            ->addColumn('request_id', 'string', ['limit' => 64, 'null' => true])
            ->addIndex(['status', 'requested_at'])
            ->update();

        $this->execute(
            "ALTER TABLE app.export_jobs ADD CONSTRAINT ck_export_format "
            . "CHECK (format IN ('GEOJSON','CSV','KML','SHAPEFILE','GEOPACKAGE'))"
        );
        $this->execute(
            "ALTER TABLE app.export_jobs ADD CONSTRAINT ck_export_status "
            . "CHECK (status IN ('QUEUED','RUNNING','COMPLETED','FAILED','REJECTED'))"
        );
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE app.export_jobs DROP CONSTRAINT IF EXISTS ck_export_status');
        $this->execute('ALTER TABLE app.export_jobs DROP CONSTRAINT IF EXISTS ck_export_format');
        $this->table('app.export_jobs')
            ->removeIndex(['status', 'requested_at'])
            ->removeColumn('request_id')
            ->removeColumn('byte_size')
            ->removeColumn('filename')
            ->removeColumn('redacted_field_count')
            ->removeColumn('pii_included')
            ->removeColumn('disclaimer')
            ->update();
    }
}
