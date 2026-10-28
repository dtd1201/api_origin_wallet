<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\NiumVirtualAccount;
use App\Models\NiumVirtualAccountDetailEnrichment as Enrichment;
use App\Models\NiumVirtualAccountOperation as Operation;
use App\Models\NiumVirtualAccountRequest;
use App\Models\User;
use App\Services\Nium\NiumVirtualAccountAssignmentContract;
use App\Services\Nium\NiumVirtualAccountDetailEnrichmentService as EnrichmentService;
use App\Services\Nium\NiumVirtualAccountReconciliationService;
use App\Services\Nium\NiumVirtualAccountRequestIdentity;
use App\Services\Nium\NiumVirtualAccountSafetyLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesNiumVirtualAccountLedgerFixtures;
use Tests\TestCase;

class NiumVirtualAccountLedgerReconciliationTest extends TestCase
{
    use CreatesNiumVirtualAccountLedgerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.nium_reconciliation_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('nium_reconciliation_test');
        $this->artisan('migrate', ['--database' => 'nium_reconciliation_test', '--force' => true])->assertSuccessful();
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
        // Every reconciliation test, including failure paths, forbids any provider write.
        Http::assertNotSent(fn ($outgoing): bool => $outgoing->method() !== 'GET');
        DB::purge('nium_reconciliation_test');
        parent::tearDown();
    }

    private function request(string $version = 'v2', bool $consumed = true): NiumVirtualAccountRequest
    {
        $account = $this->ledgerAccount();
        $identity = NiumVirtualAccountRequestIdentity::forAccount($account,
            NiumVirtualAccountAssignmentContract::payload($version, 'USD', 'COLLECTION_ACCOUNT', 'LOCAL', 'DBS_HK'),
            $version, $version === 'v1' ? 'LOCAL' : null);
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'reconciliation-key', $identity);
        if ($consumed) {
            $ledger->consumeDispatchAuthorization($request, 0);
            $ledger->transition($request->fresh(), 1, Operation::UNKNOWN);
        }

        return $request->fresh();
    }

    private function reconcile(NiumVirtualAccountRequest $request): Operation
    {
        return app(NiumVirtualAccountReconciliationService::class)->reconcile($request->operation()->firstOrFail());
    }

    private function assertHeld(NiumVirtualAccountRequest $request, string $state): void
    {
        $this->assertSame($state, $request->fresh()->operation_state);
        $this->assertSame($request->operation_id, $request->assignmentScope()->value('active_operation_id'));
        $this->assertNull($request->operation()->value('provider_payment_id'));
        $this->assertNull($request->operation()->value('nium_virtual_account_id'));
        $this->assertNull($request->fresh()->resolution_api_request_log_id);
    }

    #[DataProvider('versions')]
    public function test_exact_post_evidence_resolves_without_baseline_or_provider_requests(string $version): void
    {
        $request = $this->request($version);
        $log = $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-EXACT-POST'] + [
            'currencyCode' => 'USD', 'accountCategory' => 'COLLECTION_ACCOUNT', 'accountType' => 'LOCAL',
            'clientHashId' => $request->identity()->snapshot()['client_id'],
            'customerHashId' => $request->identity()->snapshot()['customer_id'],
            'walletHashId' => $request->identity()->snapshot()['wallet_id'], 'bankName' => 'DBS_HK',
        ]);
        $this->assertFalse($request->operation->baseline_complete);
        $this->assertNull($request->operation->baseline_payment_ids);
        $this->assertSame(Operation::ASSIGNED, $this->reconcile($request)->status);
        $this->assertSame($log->id, $request->fresh()->resolution_api_request_log_id);
        $this->assertSame('VA-EXACT-POST', $request->operation()->value('provider_payment_id'));
        $this->assertNotNull($request->operation()->value('nium_virtual_account_id'));
        $this->assertNull($request->assignmentScope()->value('active_operation_id'));
        $before = $request->fresh()->getAttributes();
        $this->reconcile($request);
        $this->assertSame($before, $request->fresh()->getAttributes());
        $this->assertSame(1, NiumVirtualAccount::query()->count());
        $this->assertSame(1, $request->dispatchAttempt()->count());
        Http::assertNothingSent();
        $this->assertSame($request->operation_id, Enrichment::query()->sole()->operation_id);
        $this->assertSame(1, DB::table('jobs')->where('queue', EnrichmentService::QUEUE)->count());
    }

    public static function versions(): array
    {
        return [['v1'], ['v2']];
    }

    #[DataProvider('unavailableListings')]
    public function test_unavailable_malformed_and_incomplete_get_preserves_state(mixed $body, int $status, bool $timeout = false): void
    {
        $request = $this->request();
        $before = $request->getAttributes();
        Http::fake(fn () => $timeout ? Http::failedConnection('synthetic timeout') : Http::response($body, $status));
        $this->assertSame(Operation::UNKNOWN, $this->reconcile($request)->status);
        $this->assertHeld($request, Operation::UNKNOWN);
        $this->assertSame($before, $request->fresh()->getAttributes());
        $this->assertSame(0, NiumVirtualAccount::query()->count());
        $this->assertSame(1, $request->dispatchAttempt()->count());
        $this->assertNotEmpty(Http::recorded());
    }

    public static function unavailableListings(): array
    {
        $ids = array_map(fn ($i) => ['uniquePaymentId' => 'VA-PAGE-'.$i], range(1, 20));

        return [
            '404 is unavailable' => [[], 404],
            '429' => [[], 429],
            '500' => [[], 500],
            '503' => [[], 503],
            'redirect' => [[], 302],
            'timeout' => [[], 200, true],
            'empty after uncertain POST' => [['content' => [], 'last' => true], 200],
            'missing envelope' => [[], 200],
            'unexpected envelope' => [['paymentIds' => [], 'last' => true], 200],
            'non-json' => ['broken json', 200],
            'null content' => [['content' => null, 'last' => true], 200],
            'scalar item' => [['content' => ['VA-ID'], 'last' => true], 200],
            'missing completeness' => [['content' => []], 200],
            'partial non-final page' => [['content' => [['uniquePaymentId' => 'VA-ONE']], 'last' => false], 200],
            'conflicting pagination' => [['content' => [], 'last' => false, 'totalPages' => 1], 200],
            'string pagination' => [['content' => [], 'totalPages' => '1'], 200],
            'contradictory nested page' => [['content' => [], 'last' => true, 'page' => ['last' => false]], 200],
            'contradictory page item count' => [['content' => [], 'last' => true, 'numberOfElements' => 1], 200],
            'wrong page number' => [['content' => [], 'last' => true, 'number' => 1], 200],
            'missing item' => [['content' => [], 'last' => true, 'totalElements' => 1], 200],
            'repeated page' => [['content' => $ids, 'last' => false, 'totalPages' => 8], 200],
            'malformed ID' => [['content' => [['uniquePaymentId' => "VA\nINVALID"]], 'last' => true], 200],
        ];
    }

    public function test_complete_listing_with_unproven_tags_needs_manual_review_and_uses_frozen_v2_get(): void
    {
        $request = $this->request('v1');
        Http::fake(function ($outgoing, $options) use ($request) {
            $this->assertSame('GET', $outgoing->method());
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(0, DB::transactionLevel());
            $snapshot = $request->identity()->snapshot();
            $this->assertStringStartsWith($snapshot['provider_origin'].'/api/v2/client/'.$snapshot['client_id'].'/customer/'.$snapshot['customer_id'].'/wallet/'.$snapshot['wallet_id'].'/paymentIds?', $outgoing->url());

            return Http::response(['content' => [[
                'uniquePaymentId' => 'VA-UNPROVEN', 'currencyCode' => 'USD',
                'tags' => ['operationId' => 'reconciliation-key', 'requestId' => $request->provider_request_id],
            ]], 'last' => true]);
        });
        $this->reconcile($request);
        $this->assertHeld($request, Operation::MANUAL_REVIEW);
        $before = $request->fresh()->getAttributes();
        $this->reconcile($request);
        $this->assertSame($before, $request->fresh()->getAttributes());
        $this->assertSame(2, ApiRequestLog::query()->where('operation', 'reconcile_virtual_account_assignment')->count());
    }

    public function test_complete_multi_page_listing_never_selects_first_or_latest_id(): void
    {
        $request = $this->request();
        $first = array_map(fn ($i) => ['uniquePaymentId' => 'VA-MULTI-'.$i], range(1, 20));
        Http::fakeSequence()->push(['content' => $first, 'number' => 0, 'totalPages' => 2, 'totalElements' => 21, 'last' => false])
            ->push(['content' => [['uniquePaymentId' => 'VA-FINAL']], 'number' => 1, 'totalPages' => 2, 'totalElements' => 21, 'last' => true]);
        $this->reconcile($request);
        $this->assertHeld($request, Operation::MANUAL_REVIEW);
        Http::assertSentCount(2);
    }

    public function test_page_limit_preserves_unknown_even_with_valid_ids(): void
    {
        $request = $this->request();
        Http::fake(function ($outgoing) {
            $page = $outgoing['page'];

            return Http::response(['content' => array_map(fn ($i) => ['uniquePaymentId' => 'VA-LIMIT-'.$page.'-'.$i], range(1, 20)), 'totalPages' => 6, 'last' => false]);
        });
        $this->reconcile($request);
        $this->assertHeld($request, Operation::UNKNOWN);
        Http::assertSentCount(5);
    }

    #[DataProvider('contradictions')]
    public function test_correlated_post_with_malformed_id_or_contradictory_dimension_holds_scope(array $response): void
    {
        $request = $this->request();
        $this->ledgerResponseEvidence($request, $response);
        $this->reconcile($request);
        $this->assertHeld($request, Operation::MANUAL_REVIEW);
        Http::assertNothingSent();
    }

    public static function contradictions(): array
    {
        $cases = [[['uniquePaymentId' => "VA\nINVALID"]], [['uniquePaymentId' => 42]]];
        foreach (['currencyCode', 'currency', 'accountCategory', 'accountType', 'clientHashId', 'customerHashId', 'walletHashId', 'bankName'] as $dimension) {
            $cases[] = [['uniquePaymentId' => 'VA-CONTRADICTION', $dimension => 'WRONG']];
        }

        return $cases;
    }

    public function test_get_explicit_target_contradiction_requires_review(): void
    {
        $request = $this->request();
        Http::fake(fn () => Http::response(['content' => [['uniquePaymentId' => 'VA-WRONG-WALLET', 'walletHashId' => 'different-wallet']], 'last' => true]));
        $this->reconcile($request);
        $this->assertHeld($request, Operation::MANUAL_REVIEW);
    }

    public function test_conflicting_correlated_post_successes_cannot_resolve(): void
    {
        $request = $this->request();
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-FIRST']);
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-SECOND']);
        $this->reconcile($request);
        $this->assertHeld($request, Operation::MANUAL_REVIEW);
        Http::assertNothingSent();
    }

    public function test_explicit_post_contradiction_cannot_be_ignored_in_favor_of_a_valid_success(): void
    {
        $request = $this->request();
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-VALID']);
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-VALID', 'currency' => 'EUR']);
        $this->reconcile($request);
        $this->assertHeld($request, Operation::MANUAL_REVIEW);
        Http::assertNothingSent();
    }

    #[DataProvider('wrongBindings')]
    public function test_unrelated_provider_response_cannot_resolve(string $binding): void
    {
        $request = $this->request();
        $log = $this->ledgerResponseEvidence($request);
        match ($binding) {
            'uuid' => $log->niumExchangeEvidence->update(['request_headers' => ['x-request-id' => ['different-request']]]),
            'target' => $log->niumExchangeEvidence->update(['assignment_target' => 'https://other.test'.$request->identity()->snapshot()['endpoint']]),
            'hash' => $log->niumExchangeEvidence->update(['response_body_sha256' => str_repeat('0', 64)]),
            'key' => $log->update(['external_reference' => 'unrelated-operation']),
        };
        Http::fake(fn () => Http::response(['content' => [], 'last' => true]));
        $this->reconcile($request);
        $this->assertHeld($request, Operation::UNKNOWN);
    }

    public static function wrongBindings(): array
    {
        return [['uuid'], ['target'], ['hash'], ['key']];
    }

    public function test_existing_cross_account_va_is_not_overwritten_or_adopted(): void
    {
        $request = $this->request();
        $other = $this->ledgerAccount();
        $existing = NiumVirtualAccount::query()->create([
            'user_provider_account_id' => $other->id, 'provider_payment_id' => 'VA-OWNED',
            'currency' => 'USD', 'account_category' => 'COLLECTION_ACCOUNT', 'account_type' => 'LOCAL', 'status' => 'assigned',
        ]);
        $before = $existing->fresh()->getAttributes();
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-OWNED']);
        $this->reconcile($request);
        $this->assertHeld($request, Operation::MANUAL_REVIEW);
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertSame(1, NiumVirtualAccount::query()->count());
    }

    public function test_failed_va_insert_rolls_back_outcome_attachment_and_scope_release(): void
    {
        $request = $this->request();
        $this->ledgerResponseEvidence($request);
        $failInsert = true;
        DB::connection()->beforeExecuting(function (string $query) use (&$failInsert): void {
            if ($failInsert && str_starts_with($query, 'insert into "nium_virtual_accounts"')) {
                throw new RuntimeException('Synthetic VA insertion failure');
            }
        });
        $this->reconcile($request);
        $this->assertHeld($request, Operation::UNKNOWN);
        $this->assertSame(2, $request->fresh()->state_version);
        $this->assertSame(0, NiumVirtualAccount::query()->count());
        $failInsert = false;
        $this->assertSame(Operation::ASSIGNED, $this->reconcile($request)->status);
    }

    public function test_prepared_request_never_dispatches_or_obtains_a_baseline(): void
    {
        $request = $this->request(consumed: false);
        $this->reconcile($request);
        app(NiumVirtualAccountReconciliationService::class)->captureBaseline($request->operation);
        $this->assertHeld($request, Operation::CLAIMED);
        $this->assertSame(0, $request->dispatchAttempt()->count());
        Http::assertNothingSent();
    }

    public function test_legacy_attachment_primitive_cannot_bypass_enrolled_resolution(): void
    {
        $request = $this->request();
        try {
            app(NiumVirtualAccountReconciliationService::class)->assign($request->operation, 'VA-UNVERIFIED', 'unverified');
            $this->fail('Legacy assignment must refuse enrolled operations.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('verified ledger evidence', $exception->getMessage());
        }
        $this->assertHeld($request, Operation::UNKNOWN);
    }

    #[DataProvider('targetChanges')]
    public function test_changed_current_target_or_ownership_prevents_resolution(string $field): void
    {
        $request = $this->request();
        $this->ledgerResponseEvidence($request);
        if ($field === 'origin') {
            config(['services.nium.base_url' => 'https://changed.test']);
        } elseif ($field === 'client') {
            config(['services.nium.client_id' => 'different-client']);
        } else {
            $request->operation->providerAccount->update([$field => $field === 'user_id' ? User::factory()->create()->id : 'different-target']);
        }
        $this->reconcile($request);
        $this->assertHeld($request, Operation::UNKNOWN);
        Http::assertNothingSent();
    }

    public static function targetChanges(): array
    {
        return [['origin'], ['client'], ['external_customer_id'], ['external_account_id'], ['user_id']];
    }

    public function test_terminal_failure_is_immutable_and_does_not_trigger_get(): void
    {
        $request = $this->request();
        $log = $this->ledgerResponseEvidence($request, ['errors' => [['code' => 'invalid_input', 'field' => 'currency']]], 400);
        (new NiumVirtualAccountSafetyLedger)->resolveFromPostEvidence($request, 2, $log);
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-LATE']);
        $before = $request->fresh()->getAttributes();
        $this->assertSame(Operation::FAILED, $this->reconcile($request)->status);
        $this->assertSame($before, $request->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_read_only_reconciliation_does_not_request_authentication_tokens(): void
    {
        $request = $this->request();
        config(['services.nium.auth.mode' => 'client_credentials']);
        $this->reconcile($request);
        $this->assertHeld($request, Operation::UNKNOWN);
        Http::assertNothingSent();
    }
}
