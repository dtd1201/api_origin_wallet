<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;
use stdClass;
use Tests\Support\NiumTransferOfflineHttpGuard;
use Tests\TestCase;
use Throwable;

final class NiumTransferOfflineHttpGuardTest extends TestCase
{
    private bool $previouslyEnabled;

    protected function setUp(): void
    {
        $this->previouslyEnabled = NiumTransferOfflineHttpGuard::isEnabled();
        NiumTransferOfflineHttpGuard::setEnabled(true);

        try {
            parent::setUp();
            // The shared lifecycle must protect the factory before child setup.
            $this->assertTrue(Http::preventingStrayRequests());
        } catch (Throwable $exception) {
            NiumTransferOfflineHttpGuard::setEnabled($this->previouslyEnabled);

            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            NiumTransferOfflineHttpGuard::setEnabled($this->previouslyEnabled);
        }
    }

    public function test_bootstrap_does_not_resolve_http_without_a_laravel_application(): void
    {
        $factory = Http::getFacadeRoot();
        Http::clearResolvedInstance();
        Http::setFacadeApplication(null);
        NiumTransferOfflineHttpGuard::setEnabled(false);

        try {
            require __DIR__.'/../nium-transfer-offline-bootstrap.php';

            $this->assertTrue(NiumTransferOfflineHttpGuard::isEnabled());
            $this->assertNull(Http::getFacadeApplication());
            $this->assertNull(Http::getFacadeRoot());
        } finally {
            Http::setFacadeApplication($this->app);
            Http::swap($factory);
        }
    }

    public function test_unexpected_http_is_blocked_without_any_fake(): void
    {
        $this->assertBlocked(Http::withOptions([]), 'https://unexpected.invalid/unfaked');
        $this->assertBlocked(
            $this->app->make(Factory::class)->withOptions([]),
            'https://unexpected.invalid/container-factory',
        );
    }

    public function test_explicit_provider_fake_works_and_unmatched_url_is_blocked(): void
    {
        $url = 'https://nium-provider.invalid/api/v1/remittance';
        Http::fake([$url => Http::response(['systemReferenceNumber' => 'SYNTHETIC-ONLY'])]);

        $response = $this->withoutNetwork(Http::withOptions([]))->post($url, ['amount' => '10.00']);

        $this->assertSame('SYNTHETIC-ONLY', $response->json('systemReferenceNumber'));
        $this->assertSame($this->app->make(Factory::class), Http::getFacadeRoot());
        $this->assertTrue(Http::preventingStrayRequests());
        Http::assertSent(fn ($request): bool => $request->url() === $url && $request['amount'] === '10.00');
        $this->assertBlocked(Http::withOptions([]), 'https://nium-provider.invalid/unmatched');
        Http::assertSentCount(1);
    }

    public function test_application_refresh_reinstalls_guard_and_discards_old_fakes(): void
    {
        $oldApp = $this->app;
        $oldFactory = Http::getFacadeRoot();
        $url = 'https://nium-provider.invalid/old-application-fake';
        Http::fake([$url => Http::response(['old' => true])]);

        $this->refreshApplication();

        $this->assertNotSame($oldApp, $this->app);
        $this->assertNotSame($oldFactory, Http::getFacadeRoot());
        $this->assertSame($this->app, Http::getFacadeApplication());
        $this->assertSame($this->app->make(Factory::class), Http::getFacadeRoot());
        $this->assertTrue(Http::preventingStrayRequests());
        $this->assertBlocked(Http::withOptions([]), $url);
    }

    public function test_reinstall_preserves_existing_provider_fake(): void
    {
        $url = 'https://nium-provider.invalid/preserved-fake';
        Http::fake([$url => Http::response(['preserved' => true])]);
        $factory = Http::getFacadeRoot();

        NiumTransferOfflineHttpGuard::install($this->app);

        $this->assertSame($factory, Http::getFacadeRoot());
        $this->assertTrue($this->withoutNetwork(Http::withOptions([]))->get($url)->json('preserved'));
        Http::assertSentCount(1);
    }

    public function test_missing_facade_application_fails_explicitly(): void
    {
        Http::setFacadeApplication(null);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('current bootstrapped Laravel application');

        try {
            NiumTransferOfflineHttpGuard::install($this->app);
        } finally {
            Http::setFacadeApplication($this->app);
        }
    }

    public function test_missing_http_factory_binding_fails_explicitly(): void
    {
        $factory = $this->app->make(Factory::class);
        $this->app->offsetUnset(Factory::class);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('registered Laravel HTTP factory');

        try {
            NiumTransferOfflineHttpGuard::install($this->app);
        } finally {
            $this->app->singleton(Factory::class);
            Http::swap($factory);
        }
    }

    public function test_invalid_http_factory_fails_explicitly(): void
    {
        $factory = $this->app->make(Factory::class);
        Http::swap(new stdClass);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('current Laravel HTTP factory as the facade root');

        try {
            NiumTransferOfflineHttpGuard::install($this->app);
        } finally {
            Http::swap($factory);
        }
    }

    private function assertBlocked(PendingRequest $request, string $url): void
    {
        try {
            $this->withoutNetwork($request)->get($url);
            $this->fail('An unfaked Laravel HTTP request was accepted.');
        } catch (StrayRequestException $exception) {
            $this->assertStringContainsString($url, $exception->getMessage());
        }
    }

    private function withoutNetwork(PendingRequest $request): PendingRequest
    {
        // Retain Laravel's stub middleware but replace its terminal transport.
        // A broken guard must fail the test without reaching any network.
        return $request->setHandler(static function (): never {
            throw new LogicException('The terminal HTTP transport must not run in this regression test.');
        });
    }
}
