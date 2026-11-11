<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Api\User\NiumDynamicTransferOptionsController;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\AuthenticateReadOnlyOptionsToken;
use App\Http\Middleware\EnsureAuthenticatedUserMatchesRoute;
use App\Http\Middleware\EnsureUserProfileIsComplete;
use App\Http\Middleware\ReadOnlyOptionsNoStore;
use App\Models\ApiToken;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Validated;
use Illuminate\Auth\RequestGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Routing\Middleware\SubstituteBindings;
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
use Tests\TestCase;

/**
 * Actual Laravel kernel requests against test-process routes and a dedicated
 * SQLite :memory: schema. No RefreshDatabase/Artisan/migrations or live data.
 * Fixture writes happen outside the guarded request/termination window only.
 * These tests have not been executed by Codex; an operator must run them.
 */
final class NiumDynamicTransferOptionsHttpAdapterTest extends TestCase
{
    private const CONNECTION = 'phase4a2a_http_offline';

    private const PREFIX = 'api/_phase4a2a/users/{user}';

    private const TOKEN = 'synthetic-read-only-http-token';

    private bool $inRequest = false;

    private array $queries = [];

    private array $writeAttempts = [];

    private array $runtimeAttempts = [];

    private array $authenticationEvents = [];

    private array $applicationRoutes = [];

    private int $terminations = 0;

    private string $previousTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue($this->app->environment('testing'), 'Only the testing environment is permitted.');
        $this->previousTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        config()->set('app.timezone', 'UTC');
        config()->set('app.debug', false);
        config()->set('auth.defaults.guard', 'web');
        config()->set('session.driver', 'array');
        config()->set('database.default', self::CONNECTION);
        config()->set('database.connections', [
            self::CONNECTION => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
        ]);
        $this->travelTo(Carbon::parse('2026-11-09T00:30:00Z'));
        $this->createOfflineSchema();
        $this->seedFixtures();
        $this->applicationRoutes = $this->routeMap();
        $this->registerTestRoutes();

