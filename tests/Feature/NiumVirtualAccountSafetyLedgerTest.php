<?php

namespace Tests\Feature;

use App\Models\NiumVirtualAccount;
use App\Models\NiumVirtualAccountAssignmentScope;
use App\Models\NiumVirtualAccountDispatchAttempt;
use App\Models\NiumVirtualAccountOperation as Operation;
use App\Models\NiumVirtualAccountRequest;
use App\Services\Nium\NiumPaymentIdBankResolver;
use App\Services\Nium\NiumVirtualAccountOperationState;
use App\Services\Nium\NiumVirtualAccountSafetyLedger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesNiumVirtualAccountLedgerFixtures;
use Tests\TestCase;

class NiumVirtualAccountSafetyLedgerTest extends TestCase
{
    use CreatesNiumVirtualAccountLedgerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        // Separate fresh in-memory schema per test, without a surrounding test transaction.
        config(['database.connections.nium_ledger_unit' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('nium_ledger_unit');
        $this->artisan('migrate', ['--database' => 'nium_ledger_unit', '--force' => true])->assertSuccessful();
        $this->configureLedgerTarget();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        DB::purge('nium_ledger_unit');
        parent::tearDown();
    }

    public function test_preparation_preserves_canonical_encrypted_identity_and_existing_records(): void
    {
        $account = $this->ledgerAccount();
        $before = $account->fresh()->getAttributes();
        $identity = $this->ledgerIdentity($account);
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'prepare-one', $identity);
        $reordered = $this->ledgerIdentity($account, ['payload' => array_reverse($identity->snapshot()['payload'], true)]);
        $replay = $ledger->prepare($account, 'prepare-one', $reordered);

        $this->assertSame($request->operation_id, $replay->operation_id);
        $this->assertSame($identity->payloadHash(), $replay->payload_sha256);
        $this->assertTrue($replay->matchesIdentity($reordered));
        $this->assertTrue(Str::isUuid($request->provider_request_id));
        $this->assertSame($request->provider_request_id, $replay->provider_request_id);
        $this->assertTrue($ledger->dispatchAuthorizationEligible($request));
        $this->assertSame($before, $account->fresh()->getAttributes());
        $this->assertSame(0, NiumVirtualAccount::query()->count());
        $this->assertNull($request->operation->baseline_payment_ids);
        $this->assertFalse($request->operation->baseline_complete);
        $this->assertNull($request->operation->submitted_at);
        $this->assertNull($request->operation->api_request_log_id);
        $raw = DB::table('nium_va_requests')->value('snapshot');
        $this->assertStringNotContainsString($account->external_customer_id, $raw);
        $this->assertStringNotContainsString('DBS_HK', $raw);
        $this->assertArrayNotHasKey('snapshot', $request->toArray());
    }

    public function test_dispatch_authorization_can_only_be_consumed_once_and_never_reset(): void
    {
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'consume-one', $this->ledgerIdentity($account));
        $attempt = $ledger->consumeDispatchAuthorization($request, 0);

