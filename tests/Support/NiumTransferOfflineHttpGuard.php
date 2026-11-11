<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class NiumTransferOfflineHttpGuard
{
    private static bool $enabled = false;

    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    public static function install(Application $app): void
    {
        if (! $app->hasBeenBootstrapped() || Http::getFacadeApplication() !== $app) {
            throw new RuntimeException('Offline HTTP protection requires the current bootstrapped Laravel application.');
        }

        // Do not let container auto-wiring hide a missing framework binding.
        if (! $app->bound(Factory::class)) {
            throw new RuntimeException('Offline HTTP protection requires a registered Laravel HTTP factory.');
        }

        $factory = $app->make(Factory::class);

        if (! $factory instanceof Factory || Http::getFacadeRoot() !== $factory) {
            throw new RuntimeException('Offline HTTP protection requires the current Laravel HTTP factory as the facade root.');
        }

        // Mutate the current singleton so existing fakes and recordings survive.
        $factory->preventStrayRequests();

        if (! $factory->preventingStrayRequests()) {
            throw new RuntimeException('Offline HTTP protection could not be enabled.');
        }
    }
}
