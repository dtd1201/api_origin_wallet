<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\IntegrationProvider;
use App\Models\NiumVirtualAccount;
use App\Models\NiumVirtualAccountDetail;
use App\Models\NiumVirtualAccountDetailEnrichment as Enrichment;
use App\Models\NiumVirtualAccountOperation as Operation;
use App\Models\NiumVirtualAccountRequest;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumVirtualAccountSafetyLedger;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesNiumVirtualAccountLedgerFixtures;
use Tests\TestCase;

class NiumVirtualAccountOperationApiTest extends TestCase
{
    use CreatesNiumVirtualAccountLedgerFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        // A private, migrated database preserves all triggers without a test-wide
        // transaction: the ledger requires independent top-level durable commits.
        config(['database.connections.nium_operation_api_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('nium_operation_api_test');
        $this->artisan('migrate', ['--database' => 'nium_operation_api_test', '--force' => true])->assertSuccessful();
        $this->configureLedgerTarget();
        config([
            'services.nium.auth' => ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'synthetic-api-key'],
            'services.nium.webhook.static_header_name' => 'x-partner-key',
            'services.nium.webhook.static_header_value' => 'synthetic-partner-key',
            'services.nium.va_single_dispatch.enabled' => false,
            'services.nium.va_single_dispatch.old_workers_fenced' => false,
            'services.nium.va_single_dispatch.account_ids' => [],
        ]);
        $this->freezeTime();
        Http::preventStrayRequests();
        Http::fake();
    }

    protected function tearDown(): void
    {
        DB::purge('nium_operation_api_test');
        parent::tearDown();
    }

    #[DataProvider('unresolvedStates')]
    public function test_owned_enrolled_operations_are_read_without_http_or_workflow_writes(string $state): void
    {
        $account = $this->account();
        $request = $this->enrolled($account, $state);
        $this->authenticate($account->user);
        $before = $this->workflowSnapshot();

        $this->getJson($this->url($account))->assertOk()
            ->assertJsonCount(1, 'virtual_account_operations')
            ->assertJsonPath('virtual_account_operations.0', $this->expectedOperation($request->operation()->firstOrFail()))
            ->assertJsonPath('virtual_account_operations.0.provider_payment_id', null)
            ->assertJsonPath('virtual_account_operations.0.virtual_account_id', null);
        $this->getJson($this->url($account).'/virtual-accounts')->assertOk()->assertExactJson(['data' => []]);

        $this->assertSame($before, $this->workflowSnapshot());
        Http::assertNothingSent();
    }

    public static function unresolvedStates(): array
    {
        return [[Operation::CLAIMED], [Operation::SUBMITTING], [Operation::PENDING], [Operation::UNKNOWN], [Operation::MANUAL_REVIEW]];
    }

    public function test_assigned_operation_exposes_only_the_safe_persisted_projection(): void
    {
        $account = $this->account();
        [$operation, $va] = $this->assigned($account);
        $this->authenticate($account->user);
        $before = $this->workflowSnapshot();

        $this->getJson($this->url($account))->assertOk()
            ->assertJsonPath('virtual_account_operations.0', $this->expectedOperation($operation))
            ->assertJsonPath('virtual_account_operations.0.virtual_account_id', $va->id);

        $this->assertSame($before, $this->workflowSnapshot());
        Http::assertNothingSent();
    }

    public function test_legacy_failed_status_does_not_claim_provider_rejection_or_reconstruct_evidence(): void
    {
        $account = $this->account();
        $operation = $this->legacyLedgerOperation($account, Operation::FAILED, [
            'resolution_code' => 'provider_rejected_or_invalid_response',
            'baseline_payment_ids' => ['historical-private-payment'],
        ]);
        $this->authenticate($account->user);
        $before = $this->workflowSnapshot();

        $response = $this->getJson($this->url($account).'?operations_unresolved_only=1')->assertOk()
            ->assertJsonPath('virtual_account_operations.0', $this->expectedOperation($operation));
        $this->assertStringNotContainsString('provider_rejected', json_encode($response->json('virtual_account_operations')));
        $this->assertSame($before, $this->workflowSnapshot());
        $this->assertDatabaseCount('nium_va_requests', 0);
        Http::assertNothingSent();
    }

