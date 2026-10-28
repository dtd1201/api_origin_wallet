<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\NiumVirtualAccount;
use App\Models\NiumVirtualAccountOperation as Operation;
use App\Models\WebhookEvent;
use App\Services\Nium\NiumPaymentIdService;
use App\Services\Nium\NiumVirtualAccountReconciliationService as Reconciliation;
use App\Services\Nium\NiumVirtualAccountSafetyLedger as Ledger;
use App\Services\Nium\NiumWebhookService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as InboundRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesNiumLegacyVirtualAccountEvidence;
use Tests\Concerns\CreatesNiumVirtualAccountLedgerFixtures;
use Tests\TestCase;

class NiumLegacyVirtualAccountEvidenceTest extends TestCase
{
    use CreatesNiumLegacyVirtualAccountEvidence;
    use CreatesNiumVirtualAccountLedgerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.nium_legacy_evidence_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('nium_legacy_evidence_test');
        $this->artisan('migrate', ['--database' => 'nium_legacy_evidence_test', '--force' => true])->assertSuccessful();
        $this->configureLedgerTarget();
        config(['services.nium.auth' => ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'fake'],
            'services.nium.webhook.static_header_name' => 'x-partner-key',
            'services.nium.webhook.static_header_value' => 'fake-partner',
            'services.nium.va_single_dispatch.enabled' => false,
            'services.nium.va_single_dispatch.allocation_paused' => false]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        DB::purge('nium_legacy_evidence_test');
        parent::tearDown();
    }

    public function test_exact_linked_historical_post_establishes_original_target_without_a_snapshot(): void
    {
        $account = $this->ledgerAccount();
        $operation = $this->legacyEvidenceOperation($account, false);
        $this->legacyPostEvidence($operation, $this->ledgerIdentity($account), 'VA-HISTORICAL-PROOF');
        config(['services.nium.va_single_dispatch.allocation_paused' => true]);

        $assigned = app(Reconciliation::class)->reconcile($operation);

        $this->assertSame(Operation::ASSIGNED, $assigned->status);
        $this->assertSame('VA-HISTORICAL-PROOF', $assigned->provider_payment_id);
        $this->assertNull($assigned->legacy_target_snapshot); // No speculative backfill.
        $this->assertSame(1, NiumVirtualAccount::query()->count());
        Http::assertNothingSent();
    }

    public static function invalidEvidence(): array
    {
        $cases = [];
        foreach (['missing', 'redacted', 'payload', 'hash', 'wallet', 'duplicate', 'unlinked'] as $case) {
            $cases[$case] = [$case];
        }

        return $cases;
    }

    #[DataProvider('invalidEvidence')]
    public function test_unproven_historical_target_never_creates_or_attaches_a_va(string $case): void
    {
        $account = $this->ledgerAccount();
        $operation = $this->legacyEvidenceOperation($account, false);
        $identity = $this->ledgerIdentity($account);
        $changes = match ($case) {
            'redacted' => ['request_url' => 'https://nium.test/api/v2/client/[REDACTED]/customer/[REDACTED]/wallet/[REDACTED]/paymentId'],
            'payload' => ['raw_request_body' => '{}', 'request_body_sha256' => hash('sha256', '{}')],
            'hash' => ['response_body_sha256' => str_repeat('0', 64)],
            default => [],
        };
        if ($case !== 'missing') {
            $this->legacyPostEvidence($operation, $identity, 'VA-UNPROVEN', $changes, $case !== 'unlinked');
        }
        if ($case === 'wallet') {
            $account->update(['external_account_id' => (string) Str::uuid()]);
        }
        if ($case === 'duplicate') {
            $this->legacyPostEvidence($operation, $identity, 'VA-OTHER-RESULT', link: false);
        }
        Http::fake(fn () => Http::response(['content' => [], 'last' => true]));

        $result = app(Reconciliation::class)->reconcile($operation);

        $this->assertSame(Operation::MANUAL_REVIEW, $result->status);
        $this->assertNull($result->provider_payment_id);
        $this->assertNull($result->nium_virtual_account_id);
        $this->assertSame(0, NiumVirtualAccount::query()->count());
        $this->assertSame($case === 'missing' ? 0 : ($case === 'duplicate' ? 2 : 1),
            ApiRequestLog::query()->where('operation', 'assign_payment_id')->count());
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    }

    public function test_frozen_original_identity_is_not_replaced_by_conflicting_exchange_evidence(): void
    {
        $account = $this->ledgerAccount();
        $operation = $this->legacyEvidenceOperation($account);
        $other = $this->ledgerAccount();
        $this->legacyPostEvidence($operation, $this->ledgerIdentity($other), 'VA-WRONG-TARGET');

        $result = app(Reconciliation::class)->assign($operation, 'VA-WRONG-TARGET', 'attempt');

        $this->assertSame(Operation::MANUAL_REVIEW, $result->status);
        $this->assertSame($this->ledgerIdentity($account)->snapshot(), $result->legacy_target_snapshot);
        $this->assertSame(0, NiumVirtualAccount::query()->count());
        Http::assertNothingSent();
    }

    public function test_enrolled_existing_owner_blocks_legacy_assignment(): void
    {
        $owner = $this->ledgerAccount();
        $request = (new Ledger)->prepare($owner, 'existing-owner', $this->ledgerIdentity($owner));
        (new Ledger)->consumeDispatchAuthorization($request, 0);
        $log = $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-GLOBAL-OWNER']);
        (new Ledger)->resolveFromPostEvidence($request->fresh(), 1, $log, attachVirtualAccount: true);
        $va = NiumVirtualAccount::query()->sole();
        $before = $va->getAttributes();
        $account = $this->ledgerAccount();
        $operation = $this->legacyEvidenceOperation($account);
        $this->legacyPostEvidence($operation, $this->ledgerIdentity($account), 'VA-GLOBAL-OWNER');

        $result = app(Reconciliation::class)->assign($operation, 'VA-GLOBAL-OWNER', 'attempt');

        $this->assertSame(Operation::MANUAL_REVIEW, $result->status);
        $this->assertSame('payment_id_already_owned', $result->resolution_code);
        $this->assertSame($before, $va->fresh()->getAttributes());
        $this->assertSame(1, NiumVirtualAccount::query()->count());
        Http::assertNothingSent();
    }

    public function test_foreign_pending_reference_has_no_create_fallback(): void
    {
        $owner = $this->ledgerAccount();
        $foreign = NiumVirtualAccount::query()->create([
            'user_provider_account_id' => $owner->id, 'currency' => 'USD', 'status' => 'pending',
            'account_category' => 'COLLECTION_ACCOUNT', 'account_type' => 'LOCAL',
        ]);
        $account = $this->ledgerAccount();
        $operation = $this->legacyEvidenceOperation($account);
        $operation->update(['nium_virtual_account_id' => $foreign->id]);
        $this->legacyPostEvidence($operation, $this->ledgerIdentity($account), 'VA-FOREIGN-PENDING');
        $before = $foreign->fresh()->getAttributes();

        $result = app(Reconciliation::class)->assign($operation, 'VA-FOREIGN-PENDING', 'attempt');

        $this->assertSame(Operation::MANUAL_REVIEW, $result->status);
        $this->assertSame($before, $foreign->fresh()->getAttributes());
        $this->assertSame(1, NiumVirtualAccount::query()->count());
        Http::assertNothingSent();
    }

    public function test_valid_historical_post_attaches_only_its_own_pending_va(): void
    {
        $account = $this->ledgerAccount();
        $operation = $this->legacyEvidenceOperation($account, false);
        $pending = NiumVirtualAccount::query()->create([
            'user_provider_account_id' => $account->id, 'currency' => 'USD', 'status' => 'pending',
            'account_category' => 'COLLECTION_ACCOUNT', 'account_type' => 'LOCAL',
        ]);
        $operation->update(['nium_virtual_account_id' => $pending->id]);
        $this->legacyPostEvidence($operation, $this->ledgerIdentity($account), 'VA-HISTORICAL-ATTACH');

        $result = app(Reconciliation::class)->reconcile($operation);
        $before = $result->getAttributes();
        $this->assertSame($pending->id, $result->nium_virtual_account_id);
        $this->assertSame('VA-HISTORICAL-ATTACH', $pending->fresh()->provider_payment_id);
        $this->assertSame(1, NiumVirtualAccount::query()->count());
        try {
            app(Reconciliation::class)->assign($result, 'VA-REASSIGN-ATTEMPT', 'attempt');
            $this->fail('Terminal legacy assignment must not be reassigned.');
        } catch (RuntimeException) {
            $this->assertSame($before, $result->fresh()->getAttributes());
        }
        Http::assertNothingSent();
    }

    public function test_legacy_owner_prevents_new_ledger_writer_from_claiming_the_same_id(): void
    {
        $account = $this->ledgerAccount();
        $legacy = $this->legacyEvidenceOperation($account);
        $this->legacyPostEvidence($legacy, $this->ledgerIdentity($account), 'VA-LEGACY-OWNER');
        app(Reconciliation::class)->reconcile($legacy);
        $before = $legacy->fresh()->getAttributes();
        $other = $this->ledgerAccount();
        $ledger = new Ledger;
        $request = $ledger->prepare($other, 'new-collision', $this->ledgerIdentity($other));
        $ledger->consumeDispatchAuthorization($request, 0);
        $log = $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-LEGACY-OWNER']);

        $ledger->resolveFromPostEvidence($request->fresh(), 1, $log, attachVirtualAccount: true);

        $this->assertSame(Operation::MANUAL_REVIEW, $request->fresh()->operation_state);
        $this->assertSame($before, $legacy->fresh()->getAttributes());
        $this->assertSame(1, NiumVirtualAccount::query()->count());
        Http::assertNothingSent();
    }

    public function test_duplicate_global_payment_id_rows_and_operation_only_owners_are_quarantined(): void
    {
        foreach (['duplicate_rows', 'operation_only'] as $kind) {
            $paymentId = 'VA-'.strtoupper($kind);
            foreach ([0, 1] as $index) {
                $owner = $this->ledgerAccount();
                if ($kind === 'duplicate_rows') {
                    NiumVirtualAccount::query()->create([
                        'user_provider_account_id' => $owner->id, 'currency' => 'USD', 'status' => 'assigned',
                        'provider_payment_id' => $paymentId,
                    ]);
                } else {
                    $this->legacyLedgerOperation($owner, Operation::MANUAL_REVIEW, ['provider_payment_id' => $paymentId]);
                }
            }
            $account = $this->ledgerAccount();
            $operation = $this->legacyEvidenceOperation($account);
            $this->legacyPostEvidence($operation, $this->ledgerIdentity($account), $paymentId);
            $before = NiumVirtualAccount::query()->orderBy('id')->get()->map->getAttributes()->all();

            $result = app(Reconciliation::class)->assign($operation, $paymentId, 'attempt');

            $this->assertSame(Operation::MANUAL_REVIEW, $result->status);
            $this->assertNull($result->provider_payment_id);
            $this->assertSame($before, NiumVirtualAccount::query()->orderBy('id')->get()->map->getAttributes()->all());
        }
        Http::assertNothingSent();
    }

    public function test_authenticated_legacy_webhook_requires_original_post_correlation(): void
    {
        $account = $this->ledgerAccount();
        $operation = $this->legacyEvidenceOperation($account);
        $identity = $this->ledgerIdentity($account);
        $this->legacyPostEvidence($operation, $identity, 'VA-LEGACY-WEBHOOK');
        config(['services.nium.webhook.static_header_name' => 'x-partner-key',
            'services.nium.webhook.static_header_value' => 'fake-partner']);
        Http::fake(fn () => Http::response(['content' => []]));
        $payload = [
            'eventId' => 'legacy-proof-receipt', 'template' => 'VIRTUAL_ACCOUNT_ASSIGNED_WEBHOOK',
            'clientHashId' => $identity->snapshot()['client_id'], 'customerHashId' => $account->external_customer_id,
            'walletHashId' => $account->external_account_id, 'uniquePaymentId' => 'VA-LEGACY-WEBHOOK', 'currencyCode' => 'USD',
        ];
        $deliver = function (array $body) use ($account): void {
            $request = InboundRequest::create('/api/webhooks/providers/nium', 'POST', server: [
                'CONTENT_TYPE' => 'application/json', 'HTTP_X_PARTNER_KEY' => 'fake-partner',
            ], content: json_encode($body, JSON_THROW_ON_ERROR));
            app(NiumWebhookService::class)->handleWebhook($account->provider, $request);
        };
        $deliver($payload);
        $this->assertSame(Operation::ASSIGNED, $operation->fresh()->status);
        $this->assertSame(1, NiumVirtualAccount::query()->count());
        $payload['eventId'] = 'uncorrelated-legacy-receipt';
        $payload['uniquePaymentId'] = 'VA-NO-POST-PROOF';
        $deliver($payload);
        $this->assertSame(1, NiumVirtualAccount::query()->count());
        $this->assertSame('observed', WebhookEvent::query()->where('event_id', $payload['eventId'])->sole()->processing_status);
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    }

    public function test_pause_blocks_new_legacy_post_without_creating_history(): void
    {
        $account = $this->ledgerAccount();
        config(['services.nium.va_single_dispatch.allocation_paused' => true]);
        try {
            app(NiumPaymentIdService::class)->assign($account, 'USD', 'COLLECTION_ACCOUNT', 'LOCAL', 'DBS_HK', 'paused-legacy');
            $this->fail('Paused allocation must fail closed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('paused', $e->getMessage());
        }
        $this->assertSame(0, Operation::query()->count());
        $this->assertSame(0, NiumVirtualAccount::query()->count());
        Http::assertNothingSent();
    }

    public function test_future_legacy_post_uses_committed_frozen_target_and_request_id_once(): void
    {
        $account = $this->ledgerAccount();
        Http::fake(function (Request $request) use ($account) {
            if ($request->method() === 'GET') {
                return Http::response(['content' => [], 'last' => true]);
            }
            $operation = Operation::query()->sole();
            $snapshot = $operation->legacy_target_snapshot;
            $this->assertSame(0, DB::transactionLevel());
            $this->assertNotNull($operation->submitted_at);
            $this->assertSame((int) $account->id, $snapshot['provider_account_id']);
            $this->assertSame($snapshot['provider_origin'].$snapshot['endpoint'], $request->url());
            $this->assertSame([$operation->legacy_provider_request_id], $request->header('x-request-id'));
            $this->assertSame(['currency' => 'USD', 'accountCategory' => 'COLLECTION_ACCOUNT',
                'accountType' => 'LOCAL', 'bankName' => 'DBS_HK'], $request->data());
            $stored = DB::table('nium_virtual_account_operations')->value('legacy_target_snapshot');
            $this->assertStringNotContainsString($account->external_account_id, $stored);
            $this->assertArrayNotHasKey('legacy_target_snapshot', $operation->toArray());

            return Http::response(['uniquePaymentId' => 'VA-FROZEN-FUTURE']);
        });
        $service = app(NiumPaymentIdService::class);
        $first = $service->assign($account, 'USD', 'COLLECTION_ACCOUNT', 'LOCAL', 'DBS_HK', 'future-legacy');
        $second = $service->assign($account, 'USD', 'COLLECTION_ACCOUNT', 'LOCAL', 'DBS_HK', 'future-legacy');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('assigned', $first->status);
        $this->assertSame(1, Operation::query()->count());
        $this->assertSame(1, NiumVirtualAccount::query()->count());
        $log = ApiRequestLog::query()->where('operation', 'assign_payment_id')->sole();
        $this->assertSame(Operation::query()->sole()->legacy_target_snapshot['provider_origin']
            .Operation::query()->sole()->legacy_target_snapshot['endpoint'], $log->niumExchangeEvidence->assignment_target);
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($p) => $p[0]->method() === 'POST')->count());
    }

    public function test_same_operation_key_for_distinct_customers_uses_isolated_frozen_targets(): void
    {
        $first = $this->ledgerAccount();
        $second = $this->ledgerAccount();
        Http::fake(fn (Request $request) => $request->method() === 'GET'
            ? Http::response(['content' => [], 'last' => true])
            : Http::response(['uniquePaymentId' => str_contains($request->url(), $first->external_account_id) ? 'VA-IDENTITY-A' : 'VA-IDENTITY-B']));
        $service = app(NiumPaymentIdService::class);
        $a = $service->assign($first, 'USD', 'COLLECTION_ACCOUNT', 'LOCAL', 'DBS_HK', 'same-local-key');
        $b = $service->assign($second, 'USD', 'COLLECTION_ACCOUNT', 'LOCAL', 'DBS_HK', 'same-local-key');

        $this->assertSame('VA-IDENTITY-A', $a->provider_payment_id);
        $this->assertSame('VA-IDENTITY-B', $b->provider_payment_id);
        $this->assertSame($first->id, $a->user_provider_account_id);
        $this->assertSame($second->id, $b->user_provider_account_id);
        foreach ([$first, $second] as $account) {
            $snapshot = Operation::query()->where('user_provider_account_id', $account->id)->sole()->legacy_target_snapshot;
            $this->assertSame($account->external_customer_id, $snapshot['customer_id']);
            $this->assertSame($account->external_account_id, $snapshot['wallet_id']);
            $this->assertSame($account->user_id, $snapshot['user_id']);
        }
        $this->assertSame(2, NiumVirtualAccount::query()->count());
        $this->assertSame(2, collect(Http::recorded())->filter(fn ($p) => $p[0]->method() === 'POST')->count());
    }

    public function test_timeout_replay_uses_only_get_and_cannot_assign_a_listing_candidate(): void
    {
        $account = $this->ledgerAccount();
        $posts = 0;
        Http::fake(function (Request $request) use (&$posts) {
            if ($request->method() === 'POST') {
                $posts++;
                throw new ConnectionException('mock timeout');
            }

            return Http::response([
                'content' => $posts === 0 ? [] : [['uniquePaymentId' => 'VA-GET-ONLY']],
                'last' => true,
            ]);
        });
        $service = app(NiumPaymentIdService::class);
        try {
            $service->assign($account, 'USD', 'COLLECTION_ACCOUNT', 'LOCAL', 'DBS_HK', 'legacy-timeout');
            $this->fail('Expected mock timeout.');
        } catch (ConnectionException) {
        }
        config(['services.nium.va_single_dispatch.allocation_paused' => true]);
        $service->assign($account, 'USD', 'COLLECTION_ACCOUNT', 'LOCAL', 'DBS_HK', 'legacy-timeout');
        $this->assertSame(Operation::MANUAL_REVIEW, Operation::query()->sole()->status);
        $this->assertNull(Operation::query()->sole()->provider_payment_id);
        $this->assertSame(1, $posts);
    }

    public function test_database_rejects_snapshot_changes_and_sealed_post_evidence_changes(): void
    {
        $account = $this->ledgerAccount();
        $operation = $this->legacyEvidenceOperation($account);
        $log = $this->legacyPostEvidence($operation, $this->ledgerIdentity($account), 'VA-IMMUTABLE');
        foreach ([
            fn () => $operation->update(['legacy_target_snapshot' => []]),
            fn () => $operation->update(['legacy_provider_request_id' => (string) Str::uuid()]),
            fn () => $operation->update(['submitted_at' => null]),
            fn () => $log->update(['response_status' => 400]),
            fn () => $log->niumExchangeEvidence->update(['assignment_target' => 'https://other.test']),
            fn () => $log->niumExchangeEvidence->delete(),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('Immutable legacy evidence must reject mutation.');
            } catch (QueryException) {
            }
        }
        $historical = $this->legacyEvidenceOperation($this->ledgerAccount(), false);
        $this->expectException(QueryException::class);
        $historical->update(['legacy_target_snapshot' => $operation->fresh()->legacy_target_snapshot,
            'legacy_provider_request_id' => (string) Str::uuid()]);
    }
}
