<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\PostgresAdapter;

final class CreateAuditLogsTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('audit_logs', ['id' => false, 'primary_key' => ['id']]);
        $table
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('actor', 'string', ['limit' => 255])
            ->addColumn('action', 'string', ['limit' => 100])
            ->addColumn('entity_type', 'string', ['limit' => 100])
            ->addColumn('entity_id', 'string', ['limit' => 255])
            ->addColumn('old_values', 'jsonb', ['null' => true])
            ->addColumn('new_values', 'jsonb', ['null' => true])
            ->addColumn('changed_fields', 'jsonb', ['null' => true])
            ->addColumn('reason', 'text', ['null' => true])
            ->addColumn('ip', 'string', ['limit' => 45, 'null' => true])
            ->addColumn('user_agent', 'text', ['null' => true])
            ->addColumn('request_id', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['entity_type', 'entity_id'])
            ->addIndex(['created_at'])
            ->create();
    }
}
