<?php
declare(strict_types=1);

namespace App\Core\Db;

use PDO;
use Throwable;

/**
 * Ambient transaction helpers.
 *
 * AuthenticateMiddleware wraps every authenticated request in a PDO
 * transaction (for SET LOCAL session context / audit atomicity), so service
 * layer transactions must join that outer transaction rather than nest.
 * begin() starts a transaction only when none is active and reports whether
 * the caller owns it; commit()/rollback() are no-ops for non-owners, keeping
 * the outer (middleware) transaction authoritative.
 */
final class DbTransaction
{
    public static function begin(PDO $pdo): bool
    {
        if ($pdo->inTransaction()) {
            return false;
        }
        $pdo->beginTransaction();
        return true;
    }

    public static function commit(PDO $pdo, bool $owned): void
    {
        if ($owned && $pdo->inTransaction()) {
            $pdo->commit();
        }
    }

    public static function rollback(PDO $pdo, bool $owned, Throwable $throwable): never
    {
        if ($owned && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $throwable;
    }
}