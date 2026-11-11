<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Auto02bPostgresTestReset;

final class Auto02bPostgresTestResetSafetyTest extends TestCase
{
    public static function unsafeTargets(): array
    {
        return [
            'production environment' => [['testing' => false]],
            'not running unit tests' => [['unit_tests' => false]],
            'wrong driver' => [['driver' => 'sqlite']],
            'origin wallet' => [['database' => 'origin_wallet']],
            'staging' => [['database' => 'origin_wallet_staging']],
            'production' => [['database' => 'origin_wallet_production']],
            'similar database name' => [['database' => 'origin_wallet_auto02b_test_copy']],
            'connection config mismatch' => [['configured_database' => 'origin_wallet']],
            'remote host' => [['host' => 'staging.example.invalid']],
            'missing host' => [['host' => null]],
            'Laravel transaction active' => [['transaction_level' => 1]],
            'PDO transaction active' => [['pdo_transaction' => true]],
            'real database mismatch' => [['server_database' => 'origin_wallet']],
            'real schema mismatch' => [['schema' => 'other_schema']],
        ];
    }

    #[DataProvider('unsafeTargets')]
    public function test_unsafe_target_is_rejected_before_any_ddl(array $changes): void
    {
        [$app, $connection] = $this->target($changes);
        $connection->expects($this->never())->method('transaction');
        $connection->expects($this->never())->method('statement');
        $connection->expects($this->never())->method('unprepared');
        if (array_intersect(array_keys($changes), ['server_database', 'schema', 'pdo_transaction']) === []) {
            $connection->expects($this->never())->method('getPdo');
            $connection->expects($this->never())->method('reconnectIfMissingConnection');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AUTO-02B schema reset');
        Auto02bPostgresTestReset::reset($app, $connection);
    }

    public function test_real_identity_is_checked_on_the_write_connection(): void
    {
        [$app, $connection] = $this->target();
        $connection->expects($this->once())->method('selectOne')->with(
            'SELECT current_database() AS database_name, current_schema() AS schema_name', [], false,
        )->willReturn((object) ['database_name' => Auto02bPostgresTestReset::DATABASE, 'schema_name' => 'public']);
        $connection->expects($this->never())->method('statement');

        Auto02bPostgresTestReset::assertSafe($app, $connection);
    }

    private function target(array $changes = []): array
    {
        $options = array_replace(['testing' => true, 'unit_tests' => true, 'driver' => 'pgsql',
            'database' => Auto02bPostgresTestReset::DATABASE, 'configured_database' => Auto02bPostgresTestReset::DATABASE,
            'host' => '127.0.0.1', 'transaction_level' => 0, 'pdo_transaction' => false,
            'server_database' => Auto02bPostgresTestReset::DATABASE, 'schema' => 'public'], $changes);
        $app = $this->createMock(Application::class);
        $app->method('environment')->with('testing')->willReturn($options['testing']);
        $app->method('runningUnitTests')->willReturn($options['unit_tests']);
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriverName')->willReturn($options['driver']);
        $connection->method('getDatabaseName')->willReturn($options['database']);
        $connection->method('getConfig')->willReturnMap([
            ['host', $options['host']], ['database', $options['configured_database']],
        ]);
        $connection->method('transactionLevel')->willReturn($options['transaction_level']);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn($options['pdo_transaction']);
        $connection->method('getPdo')->willReturn($pdo);
        $connection->method('selectOne')->willReturn((object) [
            'database_name' => $options['server_database'], 'schema_name' => $options['schema'],
        ]);

        return [$app, $connection];
    }
}
