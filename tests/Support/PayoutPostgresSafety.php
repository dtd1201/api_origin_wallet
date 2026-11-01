<?php

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use LogicException;
use PDO;

/** Safety boundary for the destructive remittance concurrency harness only. */
final class PayoutPostgresSafety
{
    private const DATABASE = 'nium_p0_phase4_test';

    private const HOST = '/tmp/origin-wallet-p0-pg';

    private const PORT = '55439';

    // Dedicated local cluster identity, also recorded in its Unix-socket lock file.
    private const DATA_DIRECTORY = '/home/dtd1201/.local/share/origin-wallet-p0-pgdata';

    public static function assertEnvironment(): void
    {
        foreach ([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => self::HOST,
            'DB_PORT' => self::PORT,
            'DB_DATABASE' => self::DATABASE,
            'DB_URL' => '',
        ] as $name => $expected) {
            if (getenv($name) !== $expected) {
                throw new LogicException('Remittance concurrency tests require '.$name.'='.var_export($expected, true)
                    .'; nium_p0_execution_test, production, staging and all other targets are forbidden.');
            }
        }
    }

    public static function assertBootstrapConnection(): void
    {
        self::assertEnvironment();

        // A fixed DSN prevents URL/libpq defaults from choosing another endpoint.
        $pdo = new PDO(
            'pgsql:host='.self::HOST.';port='.self::PORT.';dbname='.self::DATABASE.';connect_timeout=5',
            getenv('DB_USERNAME') === false ? 'root' : getenv('DB_USERNAME'),
            getenv('DB_PASSWORD') === false ? '' : getenv('DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        self::assertIdentity($pdo);
    }

    public static function verifiedLaravelConnection(Application $app): PDO
    {
        self::assertEnvironment();
        if ($app->environment() !== 'testing' || $app['config']->get('database.default') !== 'pgsql') {
            throw new LogicException('Resolved application configuration must use testing and pgsql.');
        }

        // Check before Laravel merges away read/write configuration keys.
        self::assertConfiguration($app['config']->get('database.connections.pgsql'));
        $connection = $app['db']->connection('pgsql');
        self::assertConfiguration($connection->getConfig());
        $pdo = $connection->getPdo();
        self::assertIdentity($pdo);

        return $pdo;
    }

    private static function assertConfiguration(array $config): void
    {
        // Laravel removes an absent URL while resolving the connection configuration.
        // Only null (including a missing key) and the empty string mean no URL.
        if (! in_array($config['url'] ?? null, [null, ''], true)) {
            throw new LogicException('Unsafe resolved PostgreSQL connection setting: url');
        }

        foreach ([
            'driver' => 'pgsql',
            'host' => self::HOST,
            'port' => self::PORT,
            'database' => self::DATABASE,
        ] as $name => $expected) {
            if (($config[$name] ?? null) !== $expected) {
                throw new LogicException('Unsafe resolved PostgreSQL connection setting: '.$name);
            }
        }
        // No alternate endpoints, replicas or connector overrides may bypass verification.
        foreach (['read', 'write', 'connect_via_database', 'connect_via_port'] as $name) {
            if (array_key_exists($name, $config)) {
                throw new LogicException('Remittance concurrency tests forbid connection override: '.$name);
            }
        }
    }

    private static function assertIdentity(PDO $pdo): void
    {
        // Query the writer PDO itself, never a read replica or just Laravel's config.
        $identity = $pdo->query(<<<'SQL'
            SELECT current_database() AS database,
                   current_setting('port') AS port,
                   current_setting('unix_socket_directories') AS socket_directories,
                   current_setting('data_directory') AS data_directory,
                   current_setting('listen_addresses') AS listen_addresses,
                   inet_server_addr()::text AS server_address,
                   inet_server_port() AS server_port
            SQL)->fetch(PDO::FETCH_ASSOC);

        if ($identity !== [
            'database' => self::DATABASE,
            'port' => self::PORT,
            'socket_directories' => self::HOST,
            'data_directory' => self::DATA_DIRECTORY,
            'listen_addresses' => '',
            'server_address' => null,
            'server_port' => null,
        ]) {
            throw new LogicException('Connected PostgreSQL identity does not match the dedicated Phase 4 test database/socket/instance.');
        }
    }
}