        Http::preventStrayRequests();
        Http::fake();
        Bus::fake();
        Queue::fake();
        Notification::fake();
        Mail::fake();
        Log::spy();
        // Real dispatcher remains active. No Event::fake/withoutEvents.
        foreach ([Authenticated::class, Login::class, Validated::class] as $event) {
            Event::listen($event, function (object $event): void {
                if ($this->inRequest) {
                    $this->authenticationEvents[] = $event::class;
                }
            });
        }
        foreach ([
            'App\\Services\\Integrations\\ProviderHttpClient', 'App\\Services\\Integrations\\ProviderTransferManager',
            'App\\Services\\Nium\\NiumService', 'App\\Services\\Nium\\NiumTransferService',
            'App\\Services\\Nium\\NiumSupportedCorridorService', 'App\\Services\\Nium\\NiumBeneficiaryService',
            'App\\Services\\Nium\\NiumPayoutFxLockService', 'App\\Services\\Wallet\\LedgerService',
            'App\\Services\\Wallet\\TransferApprovalService',
        ] as $service) {
            $this->app->bind($service, function () use ($service): never {
                $this->runtimeAttempts[] = $service;
                throw new LogicException('A provider, transfer or wallet service was resolved.');
            });
        }
        DB::connection()->beforeExecuting(function (string $sql): void {
            if (! $this->inRequest) {
                return;
            }
            // Check BEFORE execution; query events alone miss failed write attempts.
            $this->queries[] = $sql; // Never retain credential-bearing bindings.
            if (preg_match('/\A\s*select\b/i', $sql) !== 1) {
                $this->writeAttempts[] = $sql;
                throw new LogicException('Read-only HTTP request attempted a database write.');
            }
        });
        $this->app->terminating(function (): void {
            if ($this->inRequest) {
                $this->terminations++;
            }
        });
    }

    protected function tearDown(): void
    {
        $this->inRequest = false;
        try {
            Http::assertNothingSent();
            Bus::assertNothingDispatched();
            Queue::assertNothingPushed();
            Notification::assertNothingSent();
            Mail::assertNothingOutgoing();
            $this->assertSame([], $this->writeAttempts);
            $this->assertSame([], $this->runtimeAttempts);
            $this->assertSame([], $this->authenticationEvents);
            $this->assertSame('75.00', DB::table('balances')->where('id', 1)->value('available_balance'));
            $this->assertSame('0.00', DB::table('balances')->where('id', 1)->value('reserved_balance'));
            $this->assertSame(1, DB::table('ledger_entries')->count());
            $this->assertCount(0, $this->app->make(DeferredCallbackCollection::class));
        } finally {
            DB::purge(self::CONNECTION);
            date_default_timezone_set($this->previousTimezone);
            parent::tearDown();
        }
    }

    private function createOfflineSchema(): void
    {
        $schema = Schema::connection(self::CONNECTION);
        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('full_name');
            $table->string('kyc_status');
            $table->string('status');
        });
        $schema->create('user_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('user_type')->nullable();
        });
        $schema->create('api_tokens', function (Blueprint $table): void {
            $table->id();
            // Deliberately allow an orphan fixture to test absent related User.
            $table->unsignedBigInteger('user_id');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
        $schema->create('beneficiaries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('provider_id');
            $table->string('country_code');
            $table->string('currency');
            $table->string('payout_method');
        });
        $schema->create('balances', function (Blueprint $table): void {
            $table->id();
            $table->string('available_balance');
            $table->string('reserved_balance');
        });
        $schema->create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('amount');
        });
    }

    private function seedFixtures(): void
    {
        DB::table('users')->insert([
            ['id' => 101, 'full_name' => 'Synthetic Owner', 'kyc_status' => 'pending', 'status' => 'active'],
            ['id' => 202, 'full_name' => 'Synthetic Other', 'kyc_status' => 'verified', 'status' => 'active'],
        ]);
        DB::table('user_profiles')->insert(['id' => 1, 'user_id' => 101, 'user_type' => 'business']);
        $this->insertToken();
        DB::table('beneficiaries')->insert([
            ['id' => 301, 'user_id' => 101, 'provider_id' => 7, 'country_code' => 'HK', 'currency' => 'USD', 'payout_method' => 'SWIFT'],
            ['id' => 302, 'user_id' => 202, 'provider_id' => 7, 'country_code' => 'HK', 'currency' => 'USD', 'payout_method' => 'SWIFT'],
            ['id' => 303, 'user_id' => 101, 'provider_id' => 8, 'country_code' => 'SG', 'currency' => 'SGD', 'payout_method' => 'LOCAL'],
        ]);
        DB::table('balances')->insert(['id' => 1, 'available_balance' => '75.00', 'reserved_balance' => '0.00']);
        DB::table('ledger_entries')->insert(['id' => 1, 'amount' => '75.00']);
    }

    private function insertToken(string $plain = self::TOKEN, array $changes = []): void
    {
        DB::table('api_tokens')->insert(array_replace([
            'user_id' => 101, 'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addHour()->format('Y-m-d H:i:s'),
            'last_used_at' => '2026-11-08 00:00:00', 'created_at' => '2026-11-08 00:00:00', 'updated_at' => '2026-11-08 00:00:00',
        ], $changes));
    }

    private function registerTestRoutes(): void
    {
        $middleware = ['api', AuthenticateReadOnlyOptionsToken::class, 'auth.user', 'profile.complete'];
        Route::middleware($middleware)->group(function (): void {
            // Registered only after this test application's normal route boot.
            Route::get(self::PREFIX.'/dynamic-transfer-options', NiumDynamicTransferOptionsController::class);
            Route::get(self::PREFIX.'/context', function (Request $request): JsonResponse {
                $guard = $request->attributes->get(AuthenticateReadOnlyOptionsToken::GUARD_ATTRIBUTE);

                return new JsonResponse([
                    'request_user' => $request->user()->id,
                    'guard_matches' => $guard instanceof RequestGuard && $guard->user() === $request->user(),
                    'token_matches' => $request->attributes->get('apiToken') instanceof ApiToken
                        && $request->attributes->get('apiToken')->user_id === $request->user()->id,
                ]);
            });
            Route::get(self::PREFIX.'/exception', static function (): never {
                throw new RuntimeException('synthetic-private-provider-detail');
            });
            Route::get(self::PREFIX.'/cached', static fn (): JsonResponse => new JsonResponse(['fixture' => true], 200,
                ['Cache-Control' => 'public, max-age=600', 'ETag' => 'synthetic-etag', 'Last-Modified' => 'Mon, 09 Nov 2026 00:00:00 GMT']));
            Route::get(self::PREFIX.'/binding/{selectedUser}', static fn (User $selectedUser): JsonResponse => new JsonResponse(['fixture' => true]));
        });
        Route::middleware(['api', ReadOnlyOptionsNoStore::class])
            ->get(self::PREFIX.'/without-authenticator', NiumDynamicTransferOptionsController::class);
    }

    private function routeMap(): array
    {
        $map = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/_phase4a2a/')) {
                continue;
            }
            $map[] = [$route->uri(), $route->methods(), $route->getActionName(), $route->middleware()];
        }

        return $map;
    }

    private function requestOptions(?string $plain = self::TOKEN, string $user = '101', string $query = '', array $body = []): TestResponse
    {
        return $this->requestPath('/api/_phase4a2a/users/'.$user.'/dynamic-transfer-options'.$query,
            $plain === null ? [] : ['Authorization' => 'Bearer '.$plain], $body);
    }

    private function requestPath(string $path, array $headers = [], array $body = []): TestResponse
    {
        $this->inRequest = true;
        $terminations = $this->terminations;
        try {
            // Laravel's test call includes Kernel::terminate before returning.
            $response = $this->json('GET', $path, $body, $headers);
            $this->assertGreaterThan($terminations, $this->terminations);
            $this->assertTrue($response->headers->hasCacheControlDirective('private'));
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
            $this->assertFalse($response->headers->hasCacheControlDirective('public'));
            $this->assertFalse($response->headers->hasCacheControlDirective('max-age'));
            $this->assertFalse($response->headers->has('ETag'));
            $this->assertFalse($response->headers->has('Last-Modified'));

            return $response;
        } finally {
            $this->inRequest = false;
        }
    }

    private function assertUnavailable(TestResponse $response): void
    {
        $response->assertOk()->assertExactJson(['options' => [], 'reason_codes' => ['trusted_snapshot_unavailable']]);
        $this->assertStringNotContainsString('ALLOWED', $response->getContent());
        $this->assertStringNotContainsString('COMPATIBLE', $response->getContent());
    }

    public function test_missing_invalid_and_malformed_credentials_are_401_even_with_an_ambient_user(): void
    {
        $this->actingAs(User::query()->findOrFail(202));
        foreach ([null, '', 'not-an-issued-token', self::TOKEN.' ', strtoupper(self::TOKEN)] as $token) {
            $this->requestOptions($token)->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        }
        foreach (['Basic '.self::TOKEN, 'Bearer '.self::TOKEN.',other', 'prefix Bearer '.self::TOKEN] as $header) {
            $this->requestPath('/api/_phase4a2a/users/101/dynamic-transfer-options', ['Authorization' => $header])->assertUnauthorized();
        }
    }

    public function test_expired_and_boundary_tokens_are_not_deleted_or_updated(): void
    {
        foreach (['2026-11-09 00:29:59', '2026-11-09 00:30:00'] as $expiry) {
            DB::table('api_tokens')->update(['expires_at' => $expiry]);
            $before = (array) DB::table('api_tokens')->first();
            $this->requestOptions()->assertUnauthorized();
            $this->assertSame($before, (array) DB::table('api_tokens')->first());
        }
    }

    public function test_null_expiry_is_explicitly_supported_without_usage_writes(): void
    {
        DB::table('api_tokens')->update(['expires_at' => null, 'last_used_at' => null]);
        $before = (array) DB::table('api_tokens')->first();
        $this->assertUnavailable($this->requestOptions());
        $this->assertSame($before, (array) DB::table('api_tokens')->first());
    }

    public function test_deleted_token_revocation_and_replacement_use_fresh_lookups_on_every_request(): void
    {
        $this->assertUnavailable($this->requestOptions());
        DB::table('api_tokens')->delete(); // Operator-run fixture revocation, outside request window.
        $this->requestOptions()->assertUnauthorized();
        $this->insertToken('synthetic-replacement-token');
        $this->requestOptions()->assertUnauthorized();
        $this->assertUnavailable($this->requestOptions('synthetic-replacement-token'));
        $tokenReads = array_filter($this->queries, static fn (string $sql): bool => str_contains($sql, '"api_tokens"'));
        $this->assertCount(4, $tokenReads);
    }

    public function test_orphaned_user_and_malformed_expiry_fail_closed(): void
    {
        DB::table('api_tokens')->update(['user_id' => 999]);
        $this->requestOptions()->assertUnauthorized();
        foreach (['', 'not-a-timestamp', '2026-02-30 01:00:00'] as $expiry) {
            DB::table('api_tokens')->update(['user_id' => 101, 'expires_at' => $expiry]);
            $this->requestOptions()->assertUnauthorized();
        }
    }

    public function test_request_local_context_is_preserved_without_mutating_the_session_guard(): void
    {
        $ambient = User::query()->findOrFail(202);
        $this->actingAs($ambient);
        $this->requestPath('/api/_phase4a2a/users/101/context', ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertOk()->assertExactJson(['request_user' => 101, 'guard_matches' => true, 'token_matches' => true]);
        $this->assertSame($ambient, Auth::guard()->getUser());
        $this->assertSame([], $this->authenticationEvents);
    }

    public function test_canonical_ownership_is_checked_before_profile_or_route_model_binding(): void
    {
        foreach (['202', '999', '0101', '101suffix', '101.0'] as $routeUser) {
            $this->requestOptions(user: $routeUser)->assertForbidden();
        }
        $this->requestPath('/api/_phase4a2a/users/101/binding/999')->assertUnauthorized();
        $this->requestPath('/api/_phase4a2a/users/101/binding/999', ['Authorization' => 'Bearer '.self::TOKEN])->assertNotFound();
    }

    public function test_existing_profile_409_and_kyc_shortcut_are_preserved(): void
    {
        DB::table('user_profiles')->delete();
        $this->requestOptions()->assertStatus(409)->assertJsonPath('code', 'PROFILE_REQUIRED');
        DB::table('users')->where('id', 101)->update(['kyc_status' => 'VERIFIED']);
        $this->assertUnavailable($this->requestOptions());
    }

    public function test_profile_with_blank_type_is_incomplete(): void
    {
        DB::table('user_profiles')->update(['user_type' => '  ']);
        $this->requestOptions()->assertStatus(409)->assertJsonPath('code', 'PROFILE_REQUIRED');
    }

    public function test_missing_trusted_source_is_unavailable_without_implicit_beneficiary_selection(): void
    {
        $this->assertUnavailable($this->requestOptions());
        $beneficiaryReads = array_filter($this->queries, static fn (string $sql): bool => str_contains($sql, 'beneficiaries'));
        $this->assertSame([], $beneficiaryReads);
    }

    public function test_owned_beneficiary_selection_stops_at_ownership_for_both_hk_and_sgd(): void
    {
        foreach (['301', '303'] as $id) {
            $this->assertUnavailable($this->requestOptions(query: '?beneficiary_id='.$id));
        }
        // Different provider IDs do not become an invented account binding.
        $this->assertCount(2, array_filter($this->queries, static fn (string $sql): bool => str_contains($sql, 'beneficiaries')));
    }

    public function test_foreign_and_missing_beneficiaries_have_identical_generic_404(): void
    {
        $foreign = $this->requestOptions(query: '?beneficiary_id=302');
        $missing = $this->requestOptions(query: '?beneficiary_id=999');
        $foreign->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        $missing->assertNotFound()->assertExactJson($foreign->json());
    }

    public function test_malformed_selectors_cannot_be_normalized_into_accepted_ids(): void
    {
        foreach (['', '0', '-1', '0301', '301.0', '301suffix', '%20301%20', '+301', '999999999999999999999999999'] as $value) {
            $this->requestOptions(query: '?beneficiary_id='.$value)->assertUnprocessable()->assertExactJson(['message' => 'Invalid transfer options request.']);
        }
        foreach (['?beneficiary_id[]=301', '?beneficiary_id=301&beneficiary_id=302', '?beneficiary_id'] as $query) {
            $this->requestOptions(query: $query)->assertUnprocessable();
        }
    }

    public function test_request_supplied_evidence_and_scope_assertions_are_rejected(): void
    {
        foreach (['?evidence=VERIFIED', '?entitlement=VERIFIED', '?scope[customer]=private', '?environment=production', '?destination_currency=SGD'] as $query) {
            $this->requestOptions(query: $query)->assertUnprocessable();
        }
        $this->requestOptions(body: ['evidence' => ['state' => 'VERIFIED']])->assertUnprocessable();
        $this->requestOptions(body: ['entitlement' => true, 'activation' => true])->assertUnprocessable();
    }

    public function test_controller_rejects_ambient_context_without_the_dedicated_authenticator(): void
    {
        $this->actingAs(User::query()->findOrFail(101));
        $this->requestPath('/api/_phase4a2a/users/101/without-authenticator')->assertUnauthorized();
    }

    public function test_no_store_overrides_cache_headers_and_conditional_requests_do_not_return_304(): void
    {
        $this->requestPath('/api/_phase4a2a/users/101/cached', ['Authorization' => 'Bearer '.self::TOKEN])->assertOk();
        $this->assertUnavailable($this->requestPath('/api/_phase4a2a/users/101/dynamic-transfer-options',
            ['Authorization' => 'Bearer '.self::TOKEN, 'If-None-Match' => 'synthetic-etag']));
    }

    public function test_exception_and_lookup_failure_paths_are_no_store_and_redacted(): void
    {
        config()->set('app.debug', true);
        $response = $this->requestPath('/api/_phase4a2a/users/101/exception', ['Authorization' => 'Bearer '.self::TOKEN]);
        $response->assertStatus(500)->assertExactJson(['message' => 'Transfer options unavailable.']);
        $this->assertStringNotContainsString('synthetic-private-provider-detail', $response->getContent());
        $response = $this->requestPath('/api/_phase4a2a/users/101/binding/999', ['Authorization' => 'Bearer '.self::TOKEN]);
        $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    }

    public function test_authentication_lookup_exception_is_not_reported_with_token_hash_bindings(): void
    {
        DB::connection()->beforeExecuting(function (string $sql): void {
            if ($this->inRequest && str_contains($sql, '"api_tokens"')) {
                throw new RuntimeException('synthetic-private-token-lookup-detail');
            }
        });
        $this->requestOptions()->assertStatus(500)->assertExactJson(['message' => 'Transfer options unavailable.']);
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('log');
    }

    public function test_response_contains_no_token_identifiers_provenance_or_customer_details(): void
    {
        foreach ([$this->requestOptions(), $this->requestOptions(null), $this->requestOptions(query: '?beneficiary_id=302')] as $response) {
            foreach ([self::TOKEN, hash('sha256', self::TOKEN), 'Synthetic Owner', 'Synthetic Other', 'user_id', 'customer', 'account', 'wallet', 'provenance', '301', '302'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
        }
    }

    public function test_actual_middleware_sorting_places_the_wrapped_authenticator_before_bindings(): void
    {
        // TestCase boots the console kernel; an HTTP request initializes the
        // router's real middleware groups, aliases and priority through the HTTP kernel.
        $this->assertUnavailable($this->requestOptions());
        $router = $this->app->make(Router::class);
        $route = $router->getCurrentRoute();
        $this->assertNotNull($route);
        $stack = $router->gatherRouteMiddleware($route);
        $required = [
            AuthenticateReadOnlyOptionsToken::class, SubstituteBindings::class,
            EnsureAuthenticatedUserMatchesRoute::class, EnsureUserProfileIsComplete::class,
        ];
        // Keep every required entry and its relative position (including any
        // duplicates), allowing unrelated framework middleware elsewhere in the stack.
        $securityStack = array_values(array_filter($stack,
            static fn ($middleware): bool => in_array($middleware, $required, true)));
        $this->assertSame($required, $securityStack,
            'Unexpected resolved security middleware order: inspect before enabling any application route.');
        foreach (['api', 'auth.user', 'profile.complete', 'auth.token'] as $unresolved) {
            $this->assertNotContains($unresolved, $stack);
        }
        $this->assertNotContains(AuthenticateApiToken::class, $stack);
    }

    public function test_application_routes_and_existing_hk_transfer_route_metadata_are_unchanged(): void
    {
        $this->assertSame($this->applicationRoutes, $this->routeMap());
        $transferRoutes = [];
        foreach ($this->applicationRoutes as [$uri, $methods, $action, $middleware]) {
            $this->assertNotSame('api/user/users/{user}/dynamic-transfer-options', $uri);
            $this->assertStringNotContainsString(NiumDynamicTransferOptionsController::class, $action);
            $this->assertNotContains(AuthenticateReadOnlyOptionsToken::class, $middleware);
            if ($uri === 'api/user/users/{user}/transfers' && in_array('POST', $methods, true)) {
                $transferRoutes[] = $uri;
                $this->assertContains('auth.token', $middleware);
                $this->assertContains('auth.user', $middleware);
                $this->assertContains('profile.complete', $middleware);
            }
        }
        $this->assertCount(1, $transferRoutes);
        // This verifies unchanged route metadata, not a replay of live HK payouts.
    }
}
