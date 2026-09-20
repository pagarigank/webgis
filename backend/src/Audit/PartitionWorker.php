<?php
declare(strict_types=1);

namespace App\Audit;

use PDO;

/**
 * PartitionWorker ensures the next monthly audit_logs partition exists.
 *
 * Run this once a month (e.g., via cron on the 1st at 02:00).
 * It creates the partition for the following month if it does not already exist,
 * so the system never writes to an unmapped range.
 *
 * Design notes:
 *  - Partitions are named audit.audit_logs_YYYY_MM
 *  - Uses CREATE TABLE IF NOT EXISTS ... PARTITION OF to be idempotent
 *  - Must be run with a role that has CREATE privilege on schema audit
 */
final class PartitionWorker
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Ensure the partition for the given year+month exists.
     * Defaults to next calendar month from today.
     */
    public function ensureNextPartition(?int $year = null, ?int $month = null): void
    {
        if ($year === null || $month === null) {
            $next = new \DateTimeImmutable('first day of next month');
            $year  = (int) $next->format('Y');
            $month = (int) $next->format('n');
        }

        $start = sprintf('%04d-%02d-01', $year, $month);
        $end   = (new \DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
        $name  = sprintf('audit_logs_%04d_%02d', $year, $month);

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS audit.{$name}
            PARTITION OF audit.audit_logs
            FOR VALUES FROM ('{$start}') TO ('{$end}')
        ");
    }

    /**
     * Convenience: ensure both this month and next month exist.
     */
    public function ensureCurrentAndNext(): void
    {
        $now   = new \DateTimeImmutable();
        $this->ensureNextPartition((int) $now->format('Y'), (int) $now->format('n'));
        $next  = $now->modify('+1 month');
        $this->ensureNextPartition((int) $next->format('Y'), (int) $next->format('n'));
    }
}
