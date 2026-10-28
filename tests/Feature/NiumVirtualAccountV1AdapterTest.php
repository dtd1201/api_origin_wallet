<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\NiumApiExchangeEvidence;
use App\Models\NiumVirtualAccount;
use App\Models\NiumVirtualAccountOperation as Operation;
use App\Models\NiumVirtualAccountRequest;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumPaymentIdService;
use App\Services\Nium\NiumVirtualAccountRequestIdentity;
use App\Services\Nium\NiumVirtualAccountSafetyLedger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesNiumVirtualAccountLedgerFixtures;
use Tests\TestCase;

class NiumVirtualAccountV1AdapterTest extends TestCase
{
    use CreatesNiumVirtualAccountLedgerFixtures;

    private const BODY = ['accountCategory' => 'SELF_FUNDING_ACCOUNT', 'bankName' => 'JPM_SG', 'currencyCode' => 'USD'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.nium_v1_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('nium_v1_test');
        $this->artisan('migrate', ['--database' => 'nium_v1_test', '--force' => true])->assertSuccessful();
        $this->configureLedgerTarget();
        config([
            'services.nium.auth' => ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'test-key'],
            'services.nium.webhook.static_header_name' => 'x-partner-key',
            'services.nium.webhook.static_header_value' => 'test-partner-key',
            'services.nium.va_single_dispatch.enabled' => true,
            'services.nium.va_single_dispatch.old_workers_fenced' => true,
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        DB::purge('nium_v1_test');
        parent::tearDown();
    }

    private function account(): UserProviderAccount
    {
        $account = $this->ledgerAccount();
        config(['services.nium.va_single_dispatch.account_ids' => [$account->id]]);

        return $account;
    }

    private function assign(UserProviderAccount $account, string $key = 'v1-operation', string $version = 'v1', string $rail = 'WIRES'): NiumVirtualAccount|Operation
    {
        return app(NiumPaymentIdService::class)->assign($account, 'USD', 'SELF_FUNDING_ACCOUNT', $rail, 'JPM_SG', $key, apiVersion: $version);
    }

    public function test_exact_v1_body_frozen_identity_evidence_and_replay(): void
    {
        $account = $this->account();
        Http::fake(function ($outgoing, $options) use ($account) {
            $this->assertSame('POST', $outgoing->method());
            $this->assertSame(self::BODY, $outgoing->data());
            $this->assertArrayNotHasKey('accountType', $outgoing->data());
            $this->assertArrayNotHasKey('currency', $outgoing->data());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->assertFalse($options['allow_redirects']);
            $request = NiumVirtualAccountRequest::query()->sole();
            $snapshot = $request->identity()->snapshot();
            $this->assertSame('v1', $snapshot['api_version']);
            $this->assertSame('WIRES', $snapshot['funding_rail']);
            $this->assertSame('WIRES', $request->operation->account_type);
            $this->assertSame('https://nium.test/api/v1/client/'.$snapshot['client_id'].'/customer/'.$account->external_customer_id.'/wallet/'.$account->external_account_id.'/paymentId', $outgoing->url());
            $this->assertSame(1, $request->dispatchAttempt()->count());
            $this->assertSame([$request->provider_request_id], $outgoing->header('x-request-id'));

            return Http::response(['uniquePaymentId' => 'V1-ASSIGNED', 'currencyCode' => 'USD', 'bankName' => 'JPM_SG', 'accountCategory' => 'SELF_FUNDING_ACCOUNT']);
        });
        $va = $this->assign($account);
        $this->assertInstanceOf(NiumVirtualAccount::class, $va);
        $this->assertSame('V1-ASSIGNED', $va->provider_payment_id);
        $this->assertSame('WIRES', $va->account_type);
        $request = NiumVirtualAccountRequest::query()->sole();
        $this->assertNull($request->assignmentScope()->value('active_operation_id'));
        $exchange = NiumApiExchangeEvidence::query()->sole();
        $this->assertSame(self::BODY, json_decode($exchange->raw_request_body, true));
        $this->assertSame('https://nium.test'.$request->identity()->snapshot()['endpoint'], $exchange->assignment_target);
        $this->assertSame($va->id, $this->assign($account)->id);
        Http::assertSentCount(1);
    }

    #[DataProvider('unresolvedResponses')]
    public function test_unresolved_v1_responses_keep_scope_and_never_redispatch(array|string $body, int $status, string $state = Operation::UNKNOWN): void
    {
        $account = $this->account();
        Http::fake(fn () => Http::response($body, $status));
        $operation = $this->assign($account);
        $this->assertInstanceOf(Operation::class, $operation);
        $this->assertSame($state, $operation->status);
        $request = NiumVirtualAccountRequest::query()->sole();
        $this->assertNull($operation->provider_payment_id);
        $this->assertNull($operation->nium_virtual_account_id);
        $this->assertNull($request->outcome);
        $this->assertNull($operation->resolved_at);
        $this->assertSame($operation->id, $request->assignmentScope()->value('active_operation_id'));
        $this->assertSame($operation->id, $this->assign($account)->id);
        $this->assertSame(1, NiumApiExchangeEvidence::query()->count());
        $this->assertSame(0, NiumVirtualAccount::query()->count());
        try {
            $this->assign($account, 'new-key');
            $this->fail('Unresolved scope must block another key.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('unresolved assignment', $exception->getMessage());
        }
        // The direct resolver must apply the same dimensions policy as the transport.
        if ($status === 200 && $state === Operation::UNKNOWN && is_array($body)) {
            $this->assertSame(Operation::UNKNOWN, (new NiumVirtualAccountSafetyLedger)->resolveFromPostEvidence($request, 2, ApiRequestLog::query()->sole(), attachVirtualAccount: true)->operation_state);
            $this->assertSame($operation->id, $request->assignmentScope()->value('active_operation_id'));
        }
        Http::assertSentCount(1);
    }

    public static function unresolvedResponses(): array
    {
        return [
            'initialized' => [['uniquePaymentId' => 'INITIALIZED'], 200, Operation::PENDING],
            'lowercase initialized' => [['uniquePaymentId' => 'initialized'], 200, Operation::PENDING],
            'initialized with contradictory bank' => [['uniquePaymentId' => 'INITIALIZED', 'bankName' => 'DBS_HK'], 200],
            'initialized with contradictory client' => [['uniquePaymentId' => 'INITIALIZED', 'clientHashId' => 'other-client'], 200],
            'missing' => [[], 200], 'null' => [['uniquePaymentId' => null], 200],
            'integer' => [['uniquePaymentId' => 123], 200], 'blank' => [['uniquePaymentId' => ' '], 200],
            'control' => [['uniquePaymentId' => "VA\nINVALID"], 200],
            'currency conflict' => [['uniquePaymentId' => 'VA', 'currencyCode' => 'EUR'], 200],
            'currency alias conflict' => [['uniquePaymentId' => 'VA', 'currencyCode' => 'USD', 'currency' => 'EUR'], 200],
            'bank conflict' => [['uniquePaymentId' => 'VA', 'bankName' => 'DBS_HK'], 200],
            'null bank' => [['uniquePaymentId' => 'VA', 'bankName' => null], 200],
            'category conflict' => [['uniquePaymentId' => 'VA', 'accountCategory' => 'COLLECTION_ACCOUNT'], 200],
            'rail conflict' => [['uniquePaymentId' => 'VA', 'accountType' => 'LOCAL'], 200],
            'wallet conflict' => [['uniquePaymentId' => 'VA', 'walletHashId' => 'other-wallet'], 200],
            'customer conflict' => [['uniquePaymentId' => 'VA', 'customerHashId' => 'other-customer'], 200],
            'client conflict' => [['uniquePaymentId' => 'VA', 'clientHashId' => 'other-client'], 200],
            'null client' => [['uniquePaymentId' => 'VA', 'clientHashId' => null], 200],
            'non-string client' => [['uniquePaymentId' => 'VA', 'clientHashId' => 111], 200],
            'client is not trimmed' => [['uniquePaymentId' => 'VA', 'clientHashId' => '11111111-1111-4111-8111-111111111111 '], 200],
            'malformed json' => ['{broken', 200],
            '202 without identifier' => [[], 202], '202 with identifier' => [['uniquePaymentId' => 'VA'], 202],
            '408' => [[], 408], '409' => [[], 409], '429' => [[], 429], '500' => [[], 500], '503' => [[], 503],
        ];
    }

    #[DataProvider('versions')]
    public function test_operation_version_cannot_be_mixed_in_either_direction(string $version, string $other): void
    {
        $account = $this->account();
        Http::fake(fn () => Http::response([], 409));
        $this->assign($account, version: $version);
        try {
            $this->assign($account, version: $other);
            $this->fail('Version must be part of immutable identity.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('immutable assignment', $exception->getMessage());
        }
        $this->assertSame($version, NiumVirtualAccountRequest::query()->sole()->identity()->snapshot()['api_version']);
        Http::assertSentCount(1);
    }

    public static function versions(): array
    {
        return [['v1', 'v2'], ['v2', 'v1']];
    }

    #[DataProvider('optionalClientIdentity')]
    public function test_matching_or_missing_v1_client_identity_resolves_and_replay_does_not_dispatch(bool $includeClient): void
    {
        $account = $this->account();
        Http::fake(function () use ($includeClient) {
            $snapshot = NiumVirtualAccountRequest::query()->sole()->identity()->snapshot();

            return Http::response(['uniquePaymentId' => 'V1-CLIENT-VERIFIED', 'currencyCode' => 'USD', 'bankName' => 'JPM_SG']
                + ($includeClient ? ['clientHashId' => $snapshot['client_id']] : []));
        });
        $va = $this->assign($account);
        $this->assertInstanceOf(NiumVirtualAccount::class, $va);
        $request = NiumVirtualAccountRequest::query()->sole();
        $this->assertSame(Operation::ASSIGNED, $request->operation_state);
        $this->assertSame($va->id, $request->operation->nium_virtual_account_id);
        $this->assertNull($request->assignmentScope()->value('active_operation_id'));
        $this->assertSame($va->id, $this->assign($account)->id);
        Http::assertSentCount(1);
    }

    public static function optionalClientIdentity(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('unsupportedCategories')]
    public function test_unsupported_v1_category_fails_before_preparation_and_dispatch_authorization(string $category): void
    {
        $account = $this->account();
        try {
            app(NiumPaymentIdService::class)->assign($account, 'USD', $category, 'WIRES', 'JPM_SG', 'unsupported-v1-category', apiVersion: 'v1');
            $this->fail('Unsupported categories must not prepare or consume authorization.');
        } catch (InvalidArgumentException|RuntimeException) {
            $this->assertSame(0, Operation::query()->count());
            $this->assertSame(0, NiumVirtualAccountRequest::query()->count());
            $this->assertSame(0, DB::table('nium_va_dispatch_attempts')->count());
            $this->assertSame(0, DB::table('nium_va_assignment_scopes')->count());
        }
        Http::assertNothingSent();
    }

    public static function unsupportedCategories(): array
    {
        return [['SELF_FUNDING_AND_COLLECTION_ACCOUNT'], ['Null'], ['UNSUPPORTED']];
    }

    #[DataProvider('supportedCategories')]
    public function test_supported_category_dispatches_once_with_the_unchanged_wire_contract(string $version, string $category): void
    {
        $account = $this->account();
        Http::fake(function ($outgoing) use ($version, $category) {
            $this->assertSame('POST', $outgoing->method());
            $this->assertStringContainsString('/api/'.$version.'/', $outgoing->url());
            $expected = $version === 'v1'
                ? ['accountCategory' => $category, 'bankName' => 'JPM_SG', 'currencyCode' => 'USD']
                : ['accountCategory' => $category, 'accountType' => 'WIRES', 'bankName' => 'JPM_SG', 'currency' => 'USD'];
            $this->assertSame($expected, $outgoing->data());

            return Http::response(['uniquePaymentId' => 'VA-CATEGORY-VERIFIED']);
        });
        $service = app(NiumPaymentIdService::class);
        $va = $service->assign($account, 'USD', $category, 'WIRES', 'JPM_SG', 'supported-category', apiVersion: $version);
        $this->assertInstanceOf(NiumVirtualAccount::class, $va);
        $this->assertSame($category, $va->account_category);
        $this->assertSame($va->id, $service->assign($account, 'USD', $category, 'WIRES', 'JPM_SG', 'supported-category', apiVersion: $version)->id);
        Http::assertSentCount(1);
    }

    public static function supportedCategories(): array
    {
        return [['v1', 'SELF_FUNDING_ACCOUNT'], ['v1', 'COLLECTION_ACCOUNT'], ['v2', 'SELF_FUNDING_AND_COLLECTION_ACCOUNT']];
    }

    public function test_v2_body_and_snapshot_shape_remain_unchanged(): void
    {
        $account = $this->account();
        Http::fake(function ($outgoing) {
            $this->assertSame([
                'accountCategory' => 'SELF_FUNDING_ACCOUNT', 'accountType' => 'WIRES', 'bankName' => 'JPM_SG', 'currency' => 'USD',
            ], $outgoing->data());
            $this->assertStringContainsString('/api/v2/', $outgoing->url());

            return Http::response(['uniquePaymentId' => 'V2-UNCHANGED']);
        });
        $this->assertInstanceOf(NiumVirtualAccount::class, $this->assign($account, version: 'v2'));
        $snapshot = NiumVirtualAccountRequest::query()->sole()->identity()->snapshot();
        $this->assertArrayNotHasKey('funding_rail', $snapshot);
        $this->assertSame('v2', $snapshot['api_version']);
        Http::assertSentCount(1);
    }

    #[DataProvider('badSnapshots')]
    public function test_v1_cannot_restore_a_v2_payload_or_a_replaced_url(string $mutation): void
    {
        $account = $this->account();
        $identity = NiumVirtualAccountRequestIdentity::forAccount($account, self::BODY, 'v1', 'WIRES');
        $snapshot = $identity->snapshot();
        match ($mutation) {
            'type' => $snapshot['payload']['accountType'] = 'WIRES',
            'currency' => $snapshot['payload']['currency'] = 'USD',
            'url' => $snapshot['endpoint'] = str_replace('/v1/', '/v2/', $snapshot['endpoint']),
            'version' => $snapshot['api_version'] = 'v2',
            'missing rail' => $snapshot['funding_rail'] = null,
            'missing bank' => $snapshot['payload'] = array_diff_key($snapshot['payload'], ['bankName' => true]),
        };
        $this->expectException(InvalidArgumentException::class);
        NiumVirtualAccountRequestIdentity::fromSnapshot($snapshot);
    }

    public static function badSnapshots(): array
    {
        return [['type'], ['currency'], ['url'], ['version'], ['missing rail'], ['missing bank']];
    }

    public function test_funding_rail_is_frozen_even_though_it_is_not_in_the_v1_body(): void
    {
        $account = $this->account();
        Http::fake(fn () => Http::response([], 409));
        $this->assign($account);
        try {
            $this->assign($account, rail: 'LOCAL');
            $this->fail('Local intent must be immutable too.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('immutable assignment', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    #[DataProvider('crashStates')]
    public function test_prepared_or_consumed_v1_replay_never_dispatches(bool $consumed): void
    {
        $account = $this->account();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'v1-operation', NiumVirtualAccountRequestIdentity::forAccount($account, self::BODY, 'v1', 'WIRES'));
        if ($consumed) {
            $ledger->consumeDispatchAuthorization($request, 0);
        }
        $this->assertSame($consumed ? Operation::SUBMITTING : Operation::CLAIMED, $this->assign($account)->status);
        Http::assertNothingSent();
    }

    public static function crashStates(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('persistenceFailures')]
    public function test_timeout_or_evidence_failure_cannot_attach_or_redispatch(bool $evidenceFailure): void
    {
        $account = $this->account();
        $calls = 0;
        Http::fake(function () use (&$calls, $evidenceFailure) {
            $calls++;
            if (! $evidenceFailure) {
                throw new ConnectionException('Simulated timeout after acceptance');
            }

            return Http::response(['uniquePaymentId' => 'V1-ACCEPTED']);
        });
        if ($evidenceFailure) {
            DB::connection()->beforeExecuting(function ($query): void {
                if (str_starts_with($query, 'insert into "nium_api_exchange_evidence"')) {
                    throw new RuntimeException('Injected evidence persistence failure');
                }
            });
        }
        $this->assertSame(Operation::UNKNOWN, $this->assign($account)->status);
        $this->assertSame(Operation::UNKNOWN, $this->assign($account)->status);
        $this->assertSame(1, $calls);
        $request = NiumVirtualAccountRequest::query()->sole();
        $this->assertSame(1, $request->dispatchAttempt()->count());
        $this->assertSame($request->operation_id, $request->assignmentScope()->value('active_operation_id'));
        $this->assertSame(0, NiumVirtualAccount::query()->count());
    }

    public static function persistenceFailures(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('conflictingOwners')]
    public function test_existing_cross_account_id_conflict_remains_unresolved(bool $operationOnly): void
    {
        $account = $this->account();
        $other = $this->ledgerAccount();
        if ($operationOnly) {
            $this->legacyLedgerOperation($other, Operation::ASSIGNED, ['provider_payment_id' => 'V1-CONFLICT', 'resolved_at' => now()]);
        } else {
            NiumVirtualAccount::query()->create([
                'user_provider_account_id' => $other->id, 'provider_payment_id' => 'V1-CONFLICT',
                'currency' => 'USD', 'account_category' => 'SELF_FUNDING_ACCOUNT', 'account_type' => 'WIRES', 'status' => 'assigned',
            ]);
        }
        Http::fake(fn () => Http::response(['uniquePaymentId' => 'V1-CONFLICT']));
        $this->assertSame(Operation::MANUAL_REVIEW, $this->assign($account)->status);
        $request = NiumVirtualAccountRequest::query()->sole();
        $this->assertNull($request->outcome);
        $this->assertNull($request->operation->provider_payment_id);
        $this->assertNull($request->operation->nium_virtual_account_id);
        $this->assertSame($request->operation_id, $request->assignmentScope()->value('active_operation_id'));
        $this->assign($account);
        Http::assertSentCount(1);
    }

    public static function conflictingOwners(): array
    {
        return [[false], [true]];
    }

    public function test_multiple_intentional_usd_v1_assignments_release_scope_sequentially(): void
    {
        $account = $this->account();
        Http::fakeSequence()->push(['uniquePaymentId' => 'V1-USD-ONE'])->push(['uniquePaymentId' => 'V1-USD-TWO']);
        $first = $this->assign($account, 'first');
        $second = $this->assign($account, 'second');
        $this->assertInstanceOf(NiumVirtualAccount::class, $first);
        $this->assertInstanceOf(NiumVirtualAccount::class, $second);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, NiumVirtualAccount::query()->count());
        $this->assertSame(1, DB::table('nium_va_assignment_scopes')->count());
        $this->assertNull(NiumVirtualAccountRequest::query()->firstOrFail()->assignmentScope()->value('active_operation_id'));
        $this->assertSame($first->id, $this->assign($account, 'first')->id);
        Http::assertSentCount(2);
    }

    #[DataProvider('interlocks')]
    public function test_v1_gate_interlocks_cannot_enable_legacy_or_production_dispatch(string $interlock): void
    {
        $account = $this->account();
        match ($interlock) {
            'disabled' => config(['services.nium.va_single_dispatch.enabled' => false]),
            'production' => $this->app->instance('env', 'production'),
            'allowlist' => config(['services.nium.va_single_dispatch.account_ids' => []]),
            'workers' => config(['services.nium.va_single_dispatch.old_workers_fenced' => false]),
        };
        try {
            $this->assign($account);
            $this->fail('Gate must prevent V1 dispatch.');
        } catch (RuntimeException) {
            $this->assertSame(0, NiumVirtualAccountRequest::query()->count());
            $this->assertSame(0, Operation::query()->count());
        }
        Http::assertNothingSent();
    }

    public static function interlocks(): array
    {
        return [['disabled'], ['production'], ['allowlist'], ['workers']];
    }

    public function test_disabled_gate_returns_v1_state_without_legacy_fallback(): void
    {
        $account = $this->account();
        Http::fake(fn () => Http::response([], 409));
        $this->assign($account);
        config(['services.nium.va_single_dispatch.enabled' => false]);
        $this->assertSame(Operation::UNKNOWN, $this->assign($account)->status);
        Http::assertSentCount(1);
    }

    public function test_v1_direct_400_evidence_cannot_release_scope_using_v2_rejection_rules(): void
    {
        $account = $this->account();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'v1-rejection', NiumVirtualAccountRequestIdentity::forAccount($account, self::BODY, 'v1', 'WIRES'));
        $ledger->consumeDispatchAuthorization($request, 0);
        $log = $this->ledgerResponseEvidence($request, ['errors' => [['code' => 'invalid_input', 'field' => 'currencyCode']]], 400);
        try {
            $ledger->resolveFromPostEvidence($request, 1, $log, attachVirtualAccount: true);
            $this->fail('V2 rejection rules are not authoritative V1 evidence.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('definitive assignment outcome', $exception->getMessage());
        }
        $this->assertSame(Operation::SUBMITTING, $request->fresh()->operation_state);
        $this->assertSame($request->operation_id, $request->assignmentScope()->value('active_operation_id'));
        Http::assertNothingSent();
    }

    #[DataProvider('changedTargets')]
    public function test_prepared_v1_target_changes_prevent_consumption(string $target): void
    {
        $account = $this->account();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'v1-target', NiumVirtualAccountRequestIdentity::forAccount($account, self::BODY, 'v1', 'WIRES'));
        match ($target) {
            'origin' => config(['services.nium.base_url' => 'https://changed.test']),
            'client' => config(['services.nium.client_id' => 'changed-client']),
            'wallet' => $account->update(['external_account_id' => 'changed-wallet']),
        };
        try {
            $ledger->consumeDispatchAuthorization($request, 0);
            $this->fail('Target changes must fail closed.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('target has changed', $exception->getMessage());
        }
        $this->assertSame(0, $request->dispatchAttempt()->count());
        $this->assertSame('v1', $request->identity()->snapshot()['api_version']);
        $this->assertSame($request->operation_id, $request->assignmentScope()->value('active_operation_id'));
        Http::assertNothingSent();
    }

    public static function changedTargets(): array
    {
        return [['origin'], ['client'], ['wallet']];
    }
}
