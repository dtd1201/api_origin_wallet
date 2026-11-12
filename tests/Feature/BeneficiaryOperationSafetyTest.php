<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Models\Beneficiary;
use App\Models\BeneficiaryOperation;
use App\Models\IntegrationProvider;
use App\Models\NiumApiExchangeEvidence;
use App\Models\User;
use App\Services\Integrations\BeneficiaryOperationConflict;
use App\Services\Integrations\BeneficiaryOperationService;
use App\Services\Integrations\ProviderBeneficiaryManager;
use App\Services\Nium\NiumBeneficiaryService;
use App\Services\Nium\NiumBeneficiaryWriteException;
use App\Services\Wallet\Concurrency\OwnerLockContractViolation;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\OwnerFirstPostgresDatabase;
use Tests\Support\PostgresLockRace;
use Tests\TestCase;

final class BeneficiaryOperationSafetyTest extends TestCase
{
    use OwnerFirstPostgresDatabase;

    private User $owner;
    private IntegrationProvider $provider;
    private string $token;
    private Closure $httpHandler;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->fakeHttp(fn (Request $request) => $this->response($request));
        // Factory::fake() appends callbacks. Register one bridge per test so
        // scenario/worker replacements neither compete nor reset recordings.
        Http::fake(function (Request $request) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            return ($this->httpHandler)($request);
        });
        config(['services.nium.base_url' => 'https://beneficiary.nium.test',
            'services.nium.client_id' => 'fixture-client',
            'services.nium.webhook' => ['static_header_name' => 'x-partner-key', 'static_header_value' => 'fixture-partner'],
            'services.nium.health_endpoint' => '/api/v1/client/{clientHashId}',
            'services.nium.customer_create_endpoint' => '/api/v1/client/{clientHashId}/customer',
            'services.nium.customer_list_endpoint' => '/api/v1/client/{clientHashId}/customers',
            'services.nium.customer_get_endpoint' => '/api/v1/client/{clientHashId}/customer/{customerHashId}',
            'services.nium.auth' => ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'fixture-key'],
            'services.nium.beneficiary_endpoint' => '/beneficiaries',
            'services.nium.beneficiary_update_endpoint' => '/beneficiaries/{beneficiaryHashId}',
            'services.nium.beneficiary_delete_endpoint' => '/beneficiaries/{beneficiaryHashId}',
            'services.nium.beneficiary_update_enabled' => true,
            'services.nium.beneficiary_delete_enabled' => true,
            'services.nium.supported_corridors' => [['destinationCountry' => 'HK', 'destinationCurrency' => 'USD',
                'payoutMethod' => 'SWIFT', 'beneficiaryAccountType' => 'CORPORATE', 'customerType' => 'CORPORATE',
                'routingCodeType' => ['SWIFT']]],
        ]);
        $this->provider = IntegrationProvider::query()->create(['code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        $this->owner = User::factory()->create(['kyc_status' => 'verified']);
        $this->owner->profile()->create(['user_type' => 'business', 'country_code' => 'HK']);
        $this->owner->kycProfile()->create(['status' => 'approved', 'applicant_type' => 'business',
            'legal_name' => 'Fixture Customer', 'registered_country_code' => 'HK', 'country_code' => 'HK',
            'address_line1' => '1 Fixture Road', 'city' => 'Hong Kong', 'metadata' => ['nium_region' => 'HK']]);
        $this->owner->providerAccounts()->create(['provider_id' => $this->provider->id,
            'external_customer_id' => 'fixture-customer', 'external_account_id' => 'fixture-wallet',
            'status' => 'active', 'provider_status' => 'clear', 'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(), 'provider_ids_verified_at' => now()]);
        $this->token = Str::random(80);
        ApiToken::query()->create(['user_id' => $this->owner->id, 'name' => 'fixture-beneficiary',
            'token_hash' => hash('sha256', $this->token), 'expires_at' => now()->addDay()]);
    }

    private function payload(): array
    {
        return ['provider_id' => $this->provider->id, 'beneficiary_type' => 'business',
            'full_name' => 'Fixture Beneficiary', 'country_code' => 'HK', 'currency' => 'USD',
            'bank_name' => 'Fixture Bank', 'account_number' => '123456789', 'swift_bic' => 'HKBCHKHH',
            'address_line1' => '1 Fixture Road', 'city' => 'Hong Kong', 'postal_code' => '999077',
            'raw_data' => ['nium' => ['payoutMethod' => 'SWIFT']]];
    }

    private function response(Request $request): mixed
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        return $request->method() === 'GET' ? Http::response(['fields' => [
            ['fieldName' => 'beneficiaryAccountNumber', 'required' => true, 'type' => 'string'],
        ]])
            : Http::response(['beneficiaryHashId' => 'fixture-recipient', 'status' => 'ACTIVE']);
    }

    private function fakeHttp(Closure $handler): void
    {
        $this->httpHandler = $handler;
    }

    private function create(): Beneficiary
    {
        return app(ProviderBeneficiaryManager::class)->createForUser($this->provider, $this->owner, $this->payload());
    }

    public function test_identical_controller_creates_are_independent_and_commit_each_claim_before_http(): void
    {
        config(['database.connections.beneficiary_observer' => config('database.connections.'.DB::getDefaultConnection())]);
        $this->fakeHttp(function (Request $request) {
            if ($request->method() === 'POST') {
                $observer = DB::connection('beneficiary_observer');
                $claim = $observer->table('beneficiary_operations')->where('request_id', $request->header('x-request-id')[0])->sole();
                $this->assertSame('running', $claim->state);
                $this->assertTrue(Str::isUuid($claim->create_key));
                $this->assertSame($claim->request_id, $request->header('x-request-id')[0]);
                $this->assertSame(['create' => true], json_decode($claim->dispatch_steps, true));
                $this->assertNotSame(DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
                    $observer->selectOne('SELECT pg_backend_pid() AS pid')->pid);
                $this->response($request); // Includes the Laravel/PDO transaction assertions.
                return Http::response(['beneficiaryHashId' => 'recipient-'.$claim->request_id, 'status' => 'ACTIVE']);
            }
            return $this->response($request);
        });
        try {
            $url = '/api/user/users/'.$this->owner->id.'/beneficiaries';
            $first = $this->withToken($this->token)->postJson($url, $this->payload())->assertCreated()
                ->assertJsonPath('is_registered', true)->assertJsonPath('status', 'active');
            $second = $this->postJson($url, $this->payload())->assertCreated()->assertJsonPath('status', 'active');
            $this->assertNotSame($first->json('id'), $second->json('id'));
            $operations = BeneficiaryOperation::query()->get();
            $this->assertCount(2, $operations);
            foreach (['id', 'create_key', 'request_id', 'verification_request_id'] as $identity) {
                $this->assertCount(2, $operations->pluck($identity)->unique());
            }
            $this->assertCount(1, $operations->pluck('request_hash')->unique());
            foreach ($operations as $operation) {
                $this->assertSame('succeeded', $operation->state);
                $this->assertNull($operation->active_beneficiary_id);
                $expected = 'recipient-'.$operation->request_id;
                $this->assertSame($expected, $operation->receipt['attributes']['external_beneficiary_id']);
                $this->assertSame($expected, Beneficiary::query()->findOrFail($operation->beneficiary_id)->external_beneficiary_id);
            }
            $this->assertCount(2, Http::recorded(fn (Request $request) => $request->method() === 'POST'));
            $this->assertSame(2, ApiRequestLog::query()->where('operation', 'beneficiary_create')->count());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        } finally {
            DB::purge('beneficiary_observer');
        }
    }

    public static function concurrentCreates(): array
    {
        return ['unkeyed identical request' => [false, false],
            'unkeyed different payload' => [false, true],
            'same persisted beneficiary' => [true, false],
            'same beneficiary changed payload' => [true, true]];
    }

    #[DataProvider('concurrentCreates')]
    public function test_running_create_fences_competing_requests_using_real_postgres_sessions(bool $persisted, bool $changed): void
    {
        $beneficiary = $persisted ? $this->owner->beneficiaries()->create([...$this->payload(), 'status' => 'pending']) : null;
        $race = (new PostgresLockRace)->run(
            function (PostgresLockRace $race) use ($beneficiary): int {
                $race->pauseAfterLock('users');
                $this->fakeHttp(function (Request $request) use ($race) {
                    if ($request->method() === 'POST') {
                        $operation = BeneficiaryOperation::query()->sole();
                        $race->recordWire($operation->request_id);
                        $race->signal('provider-entered', $operation->request_id);
                        $race->await('competitor-done', 1);
                        $race->await('wire-release');
                    }
                    return $this->response($request);
                });
                return ($beneficiary === null ? $this->create()
                    : app(ProviderBeneficiaryManager::class)->createBeneficiary($this->provider, $beneficiary))->id;
            },
            function (PostgresLockRace $race) use ($beneficiary, $changed): string {
                $race->await('locked', 0);
                $this->fakeHttp(function (Request $request) use ($race) {
                    if ($request->method() !== 'GET') {
                        $race->recordWire('unexpected-competing-post');
                        throw new RuntimeException('Duplicate provider dispatch.');
                    }
                    return $this->response($request);
                });
                try {
                    if ($beneficiary !== null) {
                        if ($changed) $beneficiary->email = 'different@example.test';
                        app(ProviderBeneficiaryManager::class)->createBeneficiary($this->provider, $beneficiary);
                    } else {
                        $payload = $this->payload();
                        if ($changed) $payload['email'] = 'different@example.test';
                        app(ProviderBeneficiaryManager::class)->createForUser($this->provider, $this->owner, $payload);
                    }
                    throw new RuntimeException('The running create claim was ignored.');
                } catch (BeneficiaryOperationConflict) {
                    return 'conflict';
                } finally {
                    $race->signal('competitor-done');
                }
            },
            function (PostgresLockRace $race, Connection $observer, array $pids): void {
                $race->await('locked', 0);
                $wait = $race->waitForBlock($observer, $pids[1], $pids[0]);
                $this->assertStringContainsString('users', $wait['query']);
                $race->signal('continue');
                $key = $race->await('provider-entered', 0);
                $claim = $observer->table('beneficiary_operations')->sole();
                $this->assertSame('running', $claim->state);
                $this->assertSame($key, $claim->request_id);
                $this->assertSame(['create' => true], json_decode($claim->dispatch_steps, true));
                $race->signal('wire-release');
            },
        );
        $this->assertCleanRace($race);
        $this->assertSame('conflict', $race['workers'][1]['value']);
        $this->assertCount(1, $race['wire']);
        $this->assertSame(BeneficiaryOperation::query()->sole()->request_id, $race['wire'][0]['key']);
        $this->assertSame(1, Beneficiary::query()->count());
        $this->assertSame('succeeded', BeneficiaryOperation::query()->sole()->state);
    }

    public static function ambiguousResponses(): array
    {
        return ['timeout' => ['timeout'], 'HTTP failure' => ['500'],
            'provider rejection' => ['422'], 'missing identifier' => ['missing']];
    }

    public function test_delete_then_identical_readd_creates_a_new_operation_and_retains_prior_evidence(): void
    {
        $this->fakeHttp(function (Request $request) {
            $response = $this->response($request);
            return $request->method() === 'POST'
                ? Http::response(['beneficiaryHashId' => 'recipient-'.$request->header('x-request-id')[0], 'status' => 'ACTIVE'])
                : $response;
        });
        $url = '/api/user/users/'.$this->owner->id.'/beneficiaries';
        $first = $this->withToken($this->token)->postJson($url, $this->payload())->assertCreated();
        $original = BeneficiaryOperation::query()->sole();
        $receipt = $original->getRawOriginal('receipt');
        $this->deleteJson($url.'/'.$first->json('id'))->assertNoContent();
        $this->assertNull(Beneficiary::query()->find($first->json('id')));
        $second = $this->postJson($url, $this->payload())->assertCreated();
        $this->assertNotSame($first->json('id'), $second->json('id'));
        $created = BeneficiaryOperation::query()->where('action', 'create')->get();
        $this->assertCount(2, $created);
        foreach (['id', 'create_key', 'request_id'] as $identity) $this->assertCount(2, $created->pluck($identity)->unique());
        $this->assertCount(1, $created->pluck('request_hash')->unique());
        $this->assertSame($receipt, $original->fresh()->getRawOriginal('receipt'));
        $this->assertSame(['succeeded', 'succeeded', 'succeeded'], BeneficiaryOperation::query()->pluck('state')->all());
        $this->assertCount(2, Http::recorded(fn (Request $request) => $request->method() === 'POST'));
        $this->assertCount(1, Http::recorded(fn (Request $request) => $request->method() === 'DELETE'));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_concurrent_retries_of_an_explicit_persisted_operation_cannot_redispatch(): void
    {
        $this->fakeHttp(function (Request $request) {
            if ($request->method() === 'GET') return $this->response($request);
            $this->response($request);
            throw new ConnectionException('fixture uncertain dispatch');
        });
        try { $this->create(); $this->fail('The timeout was accepted.'); } catch (ConnectionException) {}
        $operation = BeneficiaryOperation::query()->sole();
        $beneficiary = Beneficiary::query()->sole();
        $identity = $operation->only(['id', 'create_key', 'request_id', 'verification_request_id', 'request_hash']);
        $this->assertSame('ambiguous', $operation->state);
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'beneficiary_create')->count());

        // Internal callers can name the existing persisted operation. This does
        // not introduce or simulate a client operation-key contract on POST.
        $retry = function (PostgresLockRace $race, int $worker) use ($operation, $beneficiary): string {
            $this->fakeHttp(function (Request $request) use ($race) {
                $race->recordWire('unexpected-retry');
                throw new RuntimeException('An ambiguous operation reached provider HTTP.');
            });
            $snapshot = $beneficiary->fresh();
            $snapshot->operationContext = $operation->fresh();
            $race->signal('retry-ready-'.$worker);
            $race->await('retry-release');
            try {
                app(ProviderBeneficiaryManager::class)->createBeneficiary($this->provider, $snapshot);
                throw new RuntimeException('The ambiguous operation was reused.');
            } catch (BeneficiaryOperationConflict) {
                return $snapshot->operationContext->id;
            }
        };
        $race = (new PostgresLockRace)->run(
            fn (PostgresLockRace $race) => $retry($race, 0),
            fn (PostgresLockRace $race) => $retry($race, 1),
            function (PostgresLockRace $race, Connection $observer, array $pids): void {
                $race->await('retry-ready-0', 0);
                $race->await('retry-ready-1', 1);
                $race->signal('retry-release');
            },
        );
        $this->assertCleanRace($race);
        $this->assertSame([$operation->id, $operation->id], array_column($race['workers'], 'value'));
        $this->assertSame([], $race['wire']);
        $this->assertSame($identity, $operation->fresh()->only(array_keys($identity)));
        $this->assertSame(['create' => true], $operation->fresh()->dispatch_steps);
        $this->assertSame($beneficiary->id, $operation->fresh()->active_beneficiary_id);
        $this->assertSame(1, BeneficiaryOperation::query()->count());
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'beneficiary_create')->count());
    }

    public function test_an_existing_operation_identity_cannot_be_reused_with_a_different_payload(): void
    {
        $beneficiary = $this->create();
        $operation = BeneficiaryOperation::query()->sole();
        $identity = $operation->only(['id', 'create_key', 'request_id', 'request_hash']);
        $snapshot = $beneficiary->fresh();
        $snapshot->operationContext = $operation;
        $snapshot->fill(['full_name' => 'A different beneficiary', 'account_number' => '999999999']);
        $sent = count(Http::recorded());
        try {
            app(ProviderBeneficiaryManager::class)->createBeneficiary($this->provider, $snapshot);
            $this->fail('A completed operation identity was reused.');
        } catch (BeneficiaryOperationConflict) {}
        $this->assertCount($sent, Http::recorded());
        $this->assertSame($identity, $operation->fresh()->only(array_keys($identity)));
        $this->assertSame($this->payload()['full_name'], $beneficiary->fresh()->full_name);
        $this->assertSame($this->payload()['account_number'], $beneficiary->fresh()->account_number);
        $this->assertSame(1, BeneficiaryOperation::query()->count());
    }

    public function test_old_payload_hash_claim_blocks_a_different_unkeyed_create_without_rewriting_history(): void
    {
        $this->fakeHttp(fn (Request $request) => $request->method() === 'GET'
            ? $this->response($request) : Http::response([], 503));
        try { $this->create(); $this->fail('The failed dispatch was accepted.'); } catch (RuntimeException) {}
        $operation = BeneficiaryOperation::query()->sole();
        $operation->update(['create_key' => hash('sha256', 'old-step-280-payload-key')]);
        $history = $operation->fresh()->getAttributes();
        $sent = count(Http::recorded());
        try {
            app(ProviderBeneficiaryManager::class)->createForUser($this->provider, $this->owner,
                [...$this->payload(), 'account_number' => '999999999']);
            $this->fail('A different payload bypassed an older ambiguous Create.');
        } catch (BeneficiaryOperationConflict) {}
        $this->assertSame($history, $operation->fresh()->getAttributes());
        $this->assertSame(1, BeneficiaryOperation::query()->count());
        $this->assertSame(1, Beneficiary::query()->count());
        $this->assertCount($sent, Http::recorded());
    }

    public function test_unkeyed_create_cannot_bypass_an_ambiguous_create_on_update(): void
    {
        $beneficiary = $this->owner->beneficiaries()->create([...$this->payload(), 'status' => 'pending']);
        $this->fakeHttp(fn (Request $request) => $request->method() === 'GET'
            ? $this->response($request) : Http::response([], 503));
        try {
            app(ProviderBeneficiaryManager::class)->updateBeneficiary($this->provider, $beneficiary);
            $this->fail('The failed Create-on-Update was accepted.');
        } catch (RuntimeException) {}
        $operation = BeneficiaryOperation::query()->sole();
        $this->assertSame('update', $operation->action);
        $this->assertSame('ambiguous', $operation->state);
        $this->assertSame(['create' => true], $operation->dispatch_steps);
        $sent = count(Http::recorded());
        try { $this->create(); $this->fail('Create-on-Update ambiguity was bypassed.'); } catch (BeneficiaryOperationConflict) {}
        $this->assertCount($sent, Http::recorded());
        $this->assertSame(1, BeneficiaryOperation::query()->count());
    }

    public function test_rollback_is_forbidden_even_before_any_operation_is_recorded(): void
    {
        $migration = require database_path('migrations/2026_11_12_000003_create_beneficiary_operations.php');
        try {
            $migration->down();
            $this->fail('The operation safety table was dropped.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('rollback is forbidden', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable('beneficiary_operations'));
        $this->assertSame(0, BeneficiaryOperation::query()->count());
        Http::assertNothingSent();
    }

    public function test_rollback_preserves_successful_and_ambiguous_operation_history(): void
    {
        $this->create();
        $this->fakeHttp(fn (Request $request) => $request->method() === 'GET'
            ? $this->response($request) : Http::response([], 503));
        try { $this->create(); $this->fail('The failed dispatch was accepted.'); } catch (RuntimeException) {}
        $this->assertSame(['ambiguous', 'succeeded'], BeneficiaryOperation::query()->orderBy('state')->pluck('state')->all());
        $history = DB::table('beneficiary_operations')->orderBy('id')->get()->toArray();
        $evidence = DB::table('nium_api_exchange_evidence')->orderBy('id')->get()->toArray();
        $this->assertNotEmpty($evidence);
        $migration = require database_path('migrations/2026_11_12_000003_create_beneficiary_operations.php');
        try {
            $migration->down();
            $this->fail('Durable operation history was dropped.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('rollback is forbidden', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable('beneficiary_operations'));
        $this->assertEquals($history, DB::table('beneficiary_operations')->orderBy('id')->get()->toArray());
        $this->assertEquals($evidence, DB::table('nium_api_exchange_evidence')->orderBy('id')->get()->toArray());
    }

    public static function competingChanges(): array
    {
        return ['competing update' => ['update'], 'competing delete' => ['delete']];
    }

    #[DataProvider('competingChanges')]
    public function test_update_claim_fences_concurrent_update_or_delete(string $action): void
    {
        $beneficiary = $this->create();
        $race = (new PostgresLockRace)->run(
            function (PostgresLockRace $race) use ($beneficiary): string {
                $race->pauseAfterLock('users');
                $this->fakeHttp(function (Request $request) use ($race) {
                    if ($request->method() === 'PUT') {
                        $claim = BeneficiaryOperation::query()->whereNotNull('active_beneficiary_id')->sole();
                        $race->recordWire($claim->request_id);
                        $race->await('competitor-done', 1);
                    }
                    return $this->response($request);
                });
                return app(ProviderBeneficiaryManager::class)->updateForUser($this->provider, $beneficiary,
                    fn (Beneficiary $snapshot) => $snapshot->fill(['email' => 'winner@example.test']))->email;
            },
            function (PostgresLockRace $race) use ($beneficiary, $action): string {
                $race->await('locked', 0);
                $this->fakeHttp(function (Request $request) use ($race) {
                    $race->recordWire('unexpected-competing-write');
                    throw new RuntimeException('Competing operation reached provider HTTP.');
                });
                try {
                    $manager = app(ProviderBeneficiaryManager::class);
                    if ($action === 'update') $manager->updateBeneficiary($this->provider, $beneficiary);
                    else $manager->deleteBeneficiary($this->provider, $beneficiary);
                    throw new RuntimeException('The active update claim was ignored.');
                } catch (BeneficiaryOperationConflict) {
                    return 'conflict';
                } finally {
                    $race->signal('competitor-done');
                }
            },
            function (PostgresLockRace $race, Connection $observer, array $pids): void {
                $race->await('locked', 0);
                $this->assertStringContainsString('users', $race->waitForBlock($observer, $pids[1], $pids[0])['query']);
                $race->signal('continue');
            },
        );
        $this->assertCleanRace($race);
        $this->assertSame('winner@example.test', $race['workers'][0]['value']);
        $this->assertSame('conflict', $race['workers'][1]['value']);
        $this->assertCount(1, $race['wire']);
        $this->assertSame('winner@example.test', $beneficiary->fresh()->email);
        $this->assertSame('active', $beneficiary->fresh()->status);
        $this->assertSame(2, BeneficiaryOperation::query()->where('state', 'succeeded')->count());
    }

    public static function legacyProviders(): array
    {
        return array_combine(['wise', 'airwallex', 'currenxie', 'pingpong', 'tazapay', 'unlimit'],
            array_map(fn ($code) => [$code], ['wise', 'airwallex', 'currenxie', 'pingpong', 'tazapay', 'unlimit']));
    }

    #[DataProvider('legacyProviders')]
    public function test_legacy_adapters_preserve_their_create_update_and_delete_behavior(string $code): void
    {
        $class = 'App\\Services\\'.ucfirst($code).'\\'.ucfirst($code).'BeneficiaryService';
        $class = match ($code) {
            'airwallex' => \App\Services\Airwallex\AirwallexBeneficiaryService::class,
            'pingpong' => \App\Services\PingPong\PingPongBeneficiaryService::class,
            default => $class,
        };
        $provider = IntegrationProvider::query()->create(['code' => $code, 'name' => $code, 'status' => 'active']);
        $this->owner->providerAccounts()->create(['provider_id' => $provider->id, 'external_customer_id' => '12345',
            'status' => 'active', 'metadata' => ['client_id' => 'fixture-client']]);
        config(['integrations.providers.'.$code.'.beneficiary' => $class,
            'services.'.$code.'.base_url' => 'https://'.$code.'.beneficiary.test',
            'services.'.$code.'.auth' => ['mode' => 'none']]);
        $creates = 0;
        $this->fakeHttp(function (Request $request) use ($code, &$creates) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $creates++;
            if ($code === 'pingpong') return Http::response(['code' => 'SUCCESS', 'data' => ['biz_id' => 'fixture-recipient', 'status' => 'AVAILABLE']]);
            if ($code === 'tazapay') return Http::response(['data' => ['id' => 'fixture-recipient', 'status' => 'active']]);
            return Http::response(['id' => $code === 'wise' ? (string) $creates : 'fixture-recipient', 'status' => 'active']);
        });
        $payload = [...$this->payload(), 'provider_id' => $provider->id];
        $manager = app(ProviderBeneficiaryManager::class);
        $saved = $manager->createForUser($provider, $this->owner, $payload);
        $firstId = $saved->external_beneficiary_id;
        $updated = $manager->updateForUser($provider, $saved,
            fn (Beneficiary $snapshot) => $snapshot->fill(['email' => 'changed@example.test']), ['email' => 'changed@example.test']);
        $this->assertSame('changed@example.test', $updated->email);
        $afterUpdate = count(Http::recorded());
        $manager->updateForUser($provider, $updated,
            fn (Beneficiary $snapshot) => $snapshot->fill(['email' => 'changed@example.test']), ['email' => 'changed@example.test']);
        $this->assertCount($afterUpdate, Http::recorded());
        if ($code === 'wise') {
            $this->assertNotSame($firstId, $updated->external_beneficiary_id);
            $this->assertSame(['create' => true, 'wise_cleanup' => true], BeneficiaryOperation::query()->where('action', 'update')->sole()->dispatch_steps);
        }
        $beforeDelete = count(Http::recorded());
        $manager->deleteBeneficiary($provider, $updated);
        $this->assertNull($updated->fresh());
        if (in_array($code, ['tazapay', 'unlimit'], true)) $this->assertCount($beforeDelete, Http::recorded());
        if ($code === 'unlimit') Http::assertNothingSent();
        $this->assertSame(match ($code) {
            'wise' => ['POST', 'POST', 'DELETE', 'DELETE'],
            'pingpong' => ['POST', 'POST', 'POST'],
            'tazapay' => ['POST', 'PUT'],
            'unlimit' => [],
            default => ['POST', 'PUT', 'DELETE'],
        }, Http::recorded()->map(fn (array $exchange) => $exchange[0]->method())->all());
        $this->assertSame(count(Http::recorded()), $creates, 'Each recorded legacy request must reach its scenario handler exactly once.');
        $this->assertSame(3, BeneficiaryOperation::query()->where('state', 'succeeded')->count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    #[DataProvider('ambiguousResponses')]
    public function test_ambiguous_create_is_not_repeated_and_never_returns_false_success(string $outcome): void
    {
        $this->fakeHttp(function (Request $request) use ($outcome) {
            if ($request->method() === 'GET') return $this->response($request);
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            if ($outcome === 'timeout') throw new ConnectionException('fixture timeout');
            return Http::response(['message' => 'PRIVATE_PROVIDER_VALUE'], $outcome === 'missing' ? 200 : (int) $outcome);
        });
        $url = '/api/user/users/'.$this->owner->id.'/beneficiaries';
        $this->withToken($this->token)->postJson($url, $this->payload())->assertUnprocessable()
            ->assertJsonPath('retryable', false)->assertDontSee('PRIVATE_PROVIDER_VALUE');
        $operation = BeneficiaryOperation::query()->sole();
        $this->assertSame('ambiguous', $operation->state);
        $this->assertSame(['create' => true], $operation->dispatch_steps);
        $this->assertNotNull($operation->active_beneficiary_id);
        $this->assertNull(Beneficiary::query()->sole()->external_beneficiary_id);
        $requests = count(Http::recorded());
        $this->postJson($url, $this->payload())->assertStatus(409)->assertJsonPath('retryable', false);
        $this->assertCount($requests, Http::recorded());
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'beneficiary_create')->count());
        $this->assertSame(1, NiumApiExchangeEvidence::query()->whereHas('apiRequestLog',
            fn ($query) => $query->where('operation', 'beneficiary_create'))->count());
        $saved = Beneficiary::query()->sole();
        $manager = app(ProviderBeneficiaryManager::class);
        foreach (['update', 'delete'] as $action) {
            try {
                if ($action === 'update') $manager->updateBeneficiary($this->provider, $saved);
                else $manager->deleteBeneficiary($this->provider, $saved);
                $this->fail('An ambiguous Create was bypassed by '.$action.'.');
            } catch (BeneficiaryOperationConflict) {}
        }
        $this->assertCount($requests, Http::recorded());
        $this->assertSame($saved->id, $operation->fresh()->active_beneficiary_id);
        $this->assertSame(1, BeneficiaryOperation::query()->count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public static function failingMutations(): array
    {
        return ['update' => ['update'], 'delete' => ['delete']];
    }

    #[DataProvider('failingMutations')]
    public function test_failed_update_or_delete_never_reports_success_or_allows_a_second_dispatch(string $action): void
    {
        $beneficiary = $this->create();
        $url = '/api/user/users/'.$this->owner->id.'/beneficiaries/'.$beneficiary->id;
        $this->fakeHttp(fn (Request $request) => $request->method() === 'GET'
            ? $this->response($request) : Http::response(['message' => 'PRIVATE_PROVIDER_FAILURE'], 500));
        $response = $action === 'update'
            ? $this->withToken($this->token)->putJson($url, ['email' => 'unaccepted@example.test'])
            : $this->withToken($this->token)->deleteJson($url);
        $response->assertUnprocessable()->assertJsonPath('retryable', false)->assertDontSee('PRIVATE_PROVIDER_FAILURE');
        $claim = BeneficiaryOperation::query()->whereNotNull('active_beneficiary_id')->sole();
        $this->assertSame($action, $claim->action);
        $this->assertSame('ambiguous', $claim->state);
        $this->assertSame([$action => true], $claim->dispatch_steps);
        $this->assertSame('fixture-recipient', $beneficiary->fresh()->external_beneficiary_id);
        $this->assertNull($beneficiary->fresh()->email);
        $sent = count(Http::recorded());
        $retry = $action === 'update' ? $this->putJson($url, ['email' => 'unaccepted@example.test']) : $this->deleteJson($url);
        $retry->assertStatus(409)->assertJsonPath('retryable', false);
        $this->assertCount($sent, Http::recorded());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_local_save_failure_retains_encrypted_result_and_can_be_recovered_without_http(): void
    {
        $failOnce = true;
        Beneficiary::updating(function (Beneficiary $beneficiary) use (&$failOnce) {
            if ($failOnce && $beneficiary->status === 'active') {
                $failOnce = false;
                return false;
            }
            return null;
        });
        try {
            $this->create();
            $this->fail('A local save failure was reported as success.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('result was not persisted', $exception->getMessage());
        }
        $operation = BeneficiaryOperation::query()->sole();
        $this->assertSame('result_recorded', $operation->state);
        $this->assertSame('fixture-recipient', $operation->receipt['attributes']['external_beneficiary_id']);
        $this->assertStringNotContainsString('123456789', $operation->getRawOriginal('receipt'));
        $this->assertNull(Beneficiary::query()->sole()->external_beneficiary_id);
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'beneficiary_create')->count());
        $sent = count(Http::recorded());
        try { $this->create(); $this->fail('Recorded result was redispatched.'); } catch (BeneficiaryOperationConflict) {}
        $recovered = app(BeneficiaryOperationService::class)->applyRecorded($operation->id);
        $this->assertSame('fixture-recipient', $recovered->external_beneficiary_id);
        $this->assertSame('active', $recovered->status);
        $this->assertSame('succeeded', $operation->fresh()->state);
        $this->assertCount($sent, Http::recorded());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_exchange_evidence_failure_after_http_leaves_a_durable_non_retryable_claim(): void
    {
        NiumApiExchangeEvidence::creating(function (NiumApiExchangeEvidence $evidence): void {
            if ($evidence->apiRequestLog->operation === 'beneficiary_create') {
                throw new RuntimeException('fixture evidence storage unavailable');
            }
        });
        try { $this->create(); $this->fail('Evidence failure was ignored.'); } catch (RuntimeException) {}
        $operation = BeneficiaryOperation::query()->sole();
        $this->assertSame('ambiguous', $operation->state);
        $this->assertSame(['create' => true], $operation->dispatch_steps);
        $this->assertTrue(Str::isUuid($operation->request_id));
        $this->assertNull(Beneficiary::query()->sole()->external_beneficiary_id);
        $sent = count(Http::recorded());
        try { $this->create(); $this->fail('Ambiguous evidence failure was redispatched.'); } catch (BeneficiaryOperationConflict) {}
        $this->assertCount($sent, Http::recorded());
        $this->assertSame(0, ApiRequestLog::query()->where('operation', 'beneficiary_create')->count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_preflight_validation_still_prevents_create_and_does_not_leave_a_busy_placeholder(): void
    {
        $payload = $this->payload();
        $payload['account_number'] = null;
        $this->withToken($this->token)->postJson('/api/user/users/'.$this->owner->id.'/beneficiaries', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('beneficiaryAccountNumber');
        $this->assertSame(0, Beneficiary::query()->count());
        $operation = BeneficiaryOperation::query()->sole();
        $this->assertSame('rejected', $operation->state);
        $this->assertNull($operation->active_beneficiary_id);
        $this->assertNull($operation->create_key);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_update_without_external_id_uses_one_create_and_unregistered_delete_stays_local(): void
    {
        $beneficiary = $this->owner->beneficiaries()->create([...$this->payload(), 'status' => 'pending']);
        $updated = app(ProviderBeneficiaryManager::class)->updateBeneficiary($this->provider, $beneficiary);
        $this->assertSame('fixture-recipient', $updated->external_beneficiary_id);
        $this->assertSame('update', BeneficiaryOperation::query()->sole()->action);
        $this->assertSame(['create' => true], BeneficiaryOperation::query()->sole()->dispatch_steps);
        $unregistered = $this->owner->beneficiaries()->create([...$this->payload(), 'status' => 'pending']);
        $sent = count(Http::recorded());
        app(ProviderBeneficiaryManager::class)->deleteBeneficiary($this->provider, $unregistered);
        $this->assertNull($unregistered->fresh());
        $this->assertCount($sent, Http::recorded());
        $this->assertSame('succeeded', BeneficiaryOperation::query()->where('action', 'delete')->sole()->state);
    }

    public function test_update_delete_and_cross_owner_requests_preserve_controller_bindings(): void
    {
        $beneficiary = $this->create();
        $url = '/api/user/users/'.$this->owner->id.'/beneficiaries/'.$beneficiary->id;
        $this->withToken($this->token)->putJson($url, ['email' => 'updated@example.test'])->assertOk()
            ->assertJsonPath('email', 'updated@example.test');
        $this->putJson($url, ['provider_id' => 999999])->assertUnprocessable();
        $other = User::factory()->create();
        $foreign = $other->beneficiaries()->create([...$this->payload(), 'status' => 'pending']);
        $this->putJson('/api/user/users/'.$this->owner->id.'/beneficiaries/'.$foreign->id, ['email' => 'wrong@example.test'])->assertNotFound();
        $this->deleteJson('/api/user/users/'.$this->owner->id.'/beneficiaries/'.$foreign->id)->assertNotFound();
        $this->deleteJson($url)->assertNoContent();
        $this->assertNull($beneficiary->fresh());
        $this->assertNotNull($foreign->fresh());
        $this->assertSame(['succeeded', 'succeeded', 'succeeded'], BeneficiaryOperation::query()->orderBy('created_at')->pluck('state')->all());
    }

    public static function staleChanges(): array
    {
        return ['visible field' => ['email'], 'hidden routing data' => ['raw_data']];
    }

    #[DataProvider('staleChanges')]
    public function test_stale_response_cannot_overwrite_a_beneficiary_changed_outside_the_operation(string $field): void
    {
        config(['database.connections.beneficiary_observer' => config('database.connections.'.DB::getDefaultConnection())]);
        $this->fakeHttp(function (Request $request) use ($field) {
            if ($request->method() === 'POST') {
                DB::connection('beneficiary_observer')->table('beneficiaries')->update([$field => $field === 'email'
                    ? 'newer@example.test' : json_encode(['nium' => ['routingInfo' => [['type' => 'SWIFT', 'value' => 'NEWBHKHH']]]])]);
            }
            return $this->response($request);
        });
        try {
            try { $this->create(); $this->fail('A stale response was persisted.'); } catch (BeneficiaryOperationConflict) {}
            $saved = Beneficiary::query()->sole();
            $this->assertSame($field === 'email' ? 'newer@example.test' : 'NEWBHKHH',
                $field === 'email' ? $saved->email : $saved->raw_data['nium']['routingInfo'][0]['value']);
            $this->assertNull($saved->external_beneficiary_id);
            $this->assertSame('running', BeneficiaryOperation::query()->sole()->state);
            $this->assertSame(1, ApiRequestLog::query()->where('operation', 'beneficiary_create')->count());
        } finally {
            DB::purge('beneficiary_observer');
        }
    }

    public function test_unsupported_outer_transaction_is_rejected_before_provider_dispatch(): void
    {
        try {
            DB::transaction(fn () => $this->create());
            $this->fail('An unsupported caller transaction was adopted.');
        } catch (OwnerLockContractViolation) {}
        $this->assertSame(0, BeneficiaryOperation::query()->count());
        $this->assertSame(0, Beneficiary::query()->count());
        Http::assertNothingSent();
    }

    public function test_admin_local_writer_cannot_clear_an_ambiguous_claim(): void
    {
        $postAttempts = 0;
        $this->fakeHttp(function (Request $request) use (&$postAttempts) {
            if ($request->method() === 'GET') return $this->response($request);
            $this->assertSame('POST', $request->method());
            $this->assertSame('https://beneficiary.nium.test/beneficiaries', $request->url());
            $postAttempts++;
            return Http::response([], 503);
        });
        try {
            $this->create();
            $this->fail('The failed provider call was ignored.');
        } catch (NiumBeneficiaryWriteException $exception) {
            $this->assertSame(503, $exception->safeDiagnostic['http_status']);
        }
        $this->assertSame(1, $postAttempts);
        $this->assertCount(1, Http::recorded(fn (Request $request) => $request->method() === 'POST'));
        $saved = Beneficiary::query()->sole();
        $operation = BeneficiaryOperation::query()->sole();
        $this->assertSame('ambiguous', $operation->state);
        $this->assertSame(['create' => true], $operation->dispatch_steps);
        try {
            app(BeneficiaryOperationService::class)->mutateLocally($saved,
                fn (Beneficiary $locked) => $locked->update(['status' => 'active']));
            $this->fail('A local writer bypassed the provider claim.');
        } catch (BeneficiaryOperationConflict) {}
        $this->assertSame('ambiguous', $operation->fresh()->state);
        $this->assertSame($saved->status, $saved->fresh()->status);
    }

    public function test_direct_adapter_call_revalidates_provider_binding_before_dispatch(): void
    {
        $other = IntegrationProvider::query()->create(['code' => 'other-fixture', 'name' => 'Other', 'status' => 'active']);
        $beneficiary = $this->owner->beneficiaries()->create([...$this->payload(), 'status' => 'pending']);
        try {
            app(NiumBeneficiaryService::class)->createBeneficiary($other, $beneficiary);
            $this->fail('The adapter accepted a different provider binding.');
        } catch (BeneficiaryOperationConflict) {}
        $this->assertSame(0, BeneficiaryOperation::query()->count());
        Http::assertNothingSent();
    }

    private function assertCleanRace(array $race): void
    {
        $this->assertSame(['ok', 'ok'], array_column($race['workers'], 'status'), json_encode($race));
        $this->assertCount(3, array_unique($race['pids']));
        foreach ($race['workers'] as $worker => $result) {
            $this->assertSame(0, $result['depth']);
            $this->assertFalse($result['pdo_transaction']);
            $this->assertSame(0, $race['exits'][$worker]['exit_code']);
        }
        foreach ($race['sql'] as $queries) $this->assertSame([], array_values(array_filter(array_column($queries, 'sqlstate'))), json_encode($race));
        foreach ($race['wire'] as $wire) {
            $this->assertSame(0, $wire['depth']);
            $this->assertFalse($wire['pdo_transaction']);
        }
    }
}