        $this->assertNotNull($attempt);
        $this->assertNotNull($attempt->consumed_at);
        $this->assertSame(Operation::SUBMITTING, $request->operation()->value('status'));
        $this->assertSame(1, $request->fresh()->state_version);
        $this->assertNull($ledger->consumeDispatchAuthorization($request, 0));
        $this->assertNull($ledger->consumeDispatchAuthorization($request, 1));
        $this->assertFalse($ledger->dispatchAuthorizationEligible($request));
        $this->assertDatabaseRejects(fn () => NiumVirtualAccountDispatchAttempt::query()->create([
            'operation_id' => $request->operation_id, 'consumed_at' => now(),
        ]));
        $this->assertDatabaseRejects(fn () => DB::table('nium_va_dispatch_attempts')->update(['consumed_at' => now()->addDay()]));
        $this->assertDatabaseRejects(fn () => DB::table('nium_va_dispatch_attempts')->delete());
        $this->assertDatabaseRejects(fn () => DB::table('nium_va_requests')->delete());
        $this->assertDatabaseRejects(fn () => $request->operation()->update(['status' => Operation::CLAIMED]));
        $this->assertSame(1, NiumVirtualAccountDispatchAttempt::query()->count());
    }

    public function test_different_operations_and_provider_accounts_contend_for_the_actual_wallet_currency(): void
    {
        $account = $this->ledgerAccount();
        $other = $this->ledgerAccount($account->external_account_id);
        $ledger = new NiumVirtualAccountSafetyLedger;
        $first = $ledger->prepare($account, 'first', $this->ledgerIdentity($account));

        foreach ([$account, $other] as $contender) {
            try {
                $ledger->prepare($contender, 'another-operation', $this->ledgerIdentity($contender));
                $this->fail('A second operation must not acquire this wallet/currency.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('unresolved assignment', $exception->getMessage());
            }
        }
        $this->assertSame(1, Operation::query()->count());
        $this->assertSame($first->operation_id, NiumVirtualAccountAssignmentScope::query()->sole()->active_operation_id);

        $eur = $this->ledgerIdentity($account, ['payload' => ['currency' => 'EUR', 'accountCategory' => 'COLLECTION_ACCOUNT', 'accountType' => 'LOCAL']]);
        $this->assertNotSame($first->scope_key, $ledger->prepare($account, 'eur-operation', $eur)->scope_key);
    }

    public function test_verified_resolution_releases_scope_for_sequential_intentional_usd_assignments(): void
    {
        $account = $this->ledgerAccount();
        $identity = $this->ledgerIdentity($account);
        $ledger = new NiumVirtualAccountSafetyLedger;
        $first = $ledger->prepare($account, 'usd-one', $identity);
        $ledger->consumeDispatchAuthorization($first, 0);
        $evidence = $this->ledgerResponseEvidence($first);
        $resolved = $ledger->resolveFromPostEvidence($first, 1, $evidence);

        $this->assertSame(Operation::ASSIGNED, $resolved->operation()->value('status'));
        $this->assertSame($evidence->id, $resolved->resolution_api_request_log_id);
        $this->assertNull($resolved->assignmentScope()->value('active_operation_id'));
        $this->assertNotNull($resolved->operation->resolved_at);
        $this->assertSame('VA-LEDGER-ONE', $resolved->operation->provider_payment_id);
        $this->assertDatabaseRejects(fn () => $resolved->operation()->update(['status' => Operation::UNKNOWN]));

        $second = $ledger->prepare($account, 'usd-two', $identity);
        $this->assertSame($first->scope_key, $second->scope_key);
        $this->assertNotSame($first->operation_id, $second->operation_id);
        $this->assertNotSame($first->provider_request_id, $second->provider_request_id);
        $this->assertNotNull($ledger->consumeDispatchAuthorization($second, 0));
        $ledger->resolveFromPostEvidence($second, 1, $this->ledgerResponseEvidence($second, ['uniquePaymentId' => 'VA-LEDGER-TWO']));
        $this->assertSame(2, NiumVirtualAccountDispatchAttempt::query()->count());
        $this->assertSame(['VA-LEDGER-ONE', 'VA-LEDGER-TWO'], Operation::query()->orderBy('id')->pluck('provider_payment_id')->all());
        $this->assertSame($first->operation_id, $ledger->prepare($account, 'usd-one', $identity)->operation_id);
        $this->assertSame(0, NiumVirtualAccount::query()->count());
    }

    #[DataProvider('unresolvedStates')]
    public function test_unresolved_states_retain_scope_and_consumed_authorization(string $state): void
    {
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'unresolved', $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        $ledger->transition($request, 1, $state);
        $this->travel(2)->days();

        $this->assertTrue(NiumVirtualAccountOperationState::isUnresolved($state));
        $this->assertNull($ledger->consumeDispatchAuthorization($request, 2));
        $this->assertSame($request->operation_id, $request->assignmentScope()->value('active_operation_id'));
        $this->assertDatabaseRejects(fn () => $request->assignmentScope()->update(['active_operation_id' => null]));
        $this->assertDatabaseRejects(fn () => $request->assignmentScope()->delete());
        $this->assertSame(1, NiumVirtualAccountDispatchAttempt::query()->count());
    }

    public static function unresolvedStates(): array
    {
        return [['pending'], ['unknown'], ['manual_review']];
    }

    public function test_stale_and_terminal_transitions_cannot_release_a_scope(): void
    {
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'transitions', $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        foreach ([[0, Operation::PENDING], [1, Operation::CLAIMED], [1, Operation::ASSIGNED], [1, Operation::FAILED]] as [$version, $state]) {
            try {
                $ledger->transition($request, $version, $state);
                $this->fail('An invalid transition must fail.');
            } catch (RuntimeException) {
                $this->assertSame(Operation::SUBMITTING, $request->operation()->value('status'));
            }
        }
        $this->assertSame(1, $request->fresh()->state_version);
        $this->assertDatabaseRejects(fn () => $request->assignmentScope()->update(['active_operation_id' => null]));
    }

    public function test_request_target_payload_and_provider_uuid_are_database_immutable(): void
    {
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $identity = $this->ledgerIdentity($account);
        $request = $ledger->prepare($account, 'immutable', $identity);
        foreach (['snapshot' => 'tampered', 'payload_sha256' => str_repeat('0', 64), 'provider_request_id' => (string) Str::uuid(), 'scope_key' => str_repeat('0', 64)] as $field => $value) {
            $this->assertDatabaseRejects(fn () => DB::table('nium_va_requests')->where('operation_id', $request->operation_id)->update([$field => $value, 'state_version' => 1]));
        }
        $other = $this->ledgerAccount();
        foreach (['currency' => 'EUR', 'account_category' => 'SELF_FUNDING_ACCOUNT', 'account_type' => 'WIRES', 'bank_name' => 'OTHER', 'operation_id' => 'different', 'user_provider_account_id' => $other->id] as $field => $value) {
            $this->assertDatabaseRejects(fn () => $request->operation()->update([$field => $value]));
        }
        foreach ([0, -1, 2] as $version) {
            $this->assertDatabaseRejects(fn () => $request->newQuery()->whereKey($request->operation_id)->update(['state_version' => $version]));
        }
        foreach (['environment' => 'production', 'clientId' => (string) Str::uuid(), 'walletId' => (string) Str::uuid(), 'customerId' => (string) Str::uuid(), 'payload' => array_replace($identity->snapshot()['payload'], ['bankName' => 'OTHER'])] as $field => $value) {
            try {
                $ledger->prepare($account, 'immutable', $this->ledgerIdentity($account, [$field => $value]));
                $this->fail('An existing request must reject a different target or payload.');
            } catch (RuntimeException|InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertTrue($request->fresh()->matchesIdentity($identity));
    }

    #[DataProvider('legacyStates')]
    public function test_legacy_operations_are_readable_but_cannot_be_enrolled_or_consume_dispatch(string $state): void
    {
        $account = $this->ledgerAccount();
        $operation = Operation::query()->create([
            'user_provider_account_id' => $account->id, 'operation_id' => 'legacy', 'currency' => 'USD',
            'account_category' => 'COLLECTION_ACCOUNT', 'account_type' => 'LOCAL',
            'status' => $state, 'claimed_at' => now(),
        ]);
        $before = $operation->fresh()->getAttributes();
        try {
            (new NiumVirtualAccountSafetyLedger)->prepare($account, 'legacy', $this->ledgerIdentity($account));
            $this->fail('Legacy operation enrollment must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Legacy operations cannot be enrolled', $exception->getMessage());
        }
        $this->assertSame($before, $operation->fresh()->getAttributes());
        $this->assertSame(0, NiumVirtualAccountRequest::query()->count());
        $this->assertDatabaseRejects(fn () => NiumVirtualAccountDispatchAttempt::query()->create(['operation_id' => $operation->id, 'consumed_at' => now()]));
        $operation->update(['resolution_code' => 'legacy_still_writable']);
        $this->assertSame('legacy_still_writable', $operation->fresh()->resolution_code);
    }

    public static function legacyStates(): array
    {
        return [['claimed'], ['preflight_failed'], ['submitting'], ['pending'], ['unknown'], ['manual_review'], ['assigned'], ['failed']];
    }

    public function test_only_correlated_definitive_evidence_can_resolve_and_release(): void
    {
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'evidence', $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        foreach ([[['message' => 'unavailable'], 503], [['message' => 'not found'], 404]] as [$response, $status]) {
            try {
                $ledger->resolveFromPostEvidence($request, 1, $this->ledgerResponseEvidence($request, $response, $status));
                $this->fail('Uncertain evidence must not release the scope.');
            } catch (RuntimeException) {
                $this->assertSame($request->operation_id, $request->assignmentScope()->value('active_operation_id'));
            }
        }
        $wrongEvidence = $this->ledgerResponseEvidence($request);
        $wrongEvidence->update(['external_reference' => 'another-operation']);
        try {
            $ledger->resolveFromPostEvidence($request, 1, $wrongEvidence);
            $this->fail('Evidence for a different operation must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('does not verify', $exception->getMessage());
        }
        $rejected = $ledger->resolveFromPostEvidence($request, 1, $this->ledgerResponseEvidence($request, [
            'errors' => [['code' => 'invalid_input', 'field' => 'currency']],
        ], 400));
        $this->assertSame(Operation::FAILED, $rejected->operation()->value('status'));
        $this->assertNull($rejected->assignmentScope()->value('active_operation_id'));
        $this->assertSame(1, NiumVirtualAccountDispatchAttempt::query()->count());
    }

    #[DataProvider('malformedPaymentIdResponses')]
    public function test_malformed_payment_id_retains_evidence_and_blocks_further_dispatch(array $response, int $status = 200): void
    {
        $account = $this->ledgerAccount();
        $identity = $this->ledgerIdentity($account);
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'malformed-payment-id', $identity);
        $ledger->consumeDispatchAuthorization($request, 0);
        $evidence = $this->ledgerResponseEvidence($request, $response, $status);
        $rawResponse = $evidence->niumExchangeEvidence->raw_response_body;

        $unresolved = $ledger->resolveFromPostEvidence($request, 1, $evidence);

        $this->assertSame(Operation::UNKNOWN, $unresolved->operation_state);
        $this->assertSame(Operation::UNKNOWN, $unresolved->operation()->value('status'));
        $this->assertSame(2, $unresolved->state_version);
        $this->assertNull($unresolved->outcome);
        $this->assertNull($unresolved->resolution_api_request_log_id);
        $this->assertNull($unresolved->operation()->value('provider_payment_id'));
        $this->assertNull($unresolved->operation()->value('resolved_at'));
        $this->assertSame($request->operation_id, $unresolved->assignmentScope()->value('active_operation_id'));
        $this->assertSame($rawResponse, $evidence->fresh()->niumExchangeEvidence->raw_response_body);
        $this->assertSame($response, json_decode($rawResponse, true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame(0, NiumVirtualAccount::query()->count());
        $this->assertSame($request->operation_id, $ledger->prepare($account, 'malformed-payment-id', $identity)->operation_id);
        $this->assertNull($ledger->consumeDispatchAuthorization($unresolved, 2));
        $this->assertSame(2, $ledger->resolveFromPostEvidence($unresolved, 2, $evidence)->state_version);
        try {
            $ledger->resolveFromPostEvidence($unresolved, 1, $evidence);
            $this->fail('Stale malformed evidence must not bypass the version guard.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Stale', $exception->getMessage());
        }
        try {
            $ledger->prepare($account, 'new-key-after-malformed-id', $identity);
            $this->fail('Malformed evidence must not make the wallet available.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('unresolved assignment', $exception->getMessage());
        }
        $this->assertSame(1, NiumVirtualAccountDispatchAttempt::query()->count());
        $this->assertSame(1, Operation::query()->count());
    }

    public static function malformedPaymentIdResponses(): array
    {
        $cases = [
            'reported newline' => [['uniquePaymentId' => "VA\nINVALID"]],
            'embedded carriage return' => [['uniquePaymentId' => "VA\rINVALID"]],
            'embedded NUL' => [['uniquePaymentId' => "VA\0INVALID"]],
            'embedded tab' => [['uniquePaymentId' => "VA\tINVALID"]],
            'DEL' => [['uniquePaymentId' => "VA\x7fINVALID"]],
            'empty' => [['uniquePaymentId' => '']],
            'whitespace only' => [['uniquePaymentId' => '   ']],
            'leading whitespace' => [['uniquePaymentId' => ' VA-123']],
            'trailing whitespace' => [['uniquePaymentId' => 'VA-123 ']],
            'unicode whitespace' => [['uniquePaymentId' => "VA-123\u{00a0}"]],
            'integer' => [['uniquePaymentId' => 123]],
            'boolean' => [['uniquePaymentId' => true]],
            'array' => [['uniquePaymentId' => ['VA-123']]],
            'null' => [['uniquePaymentId' => null]],
            'missing' => [[]],
            'INITIALIZED' => [['uniquePaymentId' => 'INITIALIZED']],
            'mixed case initialized' => [['uniquePaymentId' => 'InItIaLiZeD']],
            'storage overflow' => [['uniquePaymentId' => str_repeat('V', 256)]],
        ];
        foreach (['null' => null, 'newline' => "VA\nINVALID", 'initialized' => 'INITIALIZED'] as $name => $value) {
            $cases['400 with malformed ID '.$name] = [[
                'uniquePaymentId' => $value, 'errors' => [['code' => 'invalid_input', 'field' => 'currency']],
            ], 400];
        }

        return $cases;
    }

    #[DataProvider('unresolvedResponseStates')]
    public function test_malformed_response_cannot_regress_an_unresolved_state(string $state): void
    {
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'malformed-unresolved-state', $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        $request = $ledger->transition($request, 1, $state);
        $resolved = $ledger->resolveFromPostEvidence($request, 2, $this->ledgerResponseEvidence($request, ['uniquePaymentId' => "VA\nINVALID"]));

        $this->assertSame($state === Operation::PENDING ? Operation::UNKNOWN : $state, $resolved->operation_state);
        $this->assertSame($request->operation_id, $resolved->assignmentScope()->value('active_operation_id'));
        $this->assertNull($resolved->outcome);
        $this->assertNull($resolved->operation()->value('provider_payment_id'));
    }

    public static function unresolvedResponseStates(): array
    {
        return [[Operation::PENDING], [Operation::UNKNOWN], [Operation::MANUAL_REVIEW]];
    }

    public function test_valid_authoritative_evidence_can_resolve_after_a_malformed_response(): void
    {
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'malformed-then-verified', $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        $malformed = $this->ledgerResponseEvidence($request, ['uniquePaymentId' => "VA\nINVALID"]);
        $unknown = $ledger->resolveFromPostEvidence($request, 1, $malformed);
        $valid = $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA.123/ABC:+_=']);
        $assigned = $ledger->resolveFromPostEvidence($unknown, 2, $valid);

        $this->assertSame(Operation::ASSIGNED, $assigned->operation_state);
        $this->assertSame('VA.123/ABC:+_=', $assigned->operation()->value('provider_payment_id'));
        $this->assertNull($assigned->assignmentScope()->value('active_operation_id'));
        $this->assertSame(3, $ledger->resolveFromPostEvidence($assigned, 3, $valid)->state_version);
        foreach ([2, 3] as $version) {
            try {
                $ledger->resolveFromPostEvidence($assigned, $version, $malformed);
                $this->fail('Late malformed evidence cannot rewrite a terminal assignment.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('conflicting terminal', $exception->getMessage());
            }
        }
        $this->assertSame($malformed->id, $malformed->fresh()->id);
        $this->assertSame('VA.123/ABC:+_=', $assigned->operation()->value('provider_payment_id'));
    }

    #[DataProvider('malformedLegacyPaymentIds')]
    public function test_malformed_legacy_assigned_ids_remain_ambiguous_without_changing_history(?string $paymentId): void
    {
        $account = $this->ledgerAccount();
        $legacy = $this->legacyLedgerOperation($account, Operation::ASSIGNED, [
            'provider_payment_id' => $paymentId, 'resolved_at' => now(),
        ]);
        $before = $legacy->fresh()->getAttributes();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $identity = $this->ledgerIdentity($account);
        $this->assertFalse(NiumVirtualAccountOperationState::hasConclusiveLegacyAssignment($legacy));
        $this->assertSame([$legacy->id], $ledger->legacyOperationEligibility($identity)['blocking_operation_ids']);
        try {
            $ledger->prepare($account, 'new-key-after-malformed-legacy', $identity);
            $this->fail('A malformed historical ID must not clear the legacy barrier.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Legacy unresolved', $exception->getMessage());
        }
        $this->assertSame($before, $legacy->fresh()->getAttributes());
        $this->assertSame(0, NiumVirtualAccountRequest::query()->count());
        $this->assertSame(0, NiumVirtualAccountDispatchAttempt::query()->count());
    }

    public static function malformedLegacyPaymentIds(): array
    {
        return [["VA\nINVALID"], ["VA\rINVALID"], ["VA\x7fINVALID"], [''], ['   '], [' VA-123'],
            ['VA-123 '], ['INITIALIZED'], ['initialized'], ["VA-123\u{00a0}"], [null]];
    }

    #[DataProvider('unverifiedEvidence')]
    public function test_unverified_target_or_request_evidence_never_releases_scope(string $mutation): void
    {
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'unverified-evidence', $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        $evidence = $this->ledgerResponseEvidence($request);
        $exchange = $evidence->niumExchangeEvidence()->firstOrFail();
        $snapshot = $request->identity()->snapshot();
        $changes = match ($mutation) {
            'redacted_target' => ['request_url' => $evidence->request_url],
            'wrong_origin' => ['request_url' => str_replace('nium.test', 'other-provider.test', $exchange->request_url)],
            'wrong_body_hash' => ['request_body_sha256' => str_repeat('0', 64)],
            'different_wallet' => ['request_url' => str_replace($snapshot['wallet_id'], (string) Str::uuid(), $exchange->request_url)],
            'extra_query' => ['request_url' => $exchange->request_url.'?currency=USD'],
            'wrong_request_id' => ['request_headers' => ['x-request-id' => [(string) Str::uuid()]]],
            'multiple_request_ids' => ['request_headers' => ['x-request-id' => [$request->provider_request_id, (string) Str::uuid()]]],
            'different_payload' => ['raw_request_body' => json_encode(array_replace($snapshot['payload'], ['bankName' => 'OTHER']), JSON_THROW_ON_ERROR)],
            'no_response' => ['response_received' => false],
        };
        $exchange->update($changes);
        try {
            $ledger->resolveFromPostEvidence($request, 1, $evidence);
            $this->fail('Unverified evidence must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('does not verify', $exception->getMessage());
        }
        $this->assertSame(Operation::SUBMITTING, $request->operation()->value('status'));
        $this->assertSame($request->operation_id, $request->assignmentScope()->value('active_operation_id'));
        $this->assertNull($request->fresh()->resolution_api_request_log_id);
        $this->assertSame(1, $request->fresh()->state_version);
        $this->assertNull($ledger->consumeDispatchAuthorization($request, 1));
    }

    public static function unverifiedEvidence(): array
    {
        return array_map(fn (string $mutation): array => [$mutation], [
            'redacted_target', 'wrong_origin', 'wrong_body_hash', 'different_wallet', 'extra_query', 'wrong_request_id',
            'multiple_request_ids', 'different_payload', 'no_response',
        ]);
    }

    public function test_identity_rejects_authentication_fields_and_payloads_that_cannot_fit_existing_storage(): void
    {
        $account = $this->ledgerAccount();
        $payload = $this->ledgerIdentity($account)->snapshot()['payload'];
        foreach ([['authorization' => 'example-secret'], ['bankName' => str_repeat('B', 101)], ['bankName' => ' ']] as $changes) {
            try {
                $this->ledgerIdentity($account, ['payload' => array_replace($payload, $changes)]);
                $this->fail('Unsafe or unrepresentable request identity must be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, NiumVirtualAccountRequest::query()->count());
    }

    public function test_outer_transaction_is_rejected_and_a_committed_attempt_survives_later_rollback(): void
    {
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'nested', $this->ledgerIdentity($account));
        DB::beginTransaction();
        try {
            $ledger->consumeDispatchAuthorization($request, 0);
            $this->fail('An enclosing transaction cannot receive dispatch authorization.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('top-level', $exception->getMessage());
            $this->assertSame(0, NiumVirtualAccountDispatchAttempt::query()->count());
        } finally {
            DB::rollBack();
        }
        $this->assertNotNull($ledger->consumeDispatchAuthorization($request, 0));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::getPdo()->inTransaction());
        DB::beginTransaction();
        DB::rollBack();
        $this->assertNull($ledger->consumeDispatchAuthorization($request, 1));
        $this->assertSame(1, NiumVirtualAccountDispatchAttempt::query()->count());
    }

    public function test_database_rejects_direct_enrolled_state_changes_and_stale_companion_writers(): void
    {
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'controlled', $this->ledgerIdentity($account));
        $this->assertDatabaseRejects(fn () => $request->operation()->update(['status' => Operation::SUBMITTING]));
        $this->assertDatabaseRejects(fn () => $request->operation()->firstOrFail()->update(['resolution_code' => 'uncontrolled']));
        $ledger->consumeDispatchAuthorization($request, 0);
        $stale = $request->fresh();
        $ledger->transition($request, 1, Operation::PENDING);
        $this->assertDatabaseRejects(fn () => $stale->update(['operation_state' => Operation::UNKNOWN, 'state_version' => 2]));
        $this->assertDatabaseRejects(fn () => $request->operation()->update(['status' => Operation::UNKNOWN]));
        $this->assertSame(Operation::PENDING, $request->fresh()->operation_state);
        $this->assertSame(2, $request->fresh()->state_version);
        $this->assertDatabaseRejects(fn () => $request->newQuery()->whereKey($request->operation_id)->update([
            'operation_state' => Operation::ASSIGNED, 'state_version' => 3,
        ]));
    }

    public function test_terminal_outcome_and_referenced_evidence_are_frozen_and_exact_duplicate_resolution_is_idempotent(): void
    {
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'terminal-facts', $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        $evidence = $this->ledgerResponseEvidence($request);
        $resolved = $ledger->resolveFromPostEvidence($request, 1, $evidence);
        $this->assertSame(2, $ledger->resolveFromPostEvidence($resolved, 2, $evidence)->state_version);
        foreach ([
            ['provider_payment_id' => 'DIFFERENT'], ['status' => Operation::FAILED],
            ['resolution_code' => 'different'], ['resolved_at' => now()->addDay()], ['api_request_log_id' => null],
            ['submitted_at' => null], ['baseline_complete' => true],
        ] as $changes) {
            $this->assertDatabaseRejects(fn () => $resolved->operation()->firstOrFail()->update($changes));
        }
        $this->assertDatabaseRejects(fn () => $resolved->newQuery()->whereKey($resolved->operation_id)->update([
            'state_version' => 3, 'outcome' => json_encode(['provider_payment_id' => 'DIFFERENT']),
        ]));
        $this->assertDatabaseRejects(fn () => $evidence->update(['response_status' => 400]));
        $this->assertDatabaseRejects(fn () => $evidence->niumExchangeEvidence()->firstOrFail()->update(['raw_response_body' => '{}']));
        $this->assertDatabaseRejects(fn () => $evidence->niumExchangeEvidence()->delete());
        $conflicting = $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'DIFFERENT']);
        foreach ([[$conflicting, 2], [$evidence, 1]] as [$proof, $version]) {
            try {
                $ledger->resolveFromPostEvidence($resolved, $version, $proof);
                $this->fail('Conflicting evidence or a stale version cannot rewrite a terminal result.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('conflicting terminal', $exception->getMessage());
            }
        }
        $this->assertSame('VA-LEDGER-ONE', $resolved->operation()->value('provider_payment_id'));
    }

    public function test_provider_environment_comes_from_origin_and_aliases_do_not_split_scope(): void
    {
        $account = $this->ledgerAccount();
        config(['app.env' => 'prod', 'services.nium.base_url' => 'https://NIUM.test:443/']);
        $first = $this->ledgerIdentity($account);
        config(['app.env' => 'production', 'services.nium.base_url' => 'https://nium.test']);
        $second = $this->ledgerIdentity($account);
        $this->assertTrue($first->matches($second));
        $this->assertSame($first->scopeKey(), $second->scopeKey());
        $this->assertSame('https://nium.test', $first->snapshot()['provider_origin']);
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'trusted-origin', $first);
        config(['services.nium.base_url' => 'https://different-environment.test']);
        try {
            $ledger->consumeDispatchAuthorization($request, 0);
            $this->fail('Changed provider origin must not consume authorization.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, NiumVirtualAccountDispatchAttempt::query()->count());
        }
    }

    public function test_bank_is_frozen_even_if_user_profile_or_bank_mapping_inputs_change(): void
    {
        $account = $this->ledgerAccount();
        $account->user->profile()->create(['user_type' => 'business', 'country_code' => 'HK']);
        $resolver = new NiumPaymentIdBankResolver;
        $identity = $this->ledgerIdentity($account, ['payload' => [
            'currency' => 'USD', 'accountCategory' => 'COLLECTION_ACCOUNT', 'accountType' => 'LOCAL',
            'bankName' => $resolver->resolve($account->user, 'USD'),
        ]]);
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'frozen-bank', $identity);
        $account->user->profile()->update(['country_code' => 'US']);
        $this->assertNull($resolver->resolve($account->user->fresh(), 'USD'));
        $this->assertNotNull($ledger->consumeDispatchAuthorization($request, 0));
        $this->assertSame('DBS_HK', $request->fresh()->identity()->snapshot()['payload']['bankName']);
        $this->assertDatabaseRejects(fn () => $request->operation()->update(['bank_name' => 'OTHER']));
    }

    #[DataProvider('legacyBarrierStates')]
    public function test_read_only_barrier_blocks_unresolved_and_ambiguous_legacy_operations_across_accounts(string $state): void
    {
        $account = $this->ledgerAccount();
        $other = $this->ledgerAccount($account->external_account_id);
        $legacy = $this->legacyLedgerOperation($other, $state);
        $before = $legacy->fresh()->getAttributes();
        $identity = $this->ledgerIdentity($account);
        $ledger = new NiumVirtualAccountSafetyLedger;
        DB::enableQueryLog();
        $result = $ledger->legacyOperationEligibility($identity);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertFalse($result['eligible']);
        $this->assertSame([$legacy->id], $result['blocking_operation_ids']);
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select', strtolower($query['query']));
        }
        try {
            $ledger->prepare($account, 'new-key-cannot-bypass', $identity);
            $this->fail('A new operation key cannot bypass unresolved legacy work.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Legacy unresolved', $exception->getMessage());
        }
        $this->assertSame($before, $legacy->fresh()->getAttributes());
        $this->assertSame(0, NiumVirtualAccountRequest::query()->count());
        $this->assertSame(0, NiumVirtualAccountAssignmentScope::query()->count());
    }

    public static function legacyBarrierStates(): array
    {
        return array_map(fn ($state) => [$state], ['claimed', 'preflight_failed', 'submitting', 'pending', 'unknown', 'manual_review', 'failed', 'unrecognized']);
    }

    public function test_conclusively_assigned_legacy_operation_allows_new_intentional_requests_but_same_key_still_rejects(): void
    {
        $account = $this->ledgerAccount();
        $legacy = $this->legacyLedgerOperation($account, Operation::ASSIGNED, [
            'provider_payment_id' => 'VA-LEGACY', 'resolved_at' => now(),
        ]);
        $ledger = new NiumVirtualAccountSafetyLedger;
        $identity = $this->ledgerIdentity($account);
        $this->assertTrue($ledger->legacyOperationEligibility($identity)['eligible']);
        $request = $ledger->prepare($account, 'intentional-after-legacy', $identity);
        $this->assertNotNull($ledger->consumeDispatchAuthorization($request, 0));
        $ledger->resolveFromPostEvidence($request, 1, $this->ledgerResponseEvidence($request));
        $next = $ledger->prepare($account, 'intentional-next', $identity);
        $this->assertNotNull($ledger->consumeDispatchAuthorization($next, 0));
        try {
            $ledger->prepare($account, $legacy->operation_id, $identity);
            $this->fail('Even a resolved legacy operation must not be enrolled.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cannot be enrolled', $exception->getMessage());
        }
    }

    public function test_legacy_barrier_is_rechecked_immediately_before_consumption(): void
    {
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'legacy-late', $this->ledgerIdentity($account));
        $this->legacyLedgerOperation($account, Operation::UNKNOWN);
        try {
            $ledger->consumeDispatchAuthorization($request, 0);
            $this->fail('Legacy work appearing after preparation must block consumption.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Legacy unresolved', $exception->getMessage());
        }
        $this->assertSame(0, NiumVirtualAccountDispatchAttempt::query()->count());
    }

    public function test_forward_only_migration_never_drops_safety_history(): void
    {
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'no-rollback', $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        $migration = require database_path('migrations/2026_10_27_000001_create_nium_va_safety_ledger.php');
        try {
            $migration->down();
            $this->fail('Safety history must never be dropped.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('rollback is forbidden', $exception->getMessage());
        }
        $this->assertSame(1, NiumVirtualAccountDispatchAttempt::query()->count());
        $this->assertNull($ledger->consumeDispatchAuthorization($request, 1));
    }

    #[DataProvider('changedTargetFields')]
    public function test_changed_trusted_target_or_configuration_cannot_consume(string $field): void
    {
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'target-drift', $this->ledgerIdentity($account));
        match ($field) {
            'wallet' => $account->update(['external_account_id' => (string) Str::uuid()]),
            'customer' => $account->update(['external_customer_id' => (string) Str::uuid()]),
            'client' => config(['services.nium.client_id' => (string) Str::uuid()]),
            'endpoint' => config(['services.nium.assign_payment_id_endpoint' => '/api/v1/changed']),
        };
        try {
            $ledger->consumeDispatchAuthorization($request, 0);
            $this->fail('Target/configuration drift cannot authorize dispatch.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, NiumVirtualAccountDispatchAttempt::query()->count());
        }
        $this->assertSame(0, $request->fresh()->state_version);
        $this->assertSame($request->operation_id, $request->assignmentScope()->value('active_operation_id'));
    }

    public static function changedTargetFields(): array
    {
        return [['wallet'], ['customer'], ['client'], ['endpoint']];
    }

    public function test_unknown_wallet_mapping_and_incomplete_assigned_legacy_evidence_fail_closed(): void
    {
        $account = $this->ledgerAccount();
        $other = $this->ledgerAccount();
        $other->update(['external_account_id' => null]);
        $legacy = $this->legacyLedgerOperation($other, Operation::ASSIGNED, ['provider_payment_id' => 'INITIALIZED', 'resolved_at' => now()]);
        $ledger = new NiumVirtualAccountSafetyLedger;
        $identity = $this->ledgerIdentity($account);
        $this->assertSame([$legacy->id], $ledger->legacyOperationEligibility($identity)['blocking_operation_ids']);
        $this->assertDatabaseRejects(fn () => NiumVirtualAccountAssignmentScope::query()->create([
            'scope_key' => $identity->scopeKey(), 'active_operation_id' => $legacy->id,
        ]));
    }

    private function assertDatabaseRejects(callable $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('The database must enforce the ledger invariant.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
