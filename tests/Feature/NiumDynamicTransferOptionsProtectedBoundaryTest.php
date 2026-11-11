<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Api\User\NiumDynamicTransferOptionsController;
use App\Http\Middleware\AuthenticateReadOnlyOptionsToken;
use App\Http\Middleware\EnsureAuthenticatedUserMatchesRoute;
use App\Http\Middleware\EnsureUserProfileIsComplete;
use App\Http\Middleware\ProtectDynamicTransferOptionsResponses;
use App\Http\Support\DynamicTransferOptionsResponsePolicy;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Validated;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\Middleware\ValidatePostSize;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;
use Throwable;

/**
 * Actual Laravel kernel and exception handler, test-only middleware/finalizer.
 * No bootstrap edit, application route file edit, migrations or live fixtures.
 * This suite has not been executed by Codex.
 */
final class NiumDynamicTransferOptionsProtectedBoundaryTest extends TestCase
{
    private const CONNECTION = 'phase4a2bs1_protected_offline';

    private const URI = '/api/user/users/101/dynamic-transfer-options';

    private const ROUTE = 'api/user/users/{user}/dynamic-transfer-options';

    private const TOKEN = 'synthetic-protected-options-token';

    private const UNAVAILABLE = ['options' => [], 'reason_codes' => ['trusted_snapshot_unavailable']];

    private bool $inRequest = false;

    private bool $maintenanceActive = false;

    private array $maintenanceData = ['status' => 503, 'retry' => 60, 'refresh' => 30];

    private array $attempts = [];

    private array $authenticationEvents = [];

    private array $reported = [];

    private array $applicationRoutes = [];

    private int $terminations = 0;

    private int $handled = 0;

    private int $finalizations = 0;

    private bool $realEventObserved = false;

