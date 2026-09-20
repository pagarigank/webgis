<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Fixed-window per-identity token buckets backing the app-level rate limiter
 * (specification.md SR-08, api.md §1.5). Keys are "{class}:{identity}" with the
 * window slice; routes over the per-class limit are answered 429 RATE_LIMITED.
 *
 * TASK-035. nginx applies a coarse global throttle; this table gives the PHP
 * RateLimitMiddleware a shared, multi-worker store (per-user buckets).
 */

final class CreateRateLimitEntriesTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('app.rate_limit_entries', [
            'id' => false,
            'primary_key' => ['bucket_key', 'window_start'],
        ]);

        $table
            ->addColumn('bucket_key', 'string', ['limit' => 255])
            ->addColumn('window_start', 'biginteger')
            ->addColumn('bucket_count', 'integer', ['default' => 1])
            ->addIndex(['window_start'])
            ->create();

        $this->execute('ALTER TABLE app.rate_limit_entries ENABLE ROW LEVEL SECURITY');

        $this->execute(
            'CREATE POLICY rate_limit_all ON app.rate_limit_entries '
            . 'USING (true) WITH CHECK (true)'
        );
    }
}