    public function test_enrolled_failed_operations_are_reported_but_excluded_from_unresolved_filter(): void
    {
        $account = $this->account();
        $request = $this->enrolled($account, Operation::SUBMITTING);
        $evidence = $this->ledgerResponseEvidence($request, ['errors' => [['code' => 'invalid_input', 'field' => 'currencyCode']]], 400);
        $request = app(NiumVirtualAccountSafetyLedger::class)->resolveFromPostEvidence($request, 1, $evidence, attachVirtualAccount: true);
        $this->authenticate($account->user);

        $this->getJson($this->url($account))->assertOk()
            ->assertJsonPath('virtual_account_operations.0', $this->expectedOperation($request->operation()->firstOrFail()))
            ->assertJsonPath('virtual_account_operations.0.status', Operation::FAILED);
        $this->getJson($this->url($account).'?operations_unresolved_only=1')->assertOk()
            ->assertJsonCount(0, 'virtual_account_operations');
        Http::assertNothingSent();
    }

    public function test_duplicate_keys_on_different_users_accounts_retain_owned_identity_and_allow_explicit_lookup(): void
    {
        // One row per user/provider is mandatory. Duplicate keys are representable
        // across different users' Nium accounts, never two accounts for one pair.
        $first = $this->account();
        $firstRequest = $this->enrolled($first, Operation::PENDING, 'same-key');
        $second = $this->account();
        $secondRequest = $this->enrolled($second, Operation::UNKNOWN, 'same-key');
        $this->authenticate($first->user);
        $before = $this->workflowSnapshot();

        $this->getJson($this->url($first).'?operation_id=same-key')->assertOk()
            ->assertJsonPath('provider_account.id', $first->id)
            ->assertJsonCount(1, 'virtual_account_operations')
            ->assertJsonPath('virtual_account_operations.0', $this->expectedOperation($firstRequest->operation()->firstOrFail()));
        $this->getJson($this->url($first).'?operation_id=same-key&provider_account_id='.$first->id)->assertOk()
            ->assertJsonCount(1, 'virtual_account_operations')
            ->assertJsonPath('virtual_account_operations.0.provider_account_id', $first->id);
        $this->getJson($this->url($first).'?operation_id=same-key&provider_account_id='.$second->id)->assertOk()
            ->assertJsonCount(0, 'virtual_account_operations');
        $this->authenticate($second->user);
        $this->getJson($this->url($second).'?operation_id=same-key')->assertOk()
            ->assertJsonCount(1, 'virtual_account_operations')
            ->assertJsonPath('virtual_account_operations.0', $this->expectedOperation($secondRequest->operation()->firstOrFail()));

        $this->assertSame($before, $this->workflowSnapshot());
        Http::assertNothingSent();
    }

