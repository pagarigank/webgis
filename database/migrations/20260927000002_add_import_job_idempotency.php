<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * TASK-122 — idempotent import commit.
 *
 * `POST /imports/{id}/commit` honours an `Idempotency-Key` (api.md §10): a
 * replayed commit must return the original result and must not insert the
 * production rows twice. The key is stored on the job and protected by a
 * partial unique index so two different jobs can never claim the same key.
 */
final class AddImportJobIdempotency extends AbstractMigration
{
    public function up(): void
    {
        $this->table('app.import_jobs')
            ->addColumn('idempotency_key', 'string', ['limit' => 120, 'null' => true])
            ->update();

        $this->execute(
            'CREATE UNIQUE INDEX ux_import_jobs_idempotency_key '
            . 'ON app.import_jobs (idempotency_key) WHERE idempotency_key IS NOT NULL'
        );
    }

    public function down(): void
    {
        $this->execute('DROP INDEX IF EXISTS app.ux_import_jobs_idempotency_key');
        $this->table('app.import_jobs')->removeColumn('idempotency_key')->update();
    }
}
