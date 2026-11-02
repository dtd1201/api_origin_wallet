<?php

namespace Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\PostgresConnection;
use Illuminate\Foundation\Application as FoundationApplication;
use Illuminate\Support\ConfigurationUrlParser;
use LogicException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\PayoutPostgresSafety;

class PayoutPostgresSafetyTest extends TestCase
{
    private array $originalEnvironment = [];

    protected function safety(): string
    {
        return PayoutPostgresSafety::class;
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => '/tmp/origin-wallet-p0-pg',
            'DB_PORT' => '55439',
            'DB_DATABASE' => 'nium_p0_phase4_test',
            'DB_URL' => '',
        ] as $name => $value) {
            $this->originalEnvironment[$name] = getenv($name);
            putenv($name.'='.$value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            putenv($value === false ? $name : $name.'='.$value);
        }

        parent::tearDown();
    }

    public static function unsafeEnvironments(): array
    {
        return [['APP_ENV', 'production'], ['APP_ENV', 'staging'], ['DB_CONNECTION', 'sqlite'],
            ['DB_HOST', '127.0.0.1'], ['DB_PORT', '5432'], ['DB_DATABASE', 'production'],
            ['DB_DATABASE', 'staging'], ['DB_URL', 'postgresql://localhost/production']];
    }

    #[DataProvider('unsafeEnvironments')]
    public function test_unsafe_environment_is_rejected_before_connecting(string $key, string $value): void
    {
        putenv($key.'='.$value);
        $this->expectException(LogicException::class);
        ($this->safety())::assertBootstrapConnection();
    }

    public static function absentUrls(): array
    {
        return [
            'empty raw and resolved URLs' => ['', ['url' => '']],
            'empty raw and null resolved URL' => ['', ['url' => null]],
            'empty raw and missing resolved URL' => ['', []],
            'null raw and empty resolved URL' => [null, ['url' => '']],
            'null raw and resolved URLs' => [null, ['url' => null]],
            'null raw and missing resolved URL' => [null, []],
        ];
    }

    #[DataProvider('absentUrls')]
    public function test_absent_urls_are_accepted_only_with_a_verified_writer(?string $rawUrl, array $resolvedUrl): void
    {
        $raw = array_replace($this->configuration(), ['url' => $rawUrl]);
        $resolved = $this->configuration();
        unset($resolved['url']);
        $resolved = array_replace($resolved, $resolvedUrl);
        $pdo = $this->writer($this->identity());

        $this->assertSame($pdo, ($this->safety())::verifiedLaravelConnection($this->application($raw, $resolved, $pdo)));
    }

    public static function laravelAbsentUrls(): array
    {
        return ['empty URL' => [''], 'null URL' => [null]];
    }

    #[DataProvider('laravelAbsentUrls')]
    public function test_laravel_url_normalization_keeps_the_guard_usable(?string $url): void
    {
        $raw = array_replace($this->configuration(), ['url' => $url]);
        $resolved = (new ConfigurationUrlParser)->parseConfiguration($raw);
        $this->assertArrayNotHasKey('url', $resolved);
        $pdo = $this->writer($this->identity());

        $this->assertSame($pdo, ($this->safety())::verifiedLaravelConnection($this->application($raw, $resolved, $pdo)));
    }

    public static function unsafeUrls(): iterable
    {
        foreach (['raw', 'resolved'] as $stage) {
            foreach (['postgresql://localhost/nium_p0_phase4_test', ' ', '0', false, 0, []] as $index => $url) {
                yield $stage.' URL '.$index => [$stage, $url];
            }
        }
    }

    #[DataProvider('unsafeUrls')]
    public function test_non_absent_urls_are_rejected_before_querying_the_writer(string $stage, mixed $url): void
    {
        $raw = $resolved = $this->configuration();
        if ($stage === 'raw') {
            $raw['url'] = $url;
        } else {
            $resolved['url'] = $url;
        }
        $pdo = $this->unusedWriter();
        $app = $this->application($raw, $resolved, $pdo);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unsafe resolved PostgreSQL connection setting: url');
        ($this->safety())::verifiedLaravelConnection($app);
    }

    public static function unsafeConfigurations(): iterable
    {
        foreach (['raw', 'resolved'] as $stage) {
            foreach ([
                ['database', 'nium_p0_execution_test'],
                ['database', 'production'],
                ['database', 'staging'],
                ['database', 'another_test'],
                ['host', '/tmp/another-pg'],
                ['host', '127.0.0.1'],
                ['port', '5432'],
            ] as $index => [$setting, $value]) {
                yield $stage.' target '.$index => [$stage, $setting, $value];
            }
        }
    }

    #[DataProvider('unsafeConfigurations')]
    public function test_url_normalization_does_not_relax_other_configuration_checks(string $stage, string $setting, string $value): void
    {
        $raw = $resolved = $this->configuration();
        if ($stage === 'raw') {
            $raw[$setting] = $value;
        } else {
            $resolved[$setting] = $value;
        }
        $pdo = $this->unusedWriter();
        $app = $this->application($raw, $resolved, $pdo);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unsafe resolved PostgreSQL connection setting: '.$setting);
        ($this->safety())::verifiedLaravelConnection($app);
    }

    public static function unsafeWriterIdentities(): array
    {
        return [
            'protected execution database' => ['database', 'nium_p0_execution_test'],
            'production database' => ['database', 'production'],
            'staging database' => ['database', 'staging'],
            'another database' => ['database', 'another_test'],
            'wrong socket' => ['socket_directories', '/tmp/another-pg'],
            'additional socket' => ['socket_directories', '/tmp/origin-wallet-p0-pg,/tmp/another-pg'],
            'wrong port' => ['port', '5432'],
            'wrong data directory' => ['data_directory', '/tmp/another-pgdata'],
            'TCP enabled' => ['listen_addresses', 'localhost'],
            'TCP connection address' => ['server_address', '127.0.0.1'],
            'TCP connection port' => ['server_port', 55439],
        ];
    }

    #[DataProvider('unsafeWriterIdentities')]
    public function test_absent_url_does_not_bypass_actual_writer_identity_checks(string $setting, mixed $value): void
    {
        $raw = $this->configuration();
        $resolved = $raw;
        unset($resolved['url']);
        $pdo = $this->writer(array_replace($this->identity(), [$setting => $value]));
        $app = $this->application($raw, $resolved, $pdo);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Connected PostgreSQL identity does not match');
        ($this->safety())::verifiedLaravelConnection($app);
    }

    protected function configuration(): array
    {
        return [
            'driver' => 'pgsql',
            'host' => '/tmp/origin-wallet-p0-pg',
            'port' => '55439',
            'database' => 'nium_p0_phase4_test',
            'url' => '',
        ];
    }

    protected function identity(): array
    {
        return [
            'database' => 'nium_p0_phase4_test',
            'port' => '55439',
            'socket_directories' => '/tmp/origin-wallet-p0-pg',
            'data_directory' => '/home/dtd1201/.local/share/origin-wallet-p0-pgdata',
            'listen_addresses' => '',
            'server_address' => null,
            'server_port' => null,
        ];
    }

    private function writer(array $identity): PDO
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects($this->once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn($identity);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('query')->willReturn($statement);

        return $pdo;
    }

    private function unusedWriter(): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('query');

        return $pdo;
    }

    private function application(array $raw, array $resolved, PDO $pdo): Application
    {
        $config = new Repository(['database' => ['default' => 'pgsql', 'connections' => ['pgsql' => $raw]]]);
        $connection = $this->createMock(PostgresConnection::class);
        $connection->method('getConfig')->willReturn($resolved);
        $connection->method('getPdo')->willReturn($pdo);
        $database = $this->createMock(DatabaseManager::class);
        $database->method('connection')->with('pgsql')->willReturn($connection);
        $app = $this->createMock(FoundationApplication::class);
        $app->method('environment')->willReturn('testing');
        $app->method('offsetGet')->willReturnMap([['config', $config], ['db', $database]]);

        return $app;
    }
}