    public function test_cross_user_and_provider_filters_cannot_expose_foreign_operations_or_banking(): void
    {
        $account = $this->account();
        $owned = $this->enrolled($account, Operation::PENDING);
        $other = $this->account();
        [$foreignOperation] = $this->assigned($other);
        $provider = IntegrationProvider::query()->create(['code' => 'other-api-provider', 'name' => 'Other', 'status' => 'active']);
        $otherProviderAccount = $account->user->providerAccounts()->create(['provider_id' => $provider->id, 'status' => 'active']);
        $this->legacyLedgerOperation($otherProviderAccount, Operation::UNKNOWN);
        $this->authenticate($account->user);

        $this->getJson($this->url($account))->assertOk()->assertJsonCount(1, 'virtual_account_operations')
            ->assertJsonPath('virtual_account_operations.0', $this->expectedOperation($owned->operation()->firstOrFail()));
        foreach ([$other->id, $otherProviderAccount->id, 999999] as $accountId) {
            $this->getJson($this->url($account).'?provider_account_id='.$accountId)->assertOk()
                ->assertJsonCount(0, 'virtual_account_operations');
            $this->getJson($this->url($account).'/virtual-accounts?provider_account_id='.$accountId)->assertOk()
                ->assertExactJson(['data' => []]);
        }
        // Pagination boundaries do not grant access to the foreign row either.
        $this->getJson($this->url($account).'?operations_before_id='.($foreignOperation->id + 1))->assertOk()
            ->assertJsonCount(1, 'virtual_account_operations');
        $this->getJson($this->url($other))->assertForbidden();
        $this->getJson($this->url($other).'/virtual-accounts')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_authentication_and_profile_middleware_still_guard_reads_and_create(): void
    {
        $account = $this->account();
        $this->getJson($this->url($account))->assertUnauthorized();
        $this->getJson($this->url($account).'/virtual-accounts')->assertUnauthorized();
        $this->postJson($this->url($account).'/virtual-account', $this->body())->assertUnauthorized();
        $account->user->update(['kyc_status' => 'pending']);
        $account->user->profile()->delete();
        $this->authenticate($account->user->fresh());

        $this->getJson($this->url($account))->assertStatus(409)->assertJsonPath('code', 'PROFILE_REQUIRED');
        $this->getJson($this->url($account).'/virtual-accounts')->assertStatus(409)->assertJsonPath('code', 'PROFILE_REQUIRED');
        $this->postJson($this->url($account).'/virtual-account', $this->body())->assertStatus(409)
            ->assertJsonPath('code', 'PROFILE_REQUIRED');
        Http::assertNothingSent();
    }

    public function test_missing_and_unsupported_providers_are_rejected(): void
    {
        $account = $this->account();
        IntegrationProvider::query()->create(['code' => 'unsupported-api-provider', 'name' => 'Unsupported', 'status' => 'active']);
        $this->authenticate($account->user);

        foreach (['missing-api-provider', 'unsupported-api-provider'] as $provider) {
            $url = '/api/user/users/'.$account->user_id.'/provider-accounts/'.$provider;
            $this->getJson($url)->assertNotFound();
            $this->getJson($url.'/virtual-accounts')->assertNotFound();
            $this->postJson($url.'/virtual-account', $this->body())->assertNotFound();
        }
        Http::assertNothingSent();
    }

    public function test_provider_with_no_account_has_empty_operation_and_va_collections(): void
    {
        $this->account();
        $user = User::factory()->create(['kyc_status' => 'verified']);
        $this->authenticate($user);
        $url = '/api/user/users/'.$user->id.'/provider-accounts/nium';

        $this->getJson($url)->assertOk()->assertJsonPath('provider_account', null)
            ->assertJsonCount(0, 'virtual_account_operations')
            ->assertJsonPath('virtual_account_operations_pagination.next_before_id', null);
        $this->getJson($url.'/virtual-accounts')->assertOk()->assertExactJson(['data' => []]);
        Http::assertNothingSent();
    }

    #[DataProvider('optionalPrecondition')]
    public function test_201_and_202_create_metadata_and_optional_precondition_compatibility(bool $precondition, bool $pending): void
    {
        $account = $this->account();
        $this->assertTrue($account->provider->isConfigured(), 'The isolated API fixture must satisfy the existing provider configuration guard.');
        $this->enableDispatch($account);
        $this->fakeProvider(fn () => Http::response(['uniquePaymentId' => $pending ? 'INITIALIZED' : 'API-ASSIGNED']));
        $this->authenticate($account->user);

        $response = $this->withHeader('Idempotency-Key', 'create-api-key')
            ->postJson($this->url($account).'/virtual-account', [
                ...$this->body(),
                ...($precondition ? ['expected_provider_account_id' => $account->id] : []),
            ])->assertStatus($pending ? 202 : 201)->assertJsonPath('operation_id', 'create-api-key');
        $operation = Operation::query()->sole();
        $response->assertJsonPath('operation', $this->expectedOperation($operation))
            ->assertJsonPath('operation.status', $pending ? Operation::PENDING : Operation::ASSIGNED);
        if ($pending) {
            $response->assertJsonPath('virtual_account', null);
            $this->assertDatabaseCount('nium_virtual_accounts', 0);
        } else {
            $response->assertJsonPath('virtual_account.id', $operation->nium_virtual_account_id);
        }
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST');
        $this->assertDatabaseCount('nium_va_dispatch_attempts', 1);

        // An ordinary GET and a disabled-gate replay return persisted state only.
        config(['services.nium.va_single_dispatch.enabled' => false]);
        $before = $this->workflowSnapshot();
        $this->getJson($this->url($account))->assertOk()
            ->assertJsonPath('virtual_account_operations.0', $response->json('operation'));
        $this->assertSame($before, $this->workflowSnapshot());
        $this->withHeader('Idempotency-Key', 'create-api-key')
            ->postJson($this->url($account).'/virtual-account', $this->body())->assertStatus($pending ? 202 : 201)
            ->assertJsonPath('operation', $response->json('operation'));
        Http::assertSentCount(1);
    }

    public static function optionalPrecondition(): array
    {
        return [[true, false], [true, true], [false, false], [false, true]];
    }

    #[DataProvider('incompleteConfigurationResponses')]
    public function test_incomplete_provider_configuration_preserves_existing_422_responses_without_dispatch(bool $singleDispatch, string $message): void
    {
        $account = $this->account();
        if ($singleDispatch) {
            $this->enableDispatch($account);
        }
        // This was missing from the original fixture. Keep the production guard intact.
        config(['services.nium.webhook.static_header_value' => null]);
        $this->assertFalse($account->provider->isConfigured());
        $this->authenticate($account->user);

        $this->withHeader('Idempotency-Key', 'incomplete-config-key')
            ->postJson($this->url($account).'/virtual-account', $this->body())
            ->assertUnprocessable()->assertExactJson(['message' => $message]);

        $this->assertDatabaseCount('nium_va_dispatch_attempts', 0);
        if ($singleDispatch) {
            $this->assertDatabaseCount('nium_virtual_account_operations', 0);
        } else {
            $this->assertDatabaseHas('nium_virtual_account_operations', [
                'user_provider_account_id' => $account->id,
                'operation_id' => 'incomplete-config-key',
                'status' => Operation::PREFLIGHT_FAILED,
            ]);
        }
        $before = $this->workflowSnapshot();
        $this->getJson($this->url($account))->assertOk();
        $this->getJson($this->url($account).'/virtual-accounts')->assertOk();
        $this->assertSame($before, $this->workflowSnapshot());
        Http::assertNothingSent();
    }

    public static function incompleteConfigurationResponses(): array
    {
        return [
            'single dispatch rejects configuration before preparing an operation' => [true, 'Nium integration configuration is incomplete or unsafe.'],
            'legacy baseline catches configuration failure before provider HTTP' => [false, 'Assign Payment ID preflight is unresolved; no provider POST was sent.'],
        ];
    }

    public function test_account_precondition_mismatch_preserves_an_existing_unresolved_operation(): void
    {
        $account = $this->account();
        $request = $this->enrolled($account, Operation::PENDING, 'lost-response-key');
        $this->enableDispatch($account);
        $this->authenticate($account->user);
        $before = $this->workflowSnapshot();

        $this->withHeader('Idempotency-Key', 'lost-response-key')
            ->postJson($this->url($account).'/virtual-account', [
                ...$this->body(), 'expected_provider_account_id' => $account->id + 100,
            ])->assertStatus(409)->assertExactJson([
                'message' => 'The selected provider account has changed. Refresh the account and operation status before continuing.',
                'code' => 'PROVIDER_ACCOUNT_MISMATCH',
            ]);

        $this->assertSame($before, $this->workflowSnapshot());
        $this->assertDatabaseCount('nium_virtual_account_operations', 1);
        $this->assertSame($account->id, $request->operation()->value('user_provider_account_id'));
        $this->assertDatabaseCount('nium_va_dispatch_attempts', 1); // Original fixture only.
        Http::assertNothingSent();
    }

    public function test_new_key_account_precondition_mismatch_creates_no_operation_or_dispatch_attempt(): void
    {
        $account = $this->account();
        $this->enableDispatch($account);
        $this->authenticate($account->user);
        $before = $this->workflowSnapshot();

        $this->withHeader('Idempotency-Key', 'new-key-mismatch')
            ->postJson($this->url($account).'/virtual-account', [
                ...$this->body(), 'expected_provider_account_id' => $account->id + 100,
            ])->assertStatus(409)->assertJsonPath('code', 'PROVIDER_ACCOUNT_MISMATCH');

        $this->assertSame($before, $this->workflowSnapshot());
        $this->assertDatabaseCount('nium_virtual_account_operations', 0);
        $this->assertDatabaseCount('nium_va_dispatch_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_create_replay_metadata_uses_the_selected_account_when_caller_keys_are_duplicated(): void
    {
        $other = $this->account();
        [$otherOperation] = $this->assigned($other);
        $current = $this->account();
        [$currentOperation] = $this->assigned($current);
        $this->authenticate($current->user);
        $before = $this->workflowSnapshot();

        $this->assertSame($otherOperation->operation_id, $currentOperation->operation_id);
        $this->withHeader('Idempotency-Key', $currentOperation->operation_id)
            ->postJson($this->url($current).'/virtual-account', [
                ...$this->body(), 'expected_provider_account_id' => $current->id,
            ])->assertCreated()->assertJsonPath('operation', $this->expectedOperation($currentOperation));

        $this->assertSame($before, $this->workflowSnapshot());
        Http::assertNothingSent();
    }

    #[DataProvider('invalidPreconditions')]
    public function test_supplied_invalid_account_precondition_cannot_be_ignored(mixed $expected): void
    {
        $account = $this->account();
        $this->authenticate($account->user);

        $this->withHeader('Idempotency-Key', 'invalid-precondition')
            ->postJson($this->url($account).'/virtual-account', [
                ...$this->body(), 'expected_provider_account_id' => $expected,
            ])->assertUnprocessable()->assertJsonValidationErrors('expected_provider_account_id');

        $this->assertDatabaseCount('nium_virtual_account_operations', 0);
        $this->assertDatabaseCount('nium_va_dispatch_attempts', 0);
        Http::assertNothingSent();
    }

    public static function invalidPreconditions(): array
    {
        return [[null], [''], [0], [-1], ['bad'], [1.5], [[]]];
    }

    public function test_matching_precondition_preserves_server_side_eligibility_checks(): void
    {
        $account = $this->account();
        $this->enableDispatch($account);
        $account->update(['security_conflict_at' => now()]);
        $this->authenticate($account->user);

        $this->withHeader('Idempotency-Key', 'ineligible-key')
            ->postJson($this->url($account).'/virtual-account', [
                ...$this->body(), 'expected_provider_account_id' => $account->id,
            ])->assertUnprocessable();

        $this->assertDatabaseCount('nium_virtual_account_operations', 0);
        $this->assertDatabaseCount('nium_va_dispatch_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_legacy_initialized_201_keeps_pending_operation_and_null_enrichment(): void
    {
        $account = $this->account();
        $this->assertTrue($account->provider->isConfigured(), 'The isolated API fixture must satisfy the existing provider configuration guard.');
        $this->fakeProvider(fn ($request) => Http::response($request->method() === 'GET'
            ? ['content' => [], 'totalPages' => 1, 'totalElements' => 0]
            : ['uniquePaymentId' => 'INITIALIZED']));
        $this->authenticate($account->user);

        $response = $this->withHeader('Idempotency-Key', 'legacy-initialized-key')
            ->postJson($this->url($account).'/virtual-account', $this->body())->assertCreated()
            ->assertJsonPath('virtual_account.status', 'pending')
            ->assertJsonPath('virtual_account.provider_payment_id', null)
            ->assertJsonPath('operation.status', Operation::PENDING);
        $response->assertJsonPath('operation', $this->expectedOperation(Operation::query()->sole()));
        $this->assertDatabaseCount('nium_va_requests', 0);
        $this->assertDatabaseCount('nium_va_detail_enrichments', 0);
        Http::assertSentCount(2); // Legacy baseline GET and assignment POST, before reads.
        $before = $this->workflowSnapshot();

        $this->getJson($this->url($account))->assertOk()
            ->assertJsonPath('virtual_account_operations.0', $response->json('operation'));
        $this->getJson($this->url($account).'/virtual-accounts')->assertOk()
            ->assertJsonPath('data.0.enrichment', ['status' => null, 'next_attempt_at' => null, 'updated_at' => null]);

        $this->assertSame($before, $this->workflowSnapshot());
        Http::assertSentCount(2);
    }

    public function test_legacy_va_without_operation_does_not_fabricate_assignment_or_enrichment(): void
    {
        $account = $this->account();
        $va = NiumVirtualAccount::query()->create([
            'user_provider_account_id' => $account->id, 'currency' => 'USD', 'status' => 'pending',
        ]);
        $this->authenticate($account->user);
        $before = $this->workflowSnapshot();

        $this->getJson($this->url($account))->assertOk()->assertJsonCount(0, 'virtual_account_operations');
        $this->getJson($this->url($account).'/virtual-accounts')->assertOk()
            ->assertJsonPath('data.0.id', $va->id)->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.enrichment', ['status' => null, 'next_attempt_at' => null, 'updated_at' => null]);

        $this->assertSame($before, $this->workflowSnapshot());
        Http::assertNothingSent();
    }

    #[DataProvider('enrichmentStates')]
    public function test_enrichment_is_safe_read_only_and_keeps_both_routing_code_pairs(string $state): void
    {
        $account = $this->account();
        [, $va] = $this->assigned($account);
        $task = Enrichment::query()->sole();
        $task->update([
            'state' => $state, 'available_at' => now()->addMinutes(5),
            'claim_token' => $state === 'running' ? (string) Str::uuid() : null,
            'lease_until' => $state === 'running' ? now()->addMinutes(2) : null,
            'last_result' => 'synthetic-private-result',
        ]);
        NiumVirtualAccountDetail::query()->create([
            'nium_virtual_account_id' => $va->id,
            'routing_code_type' => 'SWIFT', 'routing_code_value' => 'SYNTHETICXXX',
            'routing_code_type_2' => 'ABA (Wire)', 'routing_code_value_2' => '000000002',
            'provider_account_type' => 'GLOBAL',
        ]);
        $this->authenticate($account->user);
        $before = $this->workflowSnapshot();

        $response = $this->getJson($this->url($account).'/virtual-accounts')->assertOk()
            ->assertJsonPath('data.0.enrichment', [
                'status' => $state,
                'next_attempt_at' => $state === 'pending' ? $task->available_at->toISOString() : null,
                'updated_at' => $task->updated_at->toISOString(),
            ])
            ->assertJsonPath('data.0.detail.routing_code_type', 'SWIFT')
            ->assertJsonPath('data.0.detail.routing_code_value', 'SYNTHETICXXX')
            ->assertJsonPath('data.0.detail.routing_code_type_2', 'ABA (Wire)')
            ->assertJsonPath('data.0.detail.routing_code_value_2', '000000002')
            ->assertJsonPath('data.0.detail.provider_account_type', 'GLOBAL');
        $this->assertStringNotContainsString('synthetic-private-result', $response->getContent());
        $this->assertStringNotContainsString('claim_token', $response->getContent());
        $this->assertSame($before, $this->workflowSnapshot());
        Http::assertNothingSent();
    }

    public static function enrichmentStates(): array
    {
        return [['pending'], ['running'], ['complete'], ['exhausted']];
    }

    public function test_owned_account_can_read_operation_and_enrichment_history_when_no_longer_eligible(): void
    {
        $account = $this->account();
        [$operation, $va] = $this->assigned($account);
        $account->update(['status' => 'inactive']);
        $this->authenticate($account->user);
        $before = $this->workflowSnapshot();

        $this->getJson($this->url($account).'?provider_account_id='.$account->id)->assertOk()
            ->assertJsonPath('virtual_account_operations.0', $this->expectedOperation($operation));
        $this->getJson($this->url($account).'/virtual-accounts')->assertOk()
            ->assertJsonPath('data.0.id', $va->id)->assertJsonPath('data.0.enrichment.status', 'pending');
        $this->getJson($this->url($account).'/virtual-accounts?provider_account_id='.$account->id)->assertOk()
            ->assertJsonPath('data.0.id', $va->id)->assertJsonPath('data.0.enrichment.status', 'pending');

        $this->assertSame($before, $this->workflowSnapshot());
        Http::assertNothingSent();
    }

    public function test_discovery_is_bounded_and_ordered_by_immutable_id_even_with_equal_timestamps(): void
    {
        $account = $this->account();
        $pending = $this->enrolled($account, Operation::PENDING, 'old-pending-key');
        $keys = ['old-pending-key'];
        for ($i = 0; $i < 52; $i++) {
            $key = 'legacy-terminal-'.$i;
            $this->legacyLedgerOperation($account, Operation::ASSIGNED, ['operation_id' => $key]);
            $keys[] = $key;
        }
        $this->authenticate($account->user);
        $before = $this->workflowSnapshot();

        $first = $this->getJson($this->url($account))->assertOk()
            ->assertJsonCount(50, 'virtual_account_operations')
            ->assertJsonPath('virtual_account_operations_pagination.per_page', 50)
            ->assertJsonPath('virtual_account_operations_pagination.has_more', true);
        $cursor = $first->json('virtual_account_operations_pagination.next_before_id');
        $this->getJson($this->url($account))->assertOk()->assertJsonPath('virtual_account_operations', $first->json('virtual_account_operations'));
        $second = $this->getJson($this->url($account).'?operations_before_id='.$cursor)->assertOk()
            ->assertJsonCount(3, 'virtual_account_operations')
            ->assertJsonPath('virtual_account_operations_pagination.has_more', false)
            ->assertJsonPath('virtual_account_operations_pagination.next_before_id', null);
        $this->assertSame(array_reverse($keys), array_merge(
            $first->json('virtual_account_operations.*.operation_id'), $second->json('virtual_account_operations.*.operation_id'),
        ));
        $this->getJson($this->url($account).'?operations_unresolved_only=1')->assertOk()
            ->assertJsonCount(1, 'virtual_account_operations')
            ->assertJsonPath('virtual_account_operations.0', $this->expectedOperation($pending->operation()->firstOrFail()));
        $this->assertSame($before, $this->workflowSnapshot());
        Http::assertNothingSent();
    }

    #[DataProvider('invalidDiscoveryFilters')]
    public function test_invalid_discovery_filters_are_rejected(string $query): void
    {
        $account = $this->account();
        $this->authenticate($account->user);
        $this->getJson($this->url($account).'?'.$query)->assertUnprocessable();
        Http::assertNothingSent();
    }

    public static function invalidDiscoveryFilters(): array
    {
        return [['operations_before_id=0'], ['operations_before_id=bad'], ['operations_unresolved_only=bad'], ['provider_account_id=-1']];
    }

    private function account(): UserProviderAccount
    {
        $account = $this->ledgerAccount();
        $account->user->profile()->create(['user_type' => 'business', 'country_code' => 'HK']);

        return $account;
    }

    private function enrolled(UserProviderAccount $account, string $state, string $key = 'read-api-key'): NiumVirtualAccountRequest
    {
        $ledger = app(NiumVirtualAccountSafetyLedger::class);
        $request = $ledger->prepare($account, $key, $this->ledgerIdentity($account));
        if ($state !== Operation::CLAIMED) {
            $ledger->consumeDispatchAuthorization($request, 0);
            if ($state !== Operation::SUBMITTING) {
                $ledger->transition($request->fresh(), 1, $state);
            }
        }

        return $request->fresh();
    }

    private function assigned(UserProviderAccount $account): array
    {
        $request = $this->enrolled($account, Operation::SUBMITTING);
        $log = $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'API-VA-'.Str::uuid()]);
        $request = app(NiumVirtualAccountSafetyLedger::class)->resolveFromPostEvidence($request, 1, $log, attachVirtualAccount: true);
        $operation = $request->operation()->firstOrFail();

        return [$operation, $operation->virtualAccount()->firstOrFail()];
    }

    private function enableDispatch(UserProviderAccount $account): void
    {
        // Isolated test configuration only; no environment/feature activation.
        config([
            'services.nium.va_single_dispatch.enabled' => true,
            'services.nium.va_single_dispatch.old_workers_fenced' => true,
            'services.nium.va_single_dispatch.account_ids' => [$account->id],
        ]);
    }

    private function fakeProvider(callable $callback): void
    {
        // Laravel appends fake callbacks; replace the default read-test recorder.
        Http::swap(new HttpFactory(app('events')));
        Http::preventStrayRequests();
        Http::fake($callback);
    }

    private function authenticate(User $user): void
    {
        $plainToken = Str::random(80);
        ApiToken::query()->create([
            'user_id' => $user->id, 'name' => 'operation-api-test', 'token_hash' => hash('sha256', $plainToken),
            'last_used_at' => now(), 'expires_at' => now()->addDay(),
        ]);
        $this->withToken($plainToken);
    }

    private function url(UserProviderAccount $account): string
    {
        return '/api/user/users/'.$account->user_id.'/provider-accounts/nium';
    }

    private function body(): array
    {
        return ['currency' => 'USD', 'account_category' => 'COLLECTION_ACCOUNT', 'account_type' => 'LOCAL'];
    }

    private function expectedOperation(Operation $operation): array
    {
        return [
            'operation_id' => $operation->operation_id,
            'provider_account_id' => (int) $operation->user_provider_account_id,
            'status' => $operation->status,
            'currency' => $operation->currency,
            'provider_payment_id' => $operation->provider_payment_id,
            'virtual_account_id' => $operation->nium_virtual_account_id === null ? null : (int) $operation->nium_virtual_account_id,
            'updated_at' => $operation->updated_at->toISOString(),
        ];
    }

    private function workflowSnapshot(): array
    {
        $snapshot = [];
        foreach ([
            'user_provider_accounts', 'nium_virtual_account_operations', 'nium_va_requests', 'nium_va_dispatch_attempts',
            'nium_va_assignment_scopes', 'nium_virtual_accounts', 'nium_virtual_account_details', 'nium_va_detail_enrichments',
            'nium_va_detail_rate_limits', 'api_request_logs', 'nium_api_exchange_evidence', 'webhook_events', 'jobs',
        ] as $table) {
            $snapshot[$table] = DB::table($table)->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $snapshot;
    }
}