    private string $previousTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue($this->app->environment('testing'));
        $this->previousTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        config()->set('app.timezone', 'UTC');
        config()->set('app.debug', false);
        config()->set('auth.defaults.guard', 'web');
        config()->set('session.driver', 'array');
        config()->set('database.default', self::CONNECTION);
        config()->set('database.connections', [
            self::CONNECTION => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        config()->set('cors', [
            'paths' => ['api/*'], 'allowed_methods' => ['GET'],
            'allowed_origins' => ['https://allowed.example', 'https://also-allowed.example'],
            'allowed_origins_patterns' => [], 'allowed_headers' => ['Authorization', 'Accept'],
            'exposed_headers' => [], 'max_age' => 0, 'supports_credentials' => false,
        ]);
        $this->travelTo(Carbon::parse('2026-11-09T00:30:00Z'));
        $this->createFixtures();
        $this->applicationRoutes = $this->routeMap();
        foreach ($this->app->make(Router::class)->getRoutes() as $route) {
            $this->assertNotSame(self::ROUTE, $route->uri());
            $this->assertStringNotContainsString(NiumDynamicTransferOptionsController::class, $route->getActionName());
        }

        $maintenance = $this->mock(MaintenanceMode::class);
        $maintenance->shouldReceive('active')->andReturnUsing(fn (): bool => $this->maintenanceActive);
        $maintenance->shouldReceive('data')->andReturnUsing(fn (): array => $this->maintenanceData);
        $maintenance->shouldNotReceive('activate');
        $maintenance->shouldNotReceive('deactivate');

        Http::preventStrayRequests();
        Http::fake();
        Bus::fake();
        Queue::fake();
        Mail::fake();
        Notification::fake();
        Log::spy();
        // Keep the actual dispatcher and exception reporting lifecycle active.
        foreach ([Authenticated::class, Login::class, Validated::class] as $event) {
            Event::listen($event, function (object $event): void {
                if ($this->inRequest) {
                    $this->authenticationEvents[] = $event::class;
                }
            });
        }
        Event::listen(RequestHandled::class, function (): void {
            if ($this->inRequest) {
                $this->handled++;
            }
        });
        Event::listen('phase4a2bs1.synthetic', function (): void { $this->realEventObserved = true; });
        $this->app->terminating(function (): void {
            if ($this->inRequest) {
                $this->terminations++;
            }
        });
        DB::connection()->beforeExecuting(function (string $sql): void {
            if ($this->inRequest && preg_match('/\A\s*select\b/i', $sql) !== 1) {
                $this->attempts[] = 'sql-write';
                throw new LogicException('Protected request attempted a SQL write.');
            }
        });
        foreach ([
            'App\\Services\\Integrations\\ProviderHttpClient', 'App\\Services\\Integrations\\ProviderTransferManager',
            'App\\Services\\Nium\\NiumService', 'App\\Services\\Nium\\NiumTransferService',
            'App\\Services\\Nium\\NiumSupportedCorridorService', 'App\\Services\\Nium\\NiumBeneficiaryService',
            'App\\Services\\Nium\\NiumPayoutFxLockService', 'App\\Services\\Wallet\\LedgerService',
            'App\\Services\\Wallet\\TransferApprovalService',
        ] as $service) {
            $this->app->bind($service, function () use ($service): never {
                $this->attempts[] = $service;
                throw new LogicException('Protected request resolved a financial/provider service.');
            });
        }

        // Resolve the real HTTP kernel first so Laravel configures group aliases
        // and priority, then install ONLY in this fresh test application.
        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        $this->assertInstanceOf(Kernel::class, $kernel);
        $global = $kernel->getGlobalMiddleware();
        $priority = $kernel->getMiddlewarePriority();
        $kernel->prependMiddleware(ProtectDynamicTransferOptionsResponses::class);
        $this->assertSame(array_merge([ProtectDynamicTransferOptionsResponses::class], $global), $kernel->getGlobalMiddleware());
        $this->assertSame($priority, $kernel->getMiddlewarePriority());

        $policy = new DynamicTransferOptionsResponsePolicy;
        $handler = $this->app->make(ExceptionHandler::class);
        $handler->reportable(function (Throwable $exception): void { $this->reported[] = $exception::class; });
        $handler->respondUsing(function (Response $response, Throwable $exception, Request $request) use ($policy): Response {
            $this->finalizations++;

            return $policy->normalize($request, $response, $exception);
        });
    }

    protected function tearDown(): void
    {
        $this->inRequest = false;
        try {
            Http::assertNothingSent();
            Bus::assertNothingDispatched();
            Queue::assertNothingPushed();
            Mail::assertNothingOutgoing();
            Notification::assertNothingSent();
            $this->assertSame([], $this->attempts);
            $this->assertSame([], $this->authenticationEvents);
            $this->assertFalse(Auth::guard('web')->hasUser());
            $this->assertSame('75.00', DB::table('balances')->value('available_balance'));
            $this->assertSame('0.00', DB::table('balances')->value('reserved_balance'));
            $this->assertSame(1, DB::table('ledger_entries')->count());
            $this->assertCount(0, $this->app->make(DeferredCallbackCollection::class));
            $this->assertSame($this->applicationRoutes, $this->routeMap());
        } finally {
            DB::purge(self::CONNECTION);
            date_default_timezone_set($this->previousTimezone);
            parent::tearDown();
        }
    }

    private function createFixtures(): void
    {
        $schema = Schema::connection(self::CONNECTION);
        $schema->create('users', function (Blueprint $table): void {
            $table->id(); $table->string('full_name'); $table->string('kyc_status'); $table->string('status');
        });
        $schema->create('user_profiles', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('user_id'); $table->string('user_type')->nullable();
        });
        $schema->create('api_tokens', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('user_id'); $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->nullable(); $table->timestamp('last_used_at')->nullable(); $table->timestamps();
        });
        $schema->create('beneficiaries', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('user_id'); $table->unsignedBigInteger('provider_id');
            $table->string('country_code'); $table->string('currency'); $table->string('payout_method');
        });
        $schema->create('balances', function (Blueprint $table): void {
            $table->id(); $table->string('available_balance'); $table->string('reserved_balance');
        });
        $schema->create('ledger_entries', function (Blueprint $table): void { $table->id(); $table->string('amount'); });
        DB::table('users')->insert([
            ['id' => 101, 'full_name' => 'Synthetic owner', 'kyc_status' => 'pending', 'status' => 'active'],
            ['id' => 202, 'full_name' => 'Synthetic other', 'kyc_status' => 'verified', 'status' => 'active'],
        ]);
        DB::table('user_profiles')->insert(['user_id' => 101, 'user_type' => 'business']);
        DB::table('api_tokens')->insert([
            'user_id' => 101, 'token_hash' => hash('sha256', self::TOKEN), 'expires_at' => '2026-11-09 01:30:00',
            'last_used_at' => '2026-11-08 00:00:00', 'created_at' => '2026-11-08 00:00:00', 'updated_at' => '2026-11-08 00:00:00',
        ]);
        DB::table('beneficiaries')->insert([
            ['id' => 301, 'user_id' => 101, 'provider_id' => 7, 'country_code' => 'HK', 'currency' => 'USD', 'payout_method' => 'SWIFT'],
            ['id' => 302, 'user_id' => 202, 'provider_id' => 7, 'country_code' => 'HK', 'currency' => 'USD', 'payout_method' => 'SWIFT'],
            ['id' => 303, 'user_id' => 101, 'provider_id' => 8, 'country_code' => 'SG', 'currency' => 'SGD', 'payout_method' => 'LOCAL'],
        ]);
        DB::table('balances')->insert(['available_balance' => '75.00', 'reserved_balance' => '0.00']);
        DB::table('ledger_entries')->insert(['amount' => '75.00']);
    }

    private function registerAdapter(): void
    {
        // Exact future URL, registered in this test process only. Application
        // route declarations and the seven unprotected baseline tests are intact.
        Route::middleware(['api', AuthenticateReadOnlyOptionsToken::class, 'auth.user', 'profile.complete'])
            ->get(self::ROUTE, NiumDynamicTransferOptionsController::class)->where('user', '[1-9][0-9]*');
    }

    private function routeMap(): array
    {
        $map = [];
        foreach ($this->app->make(Router::class)->getRoutes() as $route) {
            if ($route->uri() === self::ROUTE || str_starts_with($route->uri(), 'api/_phase4a2bs1/')) {
                continue;
            }
            $map[] = [$route->uri(), $route->methods(), $route->getActionName(), $route->middleware()];
        }

        return $map;
    }

    private function request(string $method = 'GET', string $uri = self::URI, array $headers = []): TestResponse
    {
        $this->inRequest = true;
        $terminations = $this->terminations;
        $handled = $this->handled;
        try {
            $response = $this->call($method, $uri, [], [], [], $this->transformHeadersToServerVars(array_replace(['Accept' => 'application/json'], $headers)));
            $this->assertGreaterThan($terminations, $this->terminations);
            $this->assertGreaterThan($handled, $this->handled);
            $this->assertCount(0, $this->app->make(DeferredCallbackCollection::class));

            return $response;
        } finally {
            $this->inRequest = false;
        }
    }

    private function assertProtected(TestResponse $response): void
    {
        foreach (['private', 'no-store'] as $directive) {
            $this->assertTrue($response->headers->hasCacheControlDirective($directive));
        }
        foreach (['public', 'max-age', 's-maxage'] as $directive) {
            $this->assertFalse($response->headers->hasCacheControlDirective($directive));
        }
        foreach (['ETag', 'Last-Modified', 'Expires', 'X-Debug-Exception'] as $header) {
            $this->assertFalse($response->headers->has($header));
        }
        foreach (['synthetic-private-detail', '"trace"', '"exception"', '"file"', 'ALLOWED'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_maintenance_exceptions_templates_and_redirects_in_both_debug_modes(): void
    {
        $this->maintenanceActive = true;
        foreach ([false, true] as $debug) {
            config()->set('app.debug', $debug);
            $this->maintenanceData = ['status' => 503, 'retry' => 60, 'refresh' => 30];
            $exception = $this->request();
            $exception->assertStatus(503)->assertExactJson(['message' => 'Transfer options unavailable.'])
                ->assertHeader('Retry-After', '60')->assertHeader('Refresh', '30');
            $this->assertProtected($exception);
            $this->maintenanceData['template'] = '<pre>synthetic-private-detail</pre>';
            $template = $this->request();
            $template->assertStatus(503)->assertExactJson(['message' => 'Transfer options unavailable.']);
            $this->assertProtected($template);
            unset($this->maintenanceData['template']);
            $this->maintenanceData['redirect'] = '/maintenance';
            $redirect = $this->request();
            $redirect->assertStatus(302)->assertRedirect('/maintenance');
            $this->assertProtected($redirect);
        }
        $this->assertGreaterThan(0, $this->finalizations);
    }

    public function test_debug_404_and_405_keep_status_and_allow_without_diagnostics(): void
    {
        $this->registerAdapter();
        foreach ([false, true] as $debug) {
            config()->set('app.debug', $debug);
            $missing = $this->request('GET', '/api/user/users/01/dynamic-transfer-options');
            $missing->assertNotFound()->assertExactJson(['message' => 'Not found.']);
            $this->assertProtected($missing);
            $wrongMethod = $this->request('POST');
            $wrongMethod->assertStatus(405)->assertExactJson(['message' => 'Method not allowed.']);
            $this->assertStringContainsString('GET', $wrongMethod->headers->get('Allow'));
            $this->assertStringContainsString('HEAD', $wrongMethod->headers->get('Allow'));
            $this->assertProtected($wrongMethod);
        }
    }

    public function test_malformed_user_encoding_and_encoded_fixed_segments_are_protected_before_routing(): void
    {
        config()->set('app.debug', true);
        foreach ([
            ['/api/user/users/%FF/dynamic-transfer-options', 400],
            ['/api/user/users/%2F/dynamic-transfer-options', 404],
            ['/api/user/users//dynamic-transfer-options', 404],
            ['/api/%75ser/users/101/dynamic-transfer%2Doptions', 404],
        ] as [$uri, $status]) {
            $response = $this->request('GET', $uri);
            $response->assertStatus($status);
            $this->assertProtected($response);
        }
    }

    public function test_exception_finalizer_alone_redacts_both_formats_but_preserves_neighbor_behavior(): void
    {
        // Exercise B independently: no outer response middleware can mask a
        // broken finalizer in this test. All edits stay in this test kernel.
        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        $kernel->setGlobalMiddleware(array_values(array_filter($kernel->getGlobalMiddleware(),
            static fn (string $middleware): bool => $middleware !== ProtectDynamicTransferOptionsResponses::class)));
        $failure = static function (): never { throw new RuntimeException('synthetic-private-detail'); };
        Route::get(self::ROUTE, $failure);
        Route::get('api/_phase4a2bs1/neighbor', $failure);
        foreach ([false, true] as $debug) {
            config()->set('app.debug', $debug);
            foreach (['application/json', 'text/html'] as $accept) {
                $response = $this->request('GET', self::URI, ['Accept' => $accept]);
                $response->assertStatus(500)->assertExactJson(['message' => 'Transfer options unavailable.']);
                $this->assertProtected($response);
            }
        }
        $neighbor = $this->request('GET', '/api/_phase4a2bs1/neighbor');
        $neighbor->assertStatus(500)->assertJsonStructure(['exception', 'file', 'line', 'trace']);
        $this->assertFalse($neighbor->headers->hasCacheControlDirective('no-store'));
        $this->assertContains(RuntimeException::class, $this->reported);
        $this->assertGreaterThanOrEqual(5, $this->finalizations);
    }

    public function test_global_exception_before_routing_uses_real_reporting_and_finalization(): void
    {
        $this->app->instance(ValidatePostSize::class, new class
        {
            public function handle(Request $request, \Closure $next): never
            {
                throw new RuntimeException('synthetic-private-detail');
            }
        });
        foreach ([false, true] as $debug) {
            config()->set('app.debug', $debug);
            $response = $this->request();
            $response->assertStatus(500)->assertExactJson(['message' => 'Transfer options unavailable.']);
            $this->assertProtected($response);
        }
        $this->assertContains(RuntimeException::class, $this->reported);
        $this->assertGreaterThanOrEqual(2, $this->finalizations);
    }

    public function test_controller_exception_and_html_accept_are_redacted_in_both_debug_modes(): void
    {
        Route::get(self::ROUTE, static function (): never { throw new RuntimeException('synthetic-private-detail'); });
        foreach ([false, true] as $debug) {
            config()->set('app.debug', $debug);
            foreach (['application/json', 'text/html'] as $accept) {
                $response = $this->request('GET', self::URI, ['Accept' => $accept]);
                $response->assertStatus(500)->assertExactJson(['message' => 'Transfer options unavailable.']);
                $this->assertProtected($response);
            }
        }
        $this->assertContains(RuntimeException::class, $this->reported);
    }

    public function test_finalizer_handles_plain_symfony_custom_exception_responses_without_a_tag(): void
    {
        Route::get(self::ROUTE, static function (): never {
            throw new class('synthetic-private-detail') extends RuntimeException implements Responsable
            {
                public function toResponse($request)
                {
                    return new Response('synthetic-private-detail', 500, ['X-Debug-Exception' => 'private', 'ETag' => 'private']);
                }
            };
        });
        config()->set('app.debug', true);
        $response = $this->request();
        $response->assertStatus(500)->assertExactJson(['message' => 'Transfer options unavailable.']);
        $this->assertProtected($response);
        $this->assertGreaterThan(0, $this->finalizations);
    }

    public function test_allowed_and_denied_preflight_and_plain_options_are_protocol_only(): void
    {
        $this->registerAdapter();
        $this->maintenanceActive = true;
        foreach (['https://allowed.example', 'https://denied.example'] as $origin) {
            $response = $this->request('OPTIONS', self::URI, [
                'Origin' => $origin, 'Access-Control-Request-Method' => 'GET', 'Access-Control-Request-Headers' => 'Authorization',
            ]);
            $response->assertStatus(204)->assertContent('');
            if ($origin === 'https://allowed.example') {
                $response->assertHeader('Access-Control-Allow-Origin', $origin)->assertHeader('Access-Control-Max-Age', '0');
            } else {
                $response->assertHeaderMissing('Access-Control-Allow-Origin');
            }
            $this->assertContains('Origin', $response->baseResponse->getVary());
            $this->assertContains('Access-Control-Request-Method', $response->baseResponse->getVary());
            $this->assertProtected($response);
        }
        $this->maintenanceActive = false;
        $options = $this->request('OPTIONS');
        $options->assertOk()->assertContent('');
        $this->assertStringContainsString('GET', $options->headers->get('Allow'));
        $this->assertProtected($options);
    }

    public function test_adapter_auth_ownership_profile_beneficiary_and_unavailable_decisions_are_preserved(): void
    {
        $this->registerAdapter();
        $tokenBefore = (array) DB::table('api_tokens')->first();
        $headers = ['Authorization' => 'Bearer '.self::TOKEN];
        $missing = $this->request();
        $missing->assertUnauthorized();
        $this->assertProtected($missing);
        $foreign = $this->request('GET', '/api/user/users/202/dynamic-transfer-options', $headers);
        $foreign->assertForbidden();
        $this->assertProtected($foreign);
        foreach (['', '?beneficiary_id=301', '?beneficiary_id=303'] as $query) {
            $response = $this->request('GET', self::URI.$query, $headers);
            $response->assertOk()->assertExactJson(self::UNAVAILABLE);
            $this->assertProtected($response);
        }
        foreach (['302', '999'] as $beneficiary) {
            $response = $this->request('GET', self::URI.'?beneficiary_id='.$beneficiary, $headers);
            $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
            $this->assertProtected($response);
        }
        $invalid = $this->request('GET', self::URI.'?activation=VERIFIED', $headers);
        $invalid->assertStatus(422);
        $this->assertProtected($invalid);
        DB::table('user_profiles')->delete(); // Fixture changes are outside requests.
        $profile = $this->request('GET', self::URI, $headers);
        $profile->assertStatus(409)->assertExactJson([
            'message' => 'User profile must be completed before accessing this resource.', 'code' => 'PROFILE_REQUIRED',
        ]);
        $this->assertProtected($profile);
        $this->assertSame($tokenBefore, (array) DB::table('api_tokens')->first());
    }

    public function test_revocation_and_expiry_still_use_fresh_lookups(): void
    {
        $this->registerAdapter();
        $headers = ['Authorization' => 'Bearer '.self::TOKEN];
        $this->request('GET', self::URI, $headers)->assertOk()->assertExactJson(self::UNAVAILABLE);
        DB::table('api_tokens')->update(['expires_at' => '2026-11-09 00:30:00']);
        $expired = $this->request('GET', self::URI, $headers);
        $expired->assertUnauthorized();
        $this->assertProtected($expired);
        $this->assertSame(1, DB::table('api_tokens')->count());
        DB::table('api_tokens')->delete();
        $revoked = $this->request('GET', self::URI, $headers);
        $revoked->assertUnauthorized();
        $this->assertProtected($revoked);
    }

    public function test_conditional_headers_do_not_change_unavailable_or_head_responses_to_304(): void
    {
        $this->registerAdapter();
        foreach (['*', '"synthetic"', 'W/"synthetic"'] as $etag) {
            $headers = ['Authorization' => 'Bearer '.self::TOKEN, 'If-None-Match' => $etag,
                'If-Modified-Since' => 'Tue, 10 Nov 2026 00:00:00 GMT'];
            $response = $this->request('GET', self::URI, $headers);
            $response->assertOk()->assertExactJson(self::UNAVAILABLE);
            $this->assertProtected($response);
            $head = $this->request('HEAD', self::URI, $headers);
            $head->assertOk()->assertContent('');
            $this->assertProtected($head);
        }
    }

    public function test_synthetic_304_is_not_rewritten_to_200_and_validators_are_removed(): void
    {
        Route::get(self::ROUTE, static fn (): Response => new Response('', 304, [
            'ETag' => 'old', 'Last-Modified' => 'Mon, 09 Nov 2026 00:00:00 GMT',
            'Expires' => 'Tue, 10 Nov 2026 00:00:00 GMT', 'Cache-Control' => 'public, max-age=60',
        ]));
        $response = $this->request();
        $response->assertStatus(304)->assertContent('');
        $this->assertProtected($response);
    }

    public function test_downstream_cache_headers_are_removed_and_real_events_still_dispatch(): void
    {
        Route::get(self::ROUTE, function (): JsonResponse {
            Event::dispatch('phase4a2bs1.synthetic');

            return new JsonResponse(self::UNAVAILABLE, 200, [
                'Cache-Control' => 'public, max-age=600, s-maxage=900', 'ETag' => 'old',
                'Last-Modified' => 'Mon, 09 Nov 2026 00:00:00 GMT', 'Expires' => 'Tue, 10 Nov 2026 00:00:00 GMT',
                'X-Debug-Exception' => 'synthetic-private-detail',
            ]);
        });
        $response = $this->request();
        $response->assertOk()->assertExactJson(self::UNAVAILABLE);
        $this->assertProtected($response);
        $this->assertTrue($this->realEventObserved);
    }

    public function test_actual_resolved_adapter_order_and_existing_hk_metadata_remain_unchanged(): void
    {
        $this->registerAdapter();
        $this->request()->assertUnauthorized();
        $router = $this->app->make(Router::class);
        $stack = $router->gatherRouteMiddleware($router->getCurrentRoute());
        $required = [AuthenticateReadOnlyOptionsToken::class, SubstituteBindings::class,
            EnsureAuthenticatedUserMatchesRoute::class, EnsureUserProfileIsComplete::class];
        $this->assertSame($required, array_values(array_filter($stack, static fn ($class): bool => in_array($class, $required, true))));
        $this->assertNotContains('auth.token', $stack);
        $this->assertSame($this->applicationRoutes, $this->routeMap());
        $hk = array_values(array_filter($this->applicationRoutes,
            static fn ($row): bool => $row[0] === 'api/user/users/{user}/transfers' && in_array('POST', $row[1], true)));
        $this->assertCount(1, $hk);
        foreach (['auth.token', 'auth.user', 'profile.complete'] as $middleware) {
            $this->assertContains($middleware, $hk[0][3]);
        }
    }

    public function test_neighboring_paths_keep_their_existing_global_response_behavior(): void
    {
        $this->maintenanceActive = true;
        $this->maintenanceData['template'] = '<p>synthetic legacy maintenance</p>';
        foreach (['/api/user/users/101/transfers', '/api/user/users/101/transfers/review',
            '/api/user/users/101/transfers/5/submit', self::URI.'-export', self::URI.'/child'] as $uri) {
            $response = $this->request('GET', $uri);
            $response->assertStatus(503)->assertContent('<p>synthetic legacy maintenance</p>');
            $this->assertFalse($response->headers->hasCacheControlDirective('no-store'));
        }
    }
}
