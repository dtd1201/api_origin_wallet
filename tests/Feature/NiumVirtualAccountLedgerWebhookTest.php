<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\NiumVirtualAccount;
use App\Models\NiumVirtualAccountDetailEnrichment as Enrichment;
use App\Models\NiumVirtualAccountOperation as Operation;
use App\Models\NiumVirtualAccountRequest;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Nium\NiumVirtualAccountAssignmentContract;
use App\Services\Nium\NiumVirtualAccountDetailEnrichmentService as EnrichmentService;
use App\Services\Nium\NiumVirtualAccountReconciliationService;
use App\Services\Nium\NiumVirtualAccountRequestIdentity;
use App\Services\Nium\NiumVirtualAccountSafetyLedger;
use App\Services\Nium\NiumVirtualAccountWebhookContract as Contract;
use App\Services\Nium\NiumWebhookService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesNiumVirtualAccountLedgerFixtures;
use Tests\TestCase;

class NiumVirtualAccountLedgerWebhookTest extends TestCase
{
    use CreatesNiumVirtualAccountLedgerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.nium_webhook_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('nium_webhook_test');
        $this->artisan('migrate', ['--database' => 'nium_webhook_test', '--force' => true])->assertSuccessful();
        $this->configureLedgerTarget();
        config([
            'services.nium.auth' => ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'test-key'],
            'services.nium.webhook.static_header_name' => 'x-partner-key',
            'services.nium.webhook.static_header_value' => 'test-partner-key',
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        // All enrolled webhook scenarios must make zero outbound provider requests.
        Http::assertNothingSent();
        DB::purge('nium_webhook_test');
        parent::tearDown();
    }

    private function request(string $version = 'v1'): NiumVirtualAccountRequest
    {
        $account = $this->ledgerAccount();
        $identity = NiumVirtualAccountRequestIdentity::forAccount($account,
            NiumVirtualAccountAssignmentContract::payload($version, 'USD', 'SELF_FUNDING_ACCOUNT', 'WIRES', 'JPM_SG'),
            $version, $version === 'v1' ? 'WIRES' : null);
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'webhook-operation', $identity);
        $ledger->consumeDispatchAuthorization($request, 0);
        $ledger->transition($request->fresh(), 1, Operation::UNKNOWN);

