<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Api\User\NiumDynamicTransferOptionsController;
use App\Http\Middleware\AuthenticateReadOnlyOptionsToken;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Characterizes the registration blocker, not a registered endpoint's security.
 * Actual global HTTP pipeline, no test routes, real maintenance files or data.
 * A passing result documents the current gap; it does not authorize exposure.
 * Not executed by Codex.
 */
final class NiumDynamicTransferOptionsGlobalBoundaryTest extends TestCase
{
    private const CONNECTION = 'phase4a2b_boundary_offline';

    private const URI = '/api/user/users/1/dynamic-transfer-options';

    private bool $maintenanceActive = false;

    private array $maintenanceData = ['status' => 503, 'retry' => 60];

    private array $attempts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue($this->app->environment('testing'));
        config()->set('app.debug', false);
        config()->set('database.default', self::CONNECTION);
        config()->set('database.connections', [
            self::CONNECTION => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        config()->set('cors', [
            'paths' => ['api/*'],
            'allowed_methods' => ['GET'],
            'allowed_origins' => ['https://allowed.example', 'https://also-allowed.example'],
            'allowed_origins_patterns' => [],
            'allowed_headers' => ['Authorization', 'Accept'],
            'exposed_headers' => [],
            'max_age' => 0,
            'supports_credentials' => false,
        ]);

        // The actual middleware reads this fake contract, never a down file/cache.
        $maintenance = $this->mock(MaintenanceMode::class);
        $maintenance->shouldReceive('active')->andReturnUsing(fn (): bool => $this->maintenanceActive);
        $maintenance->shouldReceive('data')->andReturnUsing(fn (): array => $this->maintenanceData);
        $maintenance->shouldNotReceive('activate');
        $maintenance->shouldNotReceive('deactivate');

        Http::preventStrayRequests();
        Http::fake();
        Bus::fake();
        Queue::fake();
        Notification::fake();
        Mail::fake();
        Log::spy();

        // Reject even reads: these pre-route paths must never reach user storage.
        DB::connection()->beforeExecuting(function (): never {
            $this->attempts[] = 'sql';
            throw new LogicException('Global boundary attempted SQL.');
        });
        foreach ([
            AuthenticateReadOnlyOptionsToken::class,
            NiumDynamicTransferOptionsController::class,
            'App\\Services\\Integrations\\ProviderHttpClient',
            'App\\Services\\Integrations\\ProviderTransferManager',
            'App\\Services\\Nium\\NiumTransferService',
            'App\\Services\\Wallet\\LedgerService',
            'App\\Services\\Wallet\\TransferApprovalService',
        ] as $service) {
            $this->app->bind($service, function () use ($service): never {
                $this->attempts[] = $service;
                throw new LogicException('Global boundary reached a protected service.');
            });
        }
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNothingSent();
            Bus::assertNothingDispatched();
            Queue::assertNothingPushed();
            Notification::assertNothingSent();
            Mail::assertNothingOutgoing();
            $this->assertSame([], $this->attempts);
        } finally {
            DB::purge(self::CONNECTION);
            parent::tearDown();
        }
    }

    public function test_endpoint_remains_unregistered_while_global_boundary_is_blocked(): void
    {
        foreach ($this->app->make(Router::class)->getRoutes() as $route) {
            $this->assertNotSame('api/user/users/{user}/dynamic-transfer-options', $route->uri());
            $this->assertStringNotContainsString(NiumDynamicTransferOptionsController::class, $route->getActionName());
            $this->assertNotContains(AuthenticateReadOnlyOptionsToken::class, $route->gatherMiddleware());
        }
    }

    public function test_debug_maintenance_exception_exposes_diagnostics_without_no_store_before_routing(): void
    {
        $this->maintenanceActive = true;
        config()->set('app.debug', true);

        $response = $this->getJson(self::URI);

        $response->assertStatus(503)
            ->assertHeader('Retry-After', '60')
            ->assertJsonPath('message', 'Service Unavailable')
            ->assertJsonPath('exception', HttpException::class)
            ->assertJsonStructure(['message', 'exception', 'file', 'line', 'trace']);
        $this->assertNotEmpty($response->json('file'));
        $this->assertIsArray($response->json('trace'));
        $this->assertCurrentStorageGap($response);
    }

    public function test_non_debug_maintenance_response_is_generic_but_still_lacks_no_store(): void
    {
        $this->maintenanceActive = true;

        $response = $this->getJson(self::URI);

        $response->assertStatus(503)->assertExactJson(['message' => 'Service Unavailable']);
        $this->assertCurrentStorageGap($response);
    }

    public function test_maintenance_template_also_returns_before_route_cache_protection(): void
    {
        $this->maintenanceActive = true;
        $this->maintenanceData['template'] = '<p>Synthetic maintenance response</p>';

        $response = $this->getJson(self::URI);

        $response->assertStatus(503)->assertContent('<p>Synthetic maintenance response</p>');
        $this->assertCurrentStorageGap($response);
    }

    public function test_debug_route_miss_on_noncanonical_user_exposes_diagnostics_without_no_store(): void
    {
        config()->set('app.debug', true);

        // Remains a miss under the proposed [1-9][0-9]* route constraint too.
        $response = $this->getJson('/api/user/users/01/dynamic-transfer-options');

        $response->assertStatus(404)
            ->assertJsonPath('exception', NotFoundHttpException::class)
            ->assertJsonStructure(['message', 'exception', 'file', 'line', 'trace']);
        $this->assertNotEmpty($response->json('file'));
        $this->assertCurrentStorageGap($response);
    }

    public function test_allowed_preflight_is_empty_and_returns_before_authentication_and_maintenance(): void
    {
        $this->maintenanceActive = true;

        $response = $this->options(self::URI, [], [
            'Origin' => 'https://allowed.example',
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'Authorization',
        ]);

        $response->assertStatus(204)->assertContent('')
            ->assertHeader('Access-Control-Allow-Origin', 'https://allowed.example')
            ->assertHeader('Access-Control-Max-Age', '0');
        $this->assertContains('Origin', $response->baseResponse->getVary());
        $this->assertContains('Access-Control-Request-Method', $response->baseResponse->getVary());
        $this->assertCurrentStorageGap($response);
    }

    public function test_disallowed_preflight_omits_allow_origin_instead_of_returning_an_auth_error(): void
    {
        $this->maintenanceActive = true;

        $response = $this->options(self::URI, [], [
            'Origin' => 'https://denied.example',
            'Access-Control-Request-Method' => 'GET',
        ]);

        $response->assertStatus(204)->assertContent('')->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->assertCurrentStorageGap($response);
    }

    private function assertCurrentStorageGap(TestResponse $response): void
    {
        // These characterize the blocker; no-cache permits private storage.
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-cache'));
        $this->assertFalse($response->headers->hasCacheControlDirective('no-store'));
        foreach (['ETag', 'Last-Modified', 'Expires'] as $header) {
            $this->assertFalse($response->headers->has($header));
        }
    }
}
