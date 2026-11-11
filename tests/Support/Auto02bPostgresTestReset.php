<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use PDO;
use RuntimeException;

/** Test-only destruction of the approved disposable database's public schema. */
final class Auto02bPostgresTestReset
{
    public const DATABASE = 'origin_wallet_auto02b_test';

    public static function assertSafe(Application $app, Connection $connection): void
    {
        // Reject an unsafe configuration BEFORE opening its database connection.
        if (! $app->environment('testing') || ! $app->runningUnitTests()
            || $connection->getDriverName() !== 'pgsql'
            || $connection->getDatabaseName() !== self::DATABASE
            || $connection->getConfig('database') !== self::DATABASE
            || ! in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)
            || $connection->transactionLevel() !== 0) {
            throw new RuntimeException('AUTO-02B schema reset requires testing, loopback PostgreSQL, the exact disposable database and no active transaction.');
        }

        $connection->reconnectIfMissingConnection();
        $pdo = $connection->getPdo();
        if (! $pdo instanceof PDO || $pdo->inTransaction()) {
            throw new RuntimeException('AUTO-02B schema reset requires a writable PDO outside any transaction.');
        }

        // Check the WRITE connection's real identity, not only Laravel configuration
        // or a possible read replica. Never reset another database or schema.
        $identity = $connection->selectOne(
            'SELECT current_database() AS database_name, current_schema() AS schema_name', [], false,
        );
        if (! is_object($identity) || ($identity->database_name ?? null) !== self::DATABASE
            || ($identity->schema_name ?? null) !== 'public') {
            throw new RuntimeException('AUTO-02B schema reset refuses a mismatched server database or schema identity.');
        }
    }

    public static function reset(Application $app, Connection $connection): void
    {
        self::assertSafe($app, $connection);

        // PostgreSQL transactional DDL leaves the old schema intact on failure.
        // Recreate tables, functions AND their safety triggers through migrations.
        $connection->transaction(static function () use ($connection): void {
            if (! $connection->statement('DROP SCHEMA "public" CASCADE')
                || ! $connection->statement('CREATE SCHEMA "public"')) {
                throw new RuntimeException('AUTO-02B schema reset DDL did not succeed.');
            }
        });

        RefreshDatabaseState::$migrated = false;
    }
}
