<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Models\NiumApiExchangeEvidence;
use App\Models\NiumVirtualAccount;
use App\Models\NiumVirtualAccountDetailEnrichment as Enrichment;
use App\Models\NiumVirtualAccountDispatchAttempt;
use App\Models\NiumVirtualAccountOperation as Operation;
use App\Models\NiumVirtualAccountRequest;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumPaymentIdService;
use App\Services\Nium\NiumVirtualAccountDetailEnrichmentService as EnrichmentService;
use App\Services\Nium\NiumVirtualAccountSafetyLedger;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesNiumVirtualAccountLedgerFixtures;
use Tests\TestCase;

class NiumVirtualAccountSingleDispatchTest extends TestCase
{
    use CreatesNiumVirtualAccountLedgerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.nium_dispatch_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('nium_dispatch_test');
        $this->artisan('migrate', ['--database' => 'nium_dispatch_test', '--force' => true])->assertSuccessful();
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
        DB::purge('nium_dispatch_test');
        parent::tearDown();
    }

    private function assignmentAccount(): UserProviderAccount
    {
        $account = $this->ledgerAccount();
        config(['services.nium.va_single_dispatch.account_ids' => [$account->id]]);

        return $account;
    }

    private function assign(UserProviderAccount $account, string $key = 'single-dispatch', string $currency = 'USD'): NiumVirtualAccount|Operation
    {
        return app(NiumPaymentIdService::class)->assign($account, $currency, 'COLLECTION_ACCOUNT', 'LOCAL', operationId: $key);
    }

    public function test_first_assignment_commits_authorization_before_one_v2_post_without_get(): void
    {
        $account = $this->assignmentAccount();
        $account->user->profile()->create(['user_type' => 'business', 'country_code' => 'HK']);
        Http::fake(function ($outgoing, $options) use ($account) {
            $this->assertSame('POST', $outgoing->method());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $request = NiumVirtualAccountRequest::query()->sole();
            $this->assertSame(1, $request->dispatchAttempt()->count());
            $this->assertSame(Operation::SUBMITTING, $request->operation_state);
            $this->assertFalse($request->operation->baseline_complete);
            $this->assertNull($request->operation->baseline_payment_ids);
            $this->assertSame(0, NiumVirtualAccount::query()->count());
            $this->assertSame([$request->provider_request_id], $outgoing->header('x-request-id'));
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame($request->identity()->snapshot()['payload'], $outgoing->data());
            $this->assertSame('DBS_HK', $outgoing['bankName']);
            $this->assertStringContainsString('/api/v2/', $outgoing->url());
            $this->assertStringContainsString($account->external_account_id, $outgoing->url());

            return Http::response(['uniquePaymentId' => 'VA-SINGLE-ONE']);
        });
        $va = $this->assign($account);
        $this->assertInstanceOf(NiumVirtualAccount::class, $va);
        $this->assertSame('VA-SINGLE-ONE', $va->provider_payment_id);
        $this->assertSame($va->id, Enrichment::query()->sole()->nium_virtual_account_id);
        $this->assertSame(1, DB::table('jobs')->where('queue', EnrichmentService::QUEUE)->count());
        $request = NiumVirtualAccountRequest::query()->sole();
        $this->assertSame($va->id, $request->operation->nium_virtual_account_id);
        $this->assertNull($request->assignmentScope()->value('active_operation_id'));
        $exchange = NiumApiExchangeEvidence::query()->sole();
        $this->assertSame('https://nium.test'.$request->identity()->snapshot()['endpoint'], $exchange->assignment_target);
        $this->assertStringNotContainsString($account->external_account_id, $exchange->request_url);
        $this->assertStringNotContainsString($account->external_account_id, DB::table('nium_api_exchange_evidence')->value('assignment_target'));
        $this->assertArrayNotHasKey('assignment_target', $exchange->toArray());
        $this->assertSame($va->id, $this->assign($account)->id);
        Http::assertSentCount(1);
    }

    #[DataProvider('ambiguousResponses')]
    public function test_ambiguous_responses_retain_scope_and_never_repeat_http(array|string $body, int $status, string $state = Operation::UNKNOWN): void
    {
        $account = $this->assignmentAccount();
        Http::fake(fn () => Http::response($body, $status, ['Location' => 'https://redirect.test/unsafe']));
        $operation = $this->assign($account);
        $this->assertInstanceOf(Operation::class, $operation);
        $this->assertSame($state, $operation->status);
        $this->assertNull($operation->provider_payment_id);
        $this->assertNull($operation->resolved_at);
        $this->assertNull($operation->nium_virtual_account_id);
        $this->assertSame(0, Enrichment::query()->count());
        $this->assertSame(0, DB::table('jobs')->where('queue', EnrichmentService::QUEUE)->count());
        $request = NiumVirtualAccountRequest::query()->sole();
        $this->assertSame($operation->id, $request->assignmentScope()->value('active_operation_id'));
        $this->assertSame($operation->id, $this->assign($account)->id);
        $this->assertSame(1, ApiRequestLog::query()->count());
        $this->assertSame(1, NiumApiExchangeEvidence::query()->count());
        $this->assertSame(0, NiumVirtualAccount::query()->count());
        try {
            $this->assign($account, 'another-key');
            $this->fail('A different key cannot bypass unresolved ownership.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('unresolved assignment', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public static function ambiguousResponses(): array
    {
        return [
            'missing' => [[], 200], 'newline reproducer' => [['uniquePaymentId' => "VA\nINVALID"], 200],
            'integer' => [['uniquePaymentId' => 123], 200], 'blank' => [['uniquePaymentId' => ' '], 200],
            'INITIALIZED' => [['uniquePaymentId' => 'INITIALIZED'], 200, Operation::PENDING],
            'mixed initialized' => [['uniquePaymentId' => 'initialized'], 200, Operation::PENDING],
            'contradictory initialized' => [['uniquePaymentId' => 'INITIALIZED', 'currencyCode' => 'EUR'], 200],
            'malformed JSON' => ['{broken', 200], 'redirect' => [[], 307],
            '408' => [[], 408], '409' => [[], 409], '429' => [[], 429], '500' => [[], 500], '503' => [[], 503],
            'unreviewed 400' => [['errors' => [['code' => 'invalid_input', 'field' => 'currency']]], 400],
        ];
    }

    #[DataProvider('contradictoryDimensions')]
    public function test_contradictory_success_stays_unresolved_in_transport_and_direct_ledger_resolution(array $dimensions): void
    {
        $account = $this->assignmentAccount();
        $account->user->profile()->create(['user_type' => 'business', 'country_code' => 'HK']);
        $body = ['uniquePaymentId' => 'VA-CONTRADICTORY'] + $dimensions;
        Http::fake(fn () => Http::response($body));

        $operation = $this->assign($account);
        $this->assertSame(Operation::UNKNOWN, $operation->status);
        $request = NiumVirtualAccountRequest::query()->sole();
        $log = ApiRequestLog::query()->sole();
        $rawEvidence = $log->niumExchangeEvidence->raw_response_body;
        $resolved = (new NiumVirtualAccountSafetyLedger)->resolveFromPostEvidence($request, 2, $log, attachVirtualAccount: true);
        $this->assertSame(Operation::UNKNOWN, $resolved->operation_state);
        $this->assertSame(2, $resolved->state_version);
        $this->assertNull($resolved->outcome);
        $this->assertNull($operation->fresh()->provider_payment_id);
        $this->assertNull($operation->fresh()->nium_virtual_account_id);
        $this->assertNull($operation->fresh()->resolved_at);
        $this->assertSame($operation->id, $resolved->assignmentScope()->value('active_operation_id'));
        $this->assertSame(0, NiumVirtualAccount::query()->count());
        $this->assertSame($body, json_decode($rawEvidence, true));
        $this->assertSame($rawEvidence, $log->fresh()->niumExchangeEvidence->raw_response_body);
        $this->assertSame($operation->id, $this->assign($account)->id);
        try {
            $this->assign($account, 'contradictory-new-key');
            $this->fail('Contradictory evidence cannot release the scope to another key.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('unresolved assignment', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public static function contradictoryDimensions(): array
    {
        return [
            'currencyCode' => [['currencyCode' => 'EUR']],
            'currency alias' => [['currency' => 'EUR']],
            'conflicting aliases' => [['currencyCode' => 'USD', 'currency' => 'EUR']],
            'category' => [['accountCategory' => 'SELF_FUNDING_ACCOUNT']],
            'type' => [['accountType' => 'WIRES']],
            'client' => [['clientHashId' => 'other-client']],
            'wallet' => [['walletHashId' => '22222222-2222-4222-8222-222222222222']],
            'customer' => [['customerHashId' => '33333333-3333-4333-8333-333333333333']],
            'bank' => [['bankName' => 'JPM_SG']],
            'case is not normalized' => [['currencyCode' => 'usd']],
            'whitespace is not trimmed' => [['bankName' => 'DBS_HK ']],
            'present null' => [['walletHashId' => null]],
            'present non-string' => [['accountCategory' => ['COLLECTION_ACCOUNT']]],
        ];
    }

    #[DataProvider('optionalDimensions')]
    public function test_valid_success_accepts_omitted_or_exact_optional_dimensions(bool $includeDimensions): void
    {
        $account = $this->assignmentAccount();
        $account->user->profile()->create(['user_type' => 'business', 'country_code' => 'HK']);
        Http::fake(function () use ($account, $includeDimensions) {
            return Http::response(['uniquePaymentId' => 'VA-OPTIONAL-DIMENSIONS'] + ($includeDimensions ? [
                'currencyCode' => 'USD', 'currency' => 'USD', 'accountCategory' => 'COLLECTION_ACCOUNT',
                'accountType' => 'LOCAL', 'bankName' => 'DBS_HK',
                'clientHashId' => NiumVirtualAccountRequest::query()->sole()->identity()->snapshot()['client_id'],
                'walletHashId' => $account->external_account_id, 'customerHashId' => $account->external_customer_id,
            ] : []));
        });
        $this->assertInstanceOf(NiumVirtualAccount::class, $this->assign($account));
        $request = NiumVirtualAccountRequest::query()->sole();
        $this->assertSame(Operation::ASSIGNED, $request->operation_state);
        $this->assertNull($request->assignmentScope()->value('active_operation_id'));
        Http::assertSentCount(1);
    }

    public static function optionalDimensions(): array
    {
        return [[false], [true]];
    }

    public function test_a_reported_bank_without_a_frozen_bank_remains_unresolved(): void
    {
        $account = $this->assignmentAccount();
        Http::fake(fn () => Http::response(['uniquePaymentId' => 'VA-UNVERIFIED-BANK', 'bankName' => 'JPM_SG']));
        $this->assertSame(Operation::UNKNOWN, $this->assign($account)->status);
        $request = NiumVirtualAccountRequest::query()->sole();
        $this->assertNotNull($request->assignmentScope()->value('active_operation_id'));
        $this->assertNull($request->operation()->value('provider_payment_id'));
    }

    #[DataProvider('bankProfileChanges')]
    public function test_controller_same_key_replay_uses_the_frozen_bank_after_profile_changes(string $before, string $after): void
    {
        $account = $this->assignmentAccount();
        $profile = $account->user->profile()->create(['user_type' => 'business', 'country_code' => $before]);
        $token = 'isolated-bank-replay-token';
        ApiToken::query()->create([
            'user_id' => $account->user_id, 'name' => 'bank-replay',
            'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(),
        ]);
        Http::fake(fn () => Http::response(['uniquePaymentId' => 'INITIALIZED']));
        $path = '/api/user/users/'.$account->user_id.'/provider-accounts/nium/virtual-account';
        $body = ['currency' => 'USD', 'account_category' => 'COLLECTION_ACCOUNT', 'account_type' => 'LOCAL'];
        $this->withToken($token)->withHeader('Idempotency-Key', 'bank-replay')->postJson($path, $body)
            ->assertStatus(202)->assertJsonPath('operation.status', Operation::PENDING);
        $request = NiumVirtualAccountRequest::query()->sole();
        $frozen = $request->snapshot;
        $profile->update(['country_code' => $after]);

        $this->withToken($token)->withHeader('Idempotency-Key', 'bank-replay')->postJson($path, $body)
            ->assertStatus(202)->assertJsonPath('operation.status', Operation::PENDING);
        $this->assertSame($frozen, $request->fresh()->snapshot);
        $this->assertSame($before === 'HK' ? 'DBS_HK' : null, $frozen['payload']['bankName'] ?? null);
        $this->withToken($token)->withHeader('Idempotency-Key', 'bank-replay')->postJson($path, array_replace($body, ['account_type' => 'WIRES']))
            ->assertStatus(422);
        Http::assertSentCount(1);
    }

    public static function bankProfileChanges(): array
    {
        return [['SG', 'HK'], ['HK', 'SG']];
    }

    public function test_explicit_bank_change_conflicts_with_the_same_key(): void
    {
        $account = $this->assignmentAccount();
        Http::fake(fn () => Http::response(['uniquePaymentId' => 'INITIALIZED']));
        $service = app(NiumPaymentIdService::class);
        $service->assign($account, 'USD', 'COLLECTION_ACCOUNT', 'LOCAL', 'DBS_HK', 'explicit-bank');
        try {
            $service->assign($account, 'USD', 'COLLECTION_ACCOUNT', 'LOCAL', 'JPM_SG', 'explicit-bank');
            $this->fail('An explicit bank change must conflict.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('immutable assignment', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_changed_payload_cannot_reuse_the_same_key(): void
    {
        $account = $this->assignmentAccount();
        Http::fake(fn () => Http::response(['uniquePaymentId' => 'VA-BOUND']));
        $this->assign($account);
        try {
            $this->assign($account, currency: 'EUR');
            $this->fail('Changed payload must conflict with immutable identity.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('immutable assignment', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_legacy_unresolved_operation_blocks_without_get_or_post(): void
    {
        $account = $this->assignmentAccount();
        $this->legacyLedgerOperation($account, Operation::UNKNOWN);
        try {
            $this->assign($account);
            $this->fail('Legacy ambiguity must block dispatch.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Legacy unresolved', $exception->getMessage());
        }
        $this->assertSame(0, NiumVirtualAccountDispatchAttempt::query()->count());
        Http::assertNothingSent();
    }

    #[DataProvider('crashStates')]
    public function test_replay_after_preparation_or_committed_authorization_never_dispatches(bool $consumed): void
    {
        $account = $this->assignmentAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'single-dispatch', $this->ledgerIdentity($account, ['payload' => [
            'currency' => 'USD', 'accountCategory' => 'COLLECTION_ACCOUNT', 'accountType' => 'LOCAL',
        ]]));
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

    public function test_timeout_after_hypothetical_acceptance_is_never_retried(): void
    {
        $account = $this->assignmentAccount();
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('cURL error 28: timed out after provider acceptance');
        });
        $this->assertSame(Operation::UNKNOWN, $this->assign($account)->status);
        $this->assertSame(Operation::UNKNOWN, $this->assign($account)->status);
        $this->assertSame(1, $calls);
        $this->assertSame(1, ApiRequestLog::query()->count());
        $this->assertFalse(NiumApiExchangeEvidence::query()->sole()->response_received);
    }

    public function test_evidence_persistence_failure_is_unresolved_and_cannot_authorize_another_post(): void
    {
        $account = $this->assignmentAccount();
        Http::fake(fn () => Http::response(['uniquePaymentId' => 'VA-ACCEPTED']));
        DB::connection()->beforeExecuting(function ($query): void {
            if (str_starts_with($query, 'insert into "nium_api_exchange_evidence"')) {
                throw new RuntimeException('Injected encrypted evidence failure');
            }
        });
        $this->assertSame(Operation::UNKNOWN, $this->assign($account)->status);
        $this->assertSame(Operation::UNKNOWN, $this->assign($account)->status);
        $this->assertSame(0, ApiRequestLog::query()->count());
        $this->assertSame(0, NiumVirtualAccount::query()->count());
        Http::assertSentCount(1);
    }

    public function test_generic_transport_exception_keeps_evidence_and_never_retries(): void
    {
        $account = $this->assignmentAccount();
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new RuntimeException('Injected transport failure after possible acceptance');
        });
        $this->assertSame(Operation::UNKNOWN, $this->assign($account)->status);
        $this->assertSame(Operation::UNKNOWN, $this->assign($account)->status);
        $this->assertSame(1, $calls);
        $this->assertSame(1, ApiRequestLog::query()->count());
        $this->assertFalse(NiumApiExchangeEvidence::query()->sole()->response_received);
    }

    public function test_late_http_response_cannot_overwrite_an_already_verified_terminal_assignment(): void
    {
        $account = $this->assignmentAccount();
        Http::fake(function () {
            $request = NiumVirtualAccountRequest::query()->sole();
            $evidence = $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-VERIFIED-WINNER']);
            (new NiumVirtualAccountSafetyLedger)->resolveFromPostEvidence($request, 1, $evidence, attachVirtualAccount: true);

            return Http::response(['uniquePaymentId' => 'VA-STALE-HTTP-LOSER']);
        });
        $this->assertSame('VA-VERIFIED-WINNER', $this->assign($account)->provider_payment_id);
        $this->assertSame('VA-VERIFIED-WINNER', $this->assign($account)->provider_payment_id);
        $this->assertSame(1, NiumVirtualAccount::query()->count());
        $this->assertSame(2, NiumApiExchangeEvidence::query()->count());
        $this->assertNull(NiumVirtualAccountRequest::query()->sole()->assignmentScope()->value('active_operation_id'));
        Http::assertSentCount(1);
    }

    public function test_unresolved_api_and_cli_calls_return_operation_state_without_fabricating_a_va(): void
    {
        $account = $this->assignmentAccount();
        $token = 'isolated-single-dispatch-test-token';
        ApiToken::query()->create([
            'user_id' => $account->user_id, 'name' => 'test-token',
            'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(),
        ]);
        Http::fake(fn () => Http::response(['uniquePaymentId' => 'INITIALIZED']));
        $path = '/api/user/users/'.$account->user_id.'/provider-accounts/nium/virtual-account';
        for ($call = 0; $call < 2; $call++) {
            $this->withToken($token)->withHeader('Idempotency-Key', 'api-pending')->postJson($path, [
                'currency' => 'USD', 'account_category' => 'COLLECTION_ACCOUNT', 'account_type' => 'LOCAL',
            ])->assertStatus(202)->assertJsonPath('operation.status', Operation::PENDING)->assertJsonPath('virtual_account', null);
        }
        $this->artisan('nium:assign-payment-id', [
            'account' => $account->id, 'currency' => 'USD', '--account-category' => 'COLLECTION_ACCOUNT',
            '--account-type' => 'LOCAL', '--operation-id' => 'api-pending',
        ])->expectsOutput('Operation ID: api-pending')->expectsOutput('Assignment state: pending; do not repeat the provider POST.')->assertSuccessful();
        $this->assertSame(0, NiumVirtualAccount::query()->count());
        Http::assertSentCount(1);
    }

    public function test_failure_after_acceptance_rolls_back_assignment_and_attachment_together(): void
    {
        $account = $this->assignmentAccount();
        Http::fake(fn () => Http::response(['uniquePaymentId' => 'VA-ACCEPTED-BEFORE-LOCAL-FAILURE']));
        DB::connection()->beforeExecuting(function ($query): void {
            if (str_starts_with($query, 'insert into "nium_virtual_accounts"')) {
                throw new RuntimeException('Injected crash before local VA persistence');
            }
        });
        $this->assertSame(Operation::UNKNOWN, $this->assign($account)->status);
        $request = NiumVirtualAccountRequest::query()->sole();
        $this->assertNull($request->outcome);
        $this->assertSame($request->operation_id, $request->assignmentScope()->value('active_operation_id'));
        $this->assertSame(0, NiumVirtualAccount::query()->count());
        $this->assertSame(1, NiumApiExchangeEvidence::query()->count());
        $this->assign($account);
        Http::assertSentCount(1);
    }

    public function test_existing_webhook_va_is_preserved_and_requires_phase3_resolution(): void
    {
        $account = $this->assignmentAccount();
        $existing = null;
        Http::fake(function () use ($account, &$existing) {
            $existing = NiumVirtualAccount::query()->create([
                'user_provider_account_id' => $account->id, 'provider_payment_id' => 'VA-WEBHOOK-FIRST',
                'currency' => 'USD', 'account_category' => 'COLLECTION_ACCOUNT', 'account_type' => 'LOCAL', 'status' => 'assigned',
            ]);
            $existing->refresh();

            return Http::response(['uniquePaymentId' => 'VA-WEBHOOK-FIRST']);
        });
        $this->assertSame(Operation::MANUAL_REVIEW, $this->assign($account)->status);
        $this->assertSame($existing->getAttributes(), $existing->fresh()->getAttributes());
        $this->assertSame(1, NiumVirtualAccount::query()->count());
        $this->assertNotNull(NiumVirtualAccountRequest::query()->sole()->assignmentScope()->value('active_operation_id'));
    }

    public function test_sequential_usd_assignments_and_terminal_guards(): void
    {
        $account = $this->assignmentAccount();
        Http::fakeSequence()->push(['uniquePaymentId' => 'VA-USD-ONE'])->push(['uniquePaymentId' => 'VA-USD-TWO']);
        $first = $this->assign($account, 'usd-one');
        $second = $this->assign($account, 'usd-two');
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, NiumVirtualAccount::query()->count());
        $this->assertSame(2, NiumVirtualAccountDispatchAttempt::query()->count());
        $request = NiumVirtualAccountRequest::query()->orderBy('operation_id')->firstOrFail();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $log = $request->operation->apiRequestLog;
        $this->assertSame(2, $ledger->resolveFromPostEvidence($request, 2, $log, attachVirtualAccount: true)->state_version);
        foreach ([['nium_virtual_account_id' => $second->id], ['provider_payment_id' => 'CONFLICT'], ['status' => Operation::UNKNOWN]] as $changes) {
            try {
                $request->operation->update($changes);
                $this->fail('Terminal identity cannot change.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            $ledger->resolveFromPostEvidence($request, 2, $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-LATE-CONFLICT']), attachVirtualAccount: true);
            $this->fail('Conflicting late evidence cannot rewrite assignment.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('conflicting terminal', $exception->getMessage());
        }
        Http::assertSentCount(2);
    }

    #[DataProvider('gateInterlocks')]
    public function test_gate_requires_test_environment_account_allowlist_and_old_worker_fence(string $interlock): void
    {
        $account = $this->assignmentAccount();
        match ($interlock) {
            'environment' => $this->app->instance('env', 'production'),
            'account' => config(['services.nium.va_single_dispatch.account_ids' => []]),
            'fence' => config(['services.nium.va_single_dispatch.old_workers_fenced' => false]),
        };
        try {
            $this->assign($account);
            $this->fail('Activation interlock must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('inactive', $exception->getMessage());
        }
        $this->assertSame(0, NiumVirtualAccountRequest::query()->count());
        Http::assertNothingSent();
    }

    public static function gateInterlocks(): array
    {
        return [['environment'], ['account'], ['fence']];
    }

    public function test_disabling_gate_returns_existing_state_and_never_falls_back_to_legacy_post(): void
    {
        $account = $this->assignmentAccount();
        Http::fake(fn () => Http::response([], 409));
        $this->assign($account);
        config(['services.nium.va_single_dispatch.enabled' => false]);
        $this->assertSame(Operation::UNKNOWN, $this->assign($account)->status);
        try {
            $this->assign($account, 'disabled-new-key');
            $this->fail('Disabling the gate cannot restore legacy writers for an enrolled wallet.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cannot fall back', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_disabled_gate_also_fences_other_accounts_with_case_variant_wallet_ids(): void
    {
        $account = $this->assignmentAccount();
        Http::fake(fn () => Http::response([], 409));
        $this->assign($account);
        $alias = $this->ledgerAccount(strtoupper($account->external_account_id));
        config(['services.nium.va_single_dispatch.enabled' => false]);
        try {
            $this->assign($alias, 'alias-wallet-new-key');
            $this->fail('A UUID case variant cannot bypass the rollback fence.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cannot fall back', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_wrong_encrypted_target_binding_cannot_resolve_or_release_scope(): void
    {
        $account = $this->assignmentAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'wrong-encrypted-target', $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        $evidence = $this->ledgerResponseEvidence($request);
        $evidence->niumExchangeEvidence->update(['assignment_target' => 'https://wrong-provider.test'.$request->identity()->snapshot()['endpoint']]);
        try {
            $ledger->resolveFromPostEvidence($request, 1, $evidence, attachVirtualAccount: true);
            $this->fail('An incorrect encrypted target binding cannot authorize resolution.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('does not verify', $exception->getMessage());
        }
        $this->assertSame(Operation::SUBMITTING, $request->fresh()->operation_state);
        $this->assertNull($request->operation()->value('provider_payment_id'));
        $this->assertSame($request->operation_id, $request->assignmentScope()->value('active_operation_id'));
        $this->assertSame(0, NiumVirtualAccount::query()->count());
        Http::assertNothingSent();
    }

    #[DataProvider('commitFailures')]
    public function test_uncertain_authorization_commit_never_dispatches(string $event, bool $committed): void
    {
        $account = $this->assignmentAccount();
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $dispatcher = clone $original;
        $dispatcher->listen($event, function (): void {
            if (NiumVirtualAccountDispatchAttempt::query()->exists()) {
                throw new RuntimeException('Injected authorization commit failure');
            }
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            $this->assign($account);
            $this->fail('Commit uncertainty must not authorize HTTP.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected authorization commit failure', $exception->getMessage());
        } finally {
            $connection->setEventDispatcher($original);
        }
        // SQLite :memory: disappears on the deliberate disconnect after uncertain commit;
        // PostgreSQL tests cover durable rows surviving actual worker exit/ack failures.
        Http::assertNothingSent();
    }

    public static function commitFailures(): array
    {
        return [[TransactionCommitting::class, false], [TransactionCommitted::class, true]];
    }
}