        return $request->fresh();
    }

    private function payload(NiumVirtualAccountRequest $request, array $overrides = []): array
    {
        $snapshot = $request->identity()->snapshot();

        return array_replace([
            'eventId' => 'assigned-delivery', 'template' => Contract::ASSIGNED,
            'clientHashId' => $snapshot['client_id'], 'customerHashId' => $snapshot['customer_id'],
            'walletHashId' => $snapshot['wallet_id'], 'currencyCode' => 'USD', 'uniquePaymentId' => 'VA-WEBHOOK-ONE',
        ], $overrides);
    }

    private function deliver(NiumVirtualAccountRequest $request, array $payload, string $deliveryId = 'provider-delivery-id'): array
    {
        $incoming = Request::create('/api/webhooks/providers/nium', 'POST', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_PARTNER_KEY' => 'test-partner-key', 'HTTP_X_REQUEST_ID' => $deliveryId,
        ], content: json_encode($payload, JSON_THROW_ON_ERROR));

        return app(NiumWebhookService::class)->handleWebhook($request->operation->providerAccount->provider, $incoming);
    }

    private function incoming(array $payload): TestResponse
    {
        return $this->withHeader('x-partner-key', 'test-partner-key')
            ->postJson('/api/webhooks/providers/nium', $payload);
    }

    private function recoveryAdmin(bool $permitted = true): User
    {
        $user = User::factory()->create();
        $user->roles()->create(['role_code' => $permitted ? 'admin' : 'user']);

        return $user;
    }

    private function adminRetry(WebhookEvent $event, User $user): TestResponse
    {
        $token = Str::random(80);
        ApiToken::query()->create([
            'user_id' => $user->id, 'name' => 'recovery-test', 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(),
        ]);

        return $this->withToken($token)->postJson('/api/admin/provider-webhook-events/'.$event->id.'/retry');
    }

    private function held(NiumVirtualAccountRequest $request, string $state = Operation::UNKNOWN): void
    {
        $this->assertSame($state, $request->fresh()->operation_state);
        $this->assertSame($request->operation_id, $request->assignmentScope()->value('active_operation_id'));
        $this->assertNull($request->operation()->value('provider_payment_id'));
        $this->assertNull($request->operation()->value('nium_virtual_account_id'));
    }

    #[DataProvider('versions')]
    public function test_assigned_webhook_with_exact_post_causality_resolves_atomically(string $version): void
    {
        $request = $this->request($version);
        $post = $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-WEBHOOK-ONE']);
        $this->deliver($request, $this->payload($request));
        $this->assertSame(Operation::ASSIGNED, $request->operation()->value('status'));
        $this->assertSame($post->id, $request->fresh()->resolution_api_request_log_id);
        $this->assertNull($request->assignmentScope()->value('active_operation_id'));
        $this->assertNotNull($request->operation()->value('nium_virtual_account_id'));
        $this->assertSame('JPM_SG', $request->identity()->snapshot()['payload']['bankName']);
        $this->assertSame('WIRES', $request->operation()->value('account_type'));
        $this->assertSame($request->operation_id, Enrichment::query()->sole()->operation_id);
        $this->assertSame(1, DB::table('jobs')->where('queue', EnrichmentService::QUEUE)->count());
        $event = WebhookEvent::query()->sole();
        $this->assertSame('processed', $event->processing_status);
        $this->assertSame($this->payload($request), Contract::payload($event));
        $this->assertArrayNotHasKey('nium_va_evidence', $event->toArray());
        $ciphertext = DB::table('webhook_events')->where('id', $event->id)->value('nium_va_evidence');
        $this->assertStringNotContainsString('VA-WEBHOOK-ONE', $ciphertext);
        $this->assertStringNotContainsString('test-partner-key', json_encode($event->nium_va_evidence));
    }

    public static function versions(): array
    {
        return [['v1'], ['v2']];
    }

    public function test_assigned_webhook_without_causality_is_observed_without_creating_a_va(): void
    {
        $request = $this->request();
        $this->deliver($request, $this->payload($request, ['operationId' => 'webhook-operation', 'requestId' => $request->provider_request_id]), $request->provider_request_id);
        $this->held($request);
        $this->assertSame('observed', WebhookEvent::query()->sole()->processing_status);
        $this->assertSame(0, NiumVirtualAccount::query()->count());
        $this->assertSame(1, $request->dispatchAttempt()->count());
    }

    #[DataProvider('unsafeRecoveryTransactions')]
    public function test_enrolled_recovery_rejects_open_transactions_without_changing_its_claim(bool $rawPdo): void
    {
        $request = $this->request();
        $this->deliver($request, $this->payload($request));
        $event = WebhookEvent::query()->sole();
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-WEBHOOK-ONE']);
        $receipt = $event->fresh()->getAttributes();
        $assignment = $request->fresh()->getAttributes();
        $connection = DB::connection();

        if ($rawPdo) {
            $connection->getPdo()->beginTransaction();
        } else {
            $connection->beginTransaction();
        }
        try {
            app(NiumWebhookService::class)->reprocessWebhookEvent($event->provider, $event);
            $this->fail('Enrolled recovery must reject an uncommitted outer transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('VA webhook recovery requires a top-level connection.', $exception->getMessage());
        } finally {
            if ($rawPdo) {
                $connection->getPdo()->rollBack();
            } else {
                $connection->rollBack();
            }
        }

        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
        $this->assertSame($receipt, $event->fresh()->getAttributes());
        $this->assertSame($assignment, $request->fresh()->getAttributes());
        $this->held($request);
        $this->assertSame(0, NiumVirtualAccount::query()->count());
        $this->assertSame(0, Enrichment::query()->count());
        Http::assertNothingSent();

        // Valid top-level recovery must still succeed with the original receipt.
        app(NiumWebhookService::class)->reprocessWebhookEvent($event->provider, $event->fresh());
        $this->assertSame(Operation::ASSIGNED, $request->fresh()->operation_state);
        $this->assertSame('processed', $event->fresh()->processing_status);
        $this->assertSame(2, $event->fresh()->nium_va_recovery['attempts']);
        $this->assertSame($receipt['nium_va_evidence'], $event->fresh()->getRawOriginal('nium_va_evidence'));
        $this->assertSame($receipt['payload'], $event->fresh()->getRawOriginal('payload'));
        $this->assertSame(1, NiumVirtualAccount::query()->count());
        $this->assertSame(1, $request->dispatchAttempt()->count());
        Http::assertNothingSent();
    }

    public static function unsafeRecoveryTransactions(): array
    {
        return ['Laravel transaction' => [false], 'raw PDO transaction' => [true]];
    }

    public function test_legacy_matching_dimensions_cannot_claim_an_enrolled_operation_or_pending_va(): void
    {
        $request = $this->request();
        $pending = NiumVirtualAccount::query()->create([
            'user_provider_account_id' => $request->operation->user_provider_account_id,
            'currency' => 'USD', 'account_category' => 'SELF_FUNDING_ACCOUNT',
            'account_type' => 'WIRES', 'status' => 'pending',
        ]);
        $before = $pending->fresh()->getAttributes();
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-WEBHOOK-ONE']);
        $payload = $this->payload($request, [
            'template' => 'VA_ASSIGNED', 'accountCategory' => 'SELF_FUNDING_ACCOUNT',
            'accountType' => 'WIRES', 'bankName' => 'JPM_SG',
        ]);
        $this->deliver($request, $payload);
        $event = WebhookEvent::query()->sole();
        $receipt = $event->fresh()->getRawOriginal('nium_va_evidence');
        $duplicate = $this->deliver($request, $payload);

        $this->assertTrue($duplicate['duplicate']);
        $this->held($request);
        $this->assertSame('observed', $event->fresh()->processing_status);
        $this->assertSame($receipt, $event->fresh()->getRawOriginal('nium_va_evidence'));
        $this->assertSame($before, $pending->fresh()->getAttributes());
        $this->assertSame(1, NiumVirtualAccount::query()->count());
        $this->assertSame(0, NiumVirtualAccount::query()->whereNotNull('provider_payment_id')->count());
        $this->assertSame(0, Enrichment::query()->count());
        $this->assertSame(1, $request->dispatchAttempt()->count());
        Http::assertNothingSent();
    }

    #[DataProvider('wrongTargets')]
    public function test_wrong_or_missing_frozen_target_cannot_resolve(string $field, mixed $value): void
    {
        $request = $this->request();
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-WEBHOOK-ONE']);
        $this->deliver($request, $this->payload($request, [$field => $value]));
        $this->held($request);
        $this->assertSame(0, NiumVirtualAccount::query()->count());
    }

    public static function wrongTargets(): array
    {
        $cases = [];
        foreach (['clientHashId', 'customerHashId', 'walletHashId'] as $field) {
            $cases[] = [$field, 'wrong-target'];
            $cases[] = [$field, null];
        }

        return $cases;
    }

    public function test_invalid_authentication_is_rejected_before_receipt_or_mutation(): void
    {
        $request = $this->request();
        $this->withHeader('x-partner-key', 'wrong-key')->postJson('/api/webhooks/providers/nium', $this->payload($request))->assertForbidden();
        $this->assertSame(0, WebhookEvent::query()->count());
        $this->held($request);
    }

    #[DataProvider('invalidIds')]
    public function test_missing_malformed_and_initialized_identifiers_cannot_assign(mixed $paymentId): void
    {
        $request = $this->request();
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-WEBHOOK-ONE']);
        $this->deliver($request, $this->payload($request, ['uniquePaymentId' => $paymentId]));
        $this->held($request);
        $this->assertSame(0, NiumVirtualAccount::query()->count());
    }

    public static function invalidIds(): array
    {
        return [[null], [''], [42], ["VA\nINVALID"], ['INITIALIZED'], ['initialized'], [['VA-ONE']]];
    }

    #[DataProvider('unverifiedDimensions')]
    public function test_explicit_contradictions_and_undocumented_enum_or_bank_mappings_are_not_guessed(array $overrides): void
    {
        $request = $this->request();
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-WEBHOOK-ONE']);
        $this->deliver($request, $this->payload($request, $overrides));
        $this->held($request);
        $event = WebhookEvent::query()->sole();
        $this->assertSame('observed', $event->processing_status);
        foreach ($overrides as $key => $value) {
            $this->assertSame($value, Contract::payload($event)[$key]);
        }
    }

    public static function unverifiedDimensions(): array
    {
        return [
            [['currencyCode' => 'EUR']], [['currency' => 'EUR']], [['bankName' => 'DBS HONGKONG']],
            [['fullBankName' => 'JPMorgan Chase Bank N.A.']], [['accountCategory' => 'COLLECTION_ACCOUNT']],
            [['accountType' => 'GLOBAL']], [['accountType' => 'LOCAL']], [['accountType' => 'LOCAL+GLOBAL']],
            [['accountType' => 'WIRES']], [['accountType' => null]],
        ];
    }

    #[DataProvider('withUnverifiedCorrelation')]
    public function test_failure_event_cannot_prove_rejection_even_with_undocumented_assignment_tags(bool $tagged): void
    {
        $request = $this->request();
        $payload = $this->payload($request, ['template' => Contract::FAILED, 'bankName' => 'DBS HONGKONG', 'customMessage' => 'Account creation failed']);
        unset($payload['uniquePaymentId']);
        if ($tagged) {
            $payload += ['operationId' => 'webhook-operation', 'requestId' => $request->provider_request_id];
        }
        $this->deliver($request, $payload, $request->provider_request_id);
        $this->held($request);
        $this->assertSame('uncorrelated_assignment_failure', WebhookEvent::query()->sole()->error_message);
    }

    public static function withUnverifiedCorrelation(): array
    {
        return [[false], [true]];
    }

    public function test_duplicate_assigned_event_preserves_exact_terminal_ownership_and_timestamp(): void
    {
        $request = $this->request();
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-WEBHOOK-ONE']);
        $payload = $this->payload($request);
        $this->deliver($request, $payload);
        $va = $request->operation()->firstOrFail()->virtualAccount;
        $before = $va->fresh()->getAttributes();
        $operationBefore = $request->fresh()->getAttributes();
        $result = $this->deliver($request, $payload);
        $this->assertTrue($result['duplicate']);
        $this->assertSame($before, $va->fresh()->getAttributes());
        $this->assertSame($operationBefore, $request->fresh()->getAttributes());
        $this->assertSame(1, WebhookEvent::query()->count());
        $this->assertSame(1, NiumVirtualAccount::query()->count());
    }

    public function test_reused_delivery_identity_with_different_body_is_rejected_and_original_evidence_is_preserved(): void
    {
        $request = $this->request();
        $payload = $this->payload($request);
        $this->deliver($request, $payload);
        try {
            $this->deliver($request, $this->payload($request, ['uniquePaymentId' => 'VA-DIFFERENT']));
            $this->fail('Conflicting receipt identity cannot replace evidence.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('identity conflicts', $exception->getMessage());
        }
        $this->assertSame($payload, Contract::payload(WebhookEvent::query()->sole()));
        $this->held($request);
    }

    public function test_webhook_before_post_persistence_allows_later_read_only_reconciliation(): void
    {
        $request = $this->request();
        $this->deliver($request, $this->payload($request));
        $this->held($request);
        $event = WebhookEvent::query()->sole();
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-WEBHOOK-ONE']);
        app(NiumVirtualAccountReconciliationService::class)->reconcile($request->operation()->firstOrFail());
        $this->assertSame(Operation::ASSIGNED, $request->operation()->value('status'));
        app(NiumWebhookService::class)->reprocessWebhookEvent($event->provider, $event);
        $this->assertSame('processed', $event->fresh()->processing_status);
        $this->assertSame(1, NiumVirtualAccount::query()->count());
    }

    public function test_stale_failure_after_assignment_cannot_downgrade_terminal_result(): void
    {
        $request = $this->request();
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-WEBHOOK-ONE']);
        $this->deliver($request, $this->payload($request));
        $before = $request->fresh()->getAttributes();
        $this->deliver($request, $this->payload($request, ['eventId' => 'late-failure', 'template' => Contract::FAILED]));
        $this->assertSame($before, $request->fresh()->getAttributes());
        $this->assertNull($request->assignmentScope()->value('active_operation_id'));
        $this->assertSame('VA-WEBHOOK-ONE', $request->operation()->value('provider_payment_id'));
    }

    #[DataProvider('withUnverifiedCorrelation')]
    public function test_reconciliation_and_webhook_winners_are_idempotent_in_either_order(bool $webhookFirst): void
    {
        $request = $this->request();
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-WEBHOOK-ONE']);
        $reconciliation = app(NiumVirtualAccountReconciliationService::class);
        if ($webhookFirst) {
            $this->deliver($request, $this->payload($request));
            $reconciliation->reconcile($request->operation()->firstOrFail());
        } else {
            $reconciliation->reconcile($request->operation()->firstOrFail());
            $before = $request->operation()->firstOrFail()->virtualAccount->fresh()->getAttributes();
            $this->deliver($request, $this->payload($request));
            $this->assertSame($before, $request->operation()->firstOrFail()->virtualAccount->fresh()->getAttributes());
        }
        $this->assertSame(Operation::ASSIGNED, $request->operation()->value('status'));
        $this->assertSame(1, NiumVirtualAccount::query()->count());
        $this->assertSame(1, $request->dispatchAttempt()->count());
    }

    public function test_different_post_payment_id_is_not_attributed_to_the_webhook(): void
    {
        $request = $this->request();
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-ANOTHER-ASSIGNMENT']);
        $this->deliver($request, $this->payload($request));
        $this->held($request);
    }

    public function test_cross_account_existing_va_is_never_adopted_or_overwritten(): void
    {
        $request = $this->request();
        $other = $this->ledgerAccount();
        $existing = NiumVirtualAccount::query()->create([
            'user_provider_account_id' => $other->id, 'provider_payment_id' => 'VA-WEBHOOK-ONE',
            'currency' => 'USD', 'account_category' => 'SELF_FUNDING_ACCOUNT', 'account_type' => 'WIRES', 'status' => 'assigned',
        ]);
        $before = $existing->fresh()->getAttributes();
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-WEBHOOK-ONE']);
        $this->deliver($request, $this->payload($request));
        $this->held($request, Operation::MANUAL_REVIEW);
        $this->assertSame($before, $existing->fresh()->getAttributes());
    }

    public function test_database_failure_rolls_back_resolution_but_retains_authenticated_receipt(): void
    {
        $request = $this->request();
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-WEBHOOK-ONE']);
        $fail = true;
        DB::connection()->beforeExecuting(function (string $query) use (&$fail): void {
            if ($fail && str_starts_with($query, 'insert into "nium_virtual_accounts"')) {
                throw new RuntimeException('Synthetic VA write failure');
            }
        });
        $this->incoming($this->payload($request))->assertStatus(422);
        $this->held($request);
        $event = WebhookEvent::query()->sole();
        $this->assertSame('failed', $event->processing_status);
        $this->assertSame($this->payload($request), Contract::payload($event));
        $this->assertNull($request->fresh()->resolution_api_request_log_id);
        $this->assertSame(0, NiumVirtualAccount::query()->count());
        $fail = false;
        $this->incoming($this->payload($request))->assertOk()->assertJsonPath('processing_status', 'processed');
        $this->assertSame(Operation::ASSIGNED, $request->operation()->value('status'));
    }

    #[DataProvider('badDeliveryIdentities')]
    public function test_malformed_or_conflicting_delivery_identity_is_rejected_before_receipt(array $overrides): void
    {
        $request = $this->request();
        try {
            $this->deliver($request, $this->payload($request, $overrides));
            $this->fail('Delivery identity must be validated before storage or processing.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('identit', $exception->getMessage());
        }
        $this->assertSame(0, WebhookEvent::query()->count());
        $this->held($request);
    }

    public static function badDeliveryIdentities(): array
    {
        return [[['eventId' => []]], [['eventId' => "bad\nidentity"]], [['eventId' => '']], [['event_id' => 'conflicting-delivery']]];
    }

    public function test_changed_receiving_environment_cannot_bind_to_a_frozen_operation(): void
    {
        $request = $this->request();
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-WEBHOOK-ONE']);
        config(['services.nium.base_url' => 'https://another-environment.test']);
        $this->deliver($request, $this->payload($request));
        $this->held($request);
        $this->assertSame('observed', WebhookEvent::query()->sole()->processing_status);
    }

    public function test_claimed_operation_cannot_invent_dispatch_authorization_from_a_webhook(): void
    {
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'claimed-webhook', $this->ledgerIdentity($account));
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-WEBHOOK-ONE']);
        $this->deliver($request, $this->payload($request));
        $this->held($request, Operation::CLAIMED);
        $this->assertSame(0, $request->dispatchAttempt()->count());
    }

    public function test_existing_manual_review_can_resolve_only_with_exact_post_and_webhook_agreement(): void
    {
        $request = $this->request();
        (new NiumVirtualAccountSafetyLedger)->transition($request, 2, Operation::MANUAL_REVIEW);
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-WEBHOOK-ONE']);
        $this->deliver($request, $this->payload($request));
        $this->assertSame(Operation::ASSIGNED, $request->operation()->value('status'));
        $this->assertNull($request->assignmentScope()->value('active_operation_id'));
    }

    public function test_legacy_initialized_notification_cannot_create_or_overwrite_a_payment_id(): void
    {
        $request = $this->request();
        $legacy = $this->ledgerAccount();
        $payload = $this->payload($request, [
            'customerHashId' => $legacy->external_customer_id, 'walletHashId' => $legacy->external_account_id,
            'uniquePaymentId' => 'INITIALIZED', 'accountType' => 'LOCAL',
        ]);
        try {
            $this->deliver($request, $payload);
            $this->fail('INITIALIZED is not a real legacy identifier either.');
        } catch (RuntimeException) {
            $this->assertSame('failed', WebhookEvent::query()->sole()->processing_status);
        }
        $this->assertSame(0, NiumVirtualAccount::query()->count());
        $this->held($request);
    }

    #[DataProvider('withUnverifiedCorrelation')]
    public function test_later_reconciliation_cannot_discard_an_observed_same_id_contradiction_or_unmapped_type(bool $unmappedType): void
    {
        $request = $this->request();
        $this->deliver($request, $this->payload($request, $unmappedType ? ['accountType' => 'GLOBAL'] : ['currencyCode' => 'EUR']));
        $this->held($request);
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-WEBHOOK-ONE']);
        app(NiumVirtualAccountReconciliationService::class)->reconcile($request->operation()->firstOrFail());
        $this->held($request, Operation::MANUAL_REVIEW);
        $this->assertNull($request->fresh()->resolution_api_request_log_id);
        $this->assertSame(0, NiumVirtualAccount::query()->count());
    }

    public function test_late_unmapped_notification_cannot_rewrite_an_already_terminal_assignment(): void
    {
        $request = $this->request();
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-WEBHOOK-ONE']);
        app(NiumVirtualAccountReconciliationService::class)->reconcile($request->operation()->firstOrFail());
        $before = $request->fresh()->getAttributes();
        $this->deliver($request, $this->payload($request, ['accountType' => 'GLOBAL']));
        app(NiumVirtualAccountReconciliationService::class)->reconcile($request->operation()->firstOrFail());
        $this->assertSame($before, $request->fresh()->getAttributes());
        $this->assertNull($request->assignmentScope()->value('active_operation_id'));
    }

    public function test_authenticated_receipt_is_immutable_but_processing_status_can_change(): void
    {
        $request = $this->request();
        $this->deliver($request, $this->payload($request));
        $event = WebhookEvent::query()->sole();
        foreach ([['nium_va_evidence' => null], ['payload' => ['template' => 'CHANGED']], ['event_id' => 'different-delivery']] as $changes) {
            try {
                $event->update($changes);
                $this->fail('Receipt provenance must not change.');
            } catch (QueryException) {
                $event->refresh();
            }
        }
        try {
            $event->delete();
            $this->fail('Protected receipt evidence must not be deleted.');
        } catch (QueryException) {
            $this->assertTrue($event->fresh()->exists);
        }
        $event->update(['processing_status' => 'observed']);
        $this->assertSame('observed', $event->fresh()->processing_status);
    }

    public function test_missing_raw_provenance_on_historical_receipt_cannot_resolve_a_ledger(): void
    {
        $request = $this->request();
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-WEBHOOK-ONE']);
        $event = WebhookEvent::query()->create([
            'provider_id' => $request->identity()->snapshot()['provider_id'], 'event_id' => 'historical',
            'event_type' => Contract::ASSIGNED, 'payload' => $this->payload($request), 'processing_status' => 'failed',
        ]);
        app(NiumWebhookService::class)->reprocessWebhookEvent($event->provider, $event);
        $this->held($request);
    }

    public function test_authorized_admin_retry_recovers_protected_receipt_without_changing_original_evidence(): void
    {
        $request = $this->request();
        $payload = $this->payload($request);
        $this->incoming($payload)->assertOk()->assertJsonPath('processing_status', 'observed');
        $event = WebhookEvent::query()->sole();
        $original = DB::table('webhook_events')->where('id', $event->id)->first();
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-WEBHOOK-ONE']);
        $this->adminRetry($event, $this->recoveryAdmin())->assertOk()
            ->assertJsonPath('webhook_event.status', 'processed')->assertJsonPath('webhook_event.attempts', 1);
        $after = DB::table('webhook_events')->where('id', $event->id)->first();
        $this->assertSame($original->payload, $after->payload);
        $this->assertSame($original->nium_va_evidence, $after->nium_va_evidence);
        $this->assertArrayNotHasKey('_origin_retry', $event->fresh()->payload);
        $this->assertSame(1, AuditLog::query()->where('action', 'webhook.retry_requested')->count());
        $this->assertSame(Operation::ASSIGNED, $request->operation()->value('status'));
        $this->assertSame($request->operation_id, Enrichment::query()->sole()->operation_id);
        $this->assertSame(1, DB::table('jobs')->where('queue', EnrichmentService::QUEUE)->count());
        $this->assertSame(1, NiumVirtualAccount::query()->count());
    }

    public function test_admin_retry_requires_authentication_and_provider_sync_permission(): void
    {
        $request = $this->request();
        $this->incoming($this->payload($request))->assertOk();
        $event = WebhookEvent::query()->sole();
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-WEBHOOK-ONE']);
        $this->postJson('/api/admin/provider-webhook-events/'.$event->id.'/retry')->assertUnauthorized();
        $this->adminRetry($event, $this->recoveryAdmin(false))->assertForbidden();
        $this->held($request);
        $this->assertSame(1, $event->fresh()->nium_va_recovery['attempts']);
        $this->assertSame(0, AuditLog::query()->where('action', 'webhook.retry_requested')->count());
    }

    public function test_duplicate_redelivery_recovers_original_observation_when_post_evidence_arrives_later(): void
    {
        $request = $this->request();
        $payload = $this->payload($request);
        $this->incoming($payload)->assertOk()->assertJsonPath('processing_status', 'observed');
        $event = WebhookEvent::query()->sole();
        $original = DB::table('webhook_events')->where('id', $event->id)->value('nium_va_evidence');
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-WEBHOOK-ONE']);
        $this->incoming($payload)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('processing_status', 'processed');
        $this->assertSame(Operation::ASSIGNED, $request->operation()->value('status'));
        $assignedAt = NiumVirtualAccount::query()->sole()->assigned_at;
        $this->incoming($payload)->assertOk()->assertJsonPath('processing_status', 'processed');
        $this->assertSame(2, $event->fresh()->nium_va_recovery['attempts']);
        $this->assertSame($assignedAt->toISOString(), NiumVirtualAccount::query()->sole()->assigned_at->toISOString());
        $this->assertSame($original, DB::table('webhook_events')->where('id', $event->id)->value('nium_va_evidence'));
        $this->assertSame($request->operation_id, Enrichment::query()->sole()->operation_id);
        $this->assertSame(1, DB::table('jobs')->where('queue', EnrichmentService::QUEUE)->count());
        $this->assertSame(1, NiumVirtualAccount::query()->count());
    }

    public function test_failed_processing_redelivery_and_admin_errors_do_not_claim_success(): void
    {
        $request = $this->request();
        $payload = $this->payload($request);
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-WEBHOOK-ONE']);
        $fail = true;
        DB::connection()->beforeExecuting(function (string $query) use (&$fail): void {
            if ($fail && str_starts_with($query, 'insert into "nium_virtual_accounts"')) {
                throw new RuntimeException('Sensitive provider detail must not reach response');
            }
        });
        $this->incoming($payload)->assertStatus(422);
        $event = WebhookEvent::query()->sole();
        $this->adminRetry($event, $this->recoveryAdmin())->assertStatus(422)
            ->assertJsonPath('webhook_event.status', 'failed');
        $this->incoming($payload)->assertStatus(422);
        $this->held($request);
        $this->assertSame(0, NiumVirtualAccount::query()->count());
        $fail = false;
        $this->incoming($payload)->assertOk()->assertJsonPath('processing_status', 'processed');
        $this->assertSame(Operation::ASSIGNED, $request->operation()->value('status'));
    }

    #[DataProvider('recoveryBlockedPayloads')]
    public function test_redelivery_and_admin_retry_preserve_unresolved_safety_barriers(array $overrides, bool $postEvidence): void
    {
        $request = $this->request();
        $payload = $this->payload($request, $overrides);
        if ($postEvidence) {
            $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-WEBHOOK-ONE']);
        }
        $this->incoming($payload)->assertOk()->assertJsonPath('processing_status', 'observed');
        $event = WebhookEvent::query()->sole();
        $this->incoming($payload)->assertOk()->assertJsonPath('processing_status', 'observed');
        $this->adminRetry($event, $this->recoveryAdmin())->assertOk()->assertJsonPath('webhook_event.status', 'observed');
        $this->held($request);
        $this->assertSame(0, NiumVirtualAccount::query()->count());
    }

    public static function recoveryBlockedPayloads(): array
    {
        return [[[], false], [['clientHashId' => 'wrong'], true], [['uniquePaymentId' => 'INITIALIZED'], true],
            [['uniquePaymentId' => null], true], [['accountType' => 'GLOBAL'], true]];
    }

    public function test_recovery_of_failed_notification_cannot_downgrade_terminal_assignment(): void
    {
        $request = $this->request();
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-WEBHOOK-ONE']);
        $this->incoming($this->payload($request))->assertOk();
        $failure = $this->payload($request, ['eventId' => 'failure-delivery', 'template' => Contract::FAILED]);
        $this->incoming($failure)->assertOk()->assertJsonPath('processing_status', 'observed');
        $event = WebhookEvent::query()->where('event_id', 'failure-delivery')->firstOrFail();
        $this->adminRetry($event, $this->recoveryAdmin())->assertOk()->assertJsonPath('webhook_event.status', 'observed');
        $this->incoming($failure)->assertOk()->assertJsonPath('processing_status', 'observed');
        $this->assertSame(Operation::ASSIGNED, $request->operation()->value('status'));
        $this->assertNull($request->assignmentScope()->value('active_operation_id'));
        $this->assertSame('VA-WEBHOOK-ONE', $request->operation()->value('provider_payment_id'));
        $this->assertSame(1, NiumVirtualAccount::query()->count());
    }
}
