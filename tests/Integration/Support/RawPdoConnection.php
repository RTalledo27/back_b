<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

use PDO;
use RuntimeException;

/**
 * Helper that hands out independent PDO connections pointed at the same
 * test database. Useful for verifying real PostgreSQL concurrency (row
 * locks, partial uniques, ON CONFLICT outcomes) without resorting to
 * pcntl_fork — which is unavailable on Windows.
 *
 * Hard safety: refuses to open against any database whose name does not
 * contain "test". This makes accidental connection to the development DB
 * impossible from the test suite.
 */
final class RawPdoConnection
{
    public static function open(): PDO
    {
        $config = config('database.connections.pgsql');
        $database = (string) ($config['database'] ?? '');

        if (! str_contains($database, 'test')) {
            throw new RuntimeException(
                "RawPdoConnection refuses to open against database '{$database}': "
                .'name must contain "test" to prevent accidental use against the development DB.'
            );
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $config['host'],
            $config['port'],
            $database,
        );

        return new PDO(
            $dsn,
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_PERSISTENT => false,
            ],
        );
    }

    /**
     * Roll back any open transaction and explicitly free the connection.
     * Safe to call from a finally block: silently no-ops if the PDO is
     * already nulled or if no transaction is active.
     */
    public static function teardown(?PDO &$pdo): void
    {
        if ($pdo === null) {
            return;
        }

        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (\Throwable) {
            // ignore — we are tearing down regardless
        }

        $pdo = null;
    }
}
