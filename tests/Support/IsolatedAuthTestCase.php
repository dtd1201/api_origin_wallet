<?php

namespace Tests\Support;

use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;

/** Authentication tests only: never load a repository dotenv or cached config. */
abstract class IsolatedAuthTestCase extends \Tests\TestCase
{
    private static ?string $directory = null;

    public function createApplication()
    {
        if (self::$directory === null) {
            self::$directory = sys_get_temp_dir().'/origin-auth076-tests-'.bin2hex(random_bytes(12));
            mkdir(self::$directory, 0700);
            foreach (['env', 'bootstrap', 'storage/framework/views', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/logs'] as $folder) {
                mkdir(self::$directory.'/'.$folder, 0700, true);
            }
        }
        $root = self::$directory;
        $postgres = getenv('AUTH076_POSTGRES') === '1';
        $values = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'APP_URL' => 'https://api.auth.example.test', 'BCRYPT_ROUNDS' => '4',
            'DB_CONNECTION' => $postgres ? 'pgsql' : 'sqlite', 'DB_URL' => '',
            'DB_DATABASE' => $postgres ? 'origin_wallet_auto02b_test' : ':memory:',
            'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432', 'DB_USERNAME' => 'dtd1201',
            'DB_PASSWORD' => $postgres ? (getenv('AUTH076_DISPOSABLE_DB_PASSWORD') ?: '') : '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array', 'AUTH_EXPOSE_VERIFICATION_CODE' => 'false', 'LOG_CHANNEL' => 'null',
            'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
            'NIUM_VA_ALLOCATION_PAUSED' => 'true', 'NIUM_VA_PRODUCTION_ADMISSION_ENABLED' => 'false'];
        foreach (['CONFIG', 'ROUTES', 'EVENTS', 'SERVICES', 'PACKAGES'] as $kind) {
            $values['APP_'.$kind.'_CACHE'] = $root.'/bootstrap/'.strtolower($kind).'.php';
        }
        $repository = RepositoryBuilder::createWithNoAdapters()->addAdapter(ArrayAdapter::class)->make();
        foreach ($values as $key => $value) $repository->set($key, $value);
        $property = new \ReflectionProperty(Env::class, 'repository');
        $property->setAccessible(true);
        $property->setValue(null, $repository);
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->useEnvironmentPath($root.'/env');
        $app->useStoragePath($root.'/storage');
        $this->traitsUsedByTest = array_flip(class_uses_recursive(static::class));
        $app->booted(static fn () => Http::preventStrayRequests());
        $app->make(Kernel::class)->bootstrap();
        return $app;
    }
}
