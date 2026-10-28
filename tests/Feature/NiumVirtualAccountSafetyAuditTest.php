<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\IntegrationProvider;
use App\Models\NiumVirtualAccount;
use App\Models\NiumVirtualAccountOperation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Nium\NiumEvidencePersistenceException;
use App\Services\Nium\NiumPaymentIdService;
use App\Services\Nium\NiumVirtualAccountDetailService;
use App\Services\Nium\NiumWebhookService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class NiumVirtualAccountSafetyAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Receipt claims must commit independently, outside a test-wide transaction.
        config(['database.connections.nium_legacy_webhook_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('nium_legacy_webhook_test');
        $this->artisan('migrate', ['--database' => 'nium_legacy_webhook_test', '--force' => true])->assertSuccessful();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        DB::purge('nium_legacy_webhook_test');
        parent::tearDown();
    }

    public function test_two_intentional_usd_assignments_persist_distinct_payment_ids(): void
    {
        [, , $account] = $this->eligibleAccount();
        $responses = [
            ['uniquePaymentId' => 'VA-USD-ONE', 'currencyCode' => 'USD'],
            ['uniquePaymentId' => 'VA-USD-TWO', 'currencyCode' => 'USD'],
        ];

        Http::fake(function (ClientRequest $request) use (&$responses) {
            if ($request->method() === 'GET') {
                return Http::response(['content' => []]);
            }

            return Http::response(array_shift($responses) + [
                'accountCategory' => 'COLLECTION_ACCOUNT',
                'accountType' => 'LOCAL',
            ]);
        });

        $service = app(NiumPaymentIdService::class);
        $first = $service->assign($account, 'USD', 'COLLECTION_ACCOUNT', 'LOCAL', operationId: 'intentional-usd-one');
        $second = $service->assign($account, 'USD', 'COLLECTION_ACCOUNT', 'LOCAL', operationId: 'intentional-usd-two');

        $this->assertNotSame($first->provider_payment_id, $second->provider_payment_id);
        $this->assertSame(2, NiumVirtualAccount::query()->where('currency', 'USD')->count());
        Http::assertSentCount(4);
        Http::assertSentInOrder([
            fn (ClientRequest $request): bool => $request->method() === 'GET',
            fn (ClientRequest $request): bool => $request->method() === 'POST',
            fn (ClientRequest $request): bool => $request->method() === 'GET',
            fn (ClientRequest $request): bool => $request->method() === 'POST',
        ]);
    }

    public function test_retry_of_same_logical_assignment_does_not_repeat_provider_post(): void
    {
        [, , $account] = $this->eligibleAccount();

        Http::fake(fn (ClientRequest $request) => $request->method() === 'GET'
            ? Http::response(['content' => []])
            : Http::response([
                'uniquePaymentId' => 'VA-RETRY-SAME',
                'currencyCode' => 'USD',
                'accountCategory' => 'COLLECTION_ACCOUNT',
                'accountType' => 'LOCAL',
            ]));

        $service = app(NiumPaymentIdService::class);
        $first = $service->assign($account, 'USD', 'COLLECTION_ACCOUNT', 'LOCAL', operationId: 'same-logical-operation');
        $retry = $service->assign($account, 'USD', 'COLLECTION_ACCOUNT', 'LOCAL', operationId: 'same-logical-operation');

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, NiumVirtualAccount::query()->count());
        Http::assertSentCount(2);
        $this->assertSame(1, collect(Http::recorded())->filter(
            fn (array $pair): bool => $pair[0]->method() === 'POST',
        )->count());
    }

    public function test_reversed_uncorrelated_webhooks_leave_pending_records_unchanged(): void
    {
        [$provider, , $account] = $this->eligibleAccount();
        $first = NiumVirtualAccount::query()->create([
            'user_provider_account_id' => $account->id,
            'currency' => 'USD',
            'account_category' => 'COLLECTION_ACCOUNT',
            'account_type' => 'LOCAL',
            'status' => 'pending',
        ]);
        $second = NiumVirtualAccount::query()->create([
            'user_provider_account_id' => $account->id,
            'currency' => 'USD',
            'account_category' => 'COLLECTION_ACCOUNT',
            'account_type' => 'LOCAL',
            'status' => 'pending',
        ]);

        Http::fake(fn (ClientRequest $request) => Http::response([
            'content' => [[
                'uniquePaymentId' => $request->data()['uniquePaymentId'] ?? null,
                'accountName' => 'Matched account',
            ]],
        ]));

        $this->deliverVaWebhook($provider, 'event-second', 'VA-SECOND', '2026-10-20T10:02:00Z');
        $this->deliverVaWebhook($provider, 'event-first', 'VA-FIRST', '2026-10-20T10:01:00Z');

        $this->assertNull($first->fresh()->provider_payment_id);
        $this->assertNull($second->fresh()->provider_payment_id);
        $this->assertDatabaseMissing('nium_virtual_accounts', ['provider_payment_id' => 'VA-FIRST']);
        $this->assertDatabaseMissing('nium_virtual_accounts', ['provider_payment_id' => 'VA-SECOND']);
        $this->assertSame(2, NiumVirtualAccount::query()->count());
        Http::assertNothingSent();
    }

    public function test_detail_sync_selects_the_requested_payment_id_when_it_is_not_first(): void
    {
        [, , $account] = $this->eligibleAccount();
        $virtualAccount = NiumVirtualAccount::query()->create([
            'user_provider_account_id' => $account->id,
            'provider_payment_id' => 'VA-REQUESTED',
            'currency' => 'USD',
            'account_category' => 'COLLECTION_ACCOUNT',
            'account_type' => 'LOCAL',
            'status' => 'assigned',
        ]);

        Http::fake(fn () => Http::response(['content' => [
            [
                'uniquePaymentId' => 'VA-OTHER',
                'accountName' => 'Wrong account',
                'fullBankName' => 'Wrong bank',
            ],
            [
                'uniquePaymentId' => 'VA-REQUESTED',
                'accountName' => 'Requested account',
                'fullBankName' => 'Requested bank',
            ],
        ]]));

        $detail = app(NiumVirtualAccountDetailService::class)->sync($virtualAccount);

        $this->assertSame('Requested account', $detail?->account_name);
        $this->assertSame('Requested bank', $detail?->bank_name);
    }

    public function test_ambiguous_post_timeout_retains_manual_review_without_retry_post(): void
    {
        [, , $account] = $this->eligibleAccount();
        $postCount = 0;
        $getCount = 0;

        Http::fake(function (ClientRequest $request) use (&$postCount, &$getCount) {
            if ($request->method() === 'GET') {
                $getCount++;
                if ($getCount === 1) {
                    return Http::response(['content' => []]);
                }

                return Http::response(['content' => [[
                    'uniquePaymentId' => 'VA-ACCEPTED-BEFORE-TIMEOUT',
                    'currencyCode' => 'USD',
                    'accountCategory' => 'COLLECTION_ACCOUNT',
                    'accountType' => 'LOCAL',
                ]]]);
            }

            $postCount++;
            if ($postCount === 1) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response([
                'uniquePaymentId' => 'VA-DUPLICATE-RETRY',
                'currencyCode' => 'USD',
                'accountCategory' => 'COLLECTION_ACCOUNT',
                'accountType' => 'LOCAL',
            ]);
        });

        $service = app(NiumPaymentIdService::class);
        try {
            $service->assign($account, 'USD', 'COLLECTION_ACCOUNT', 'LOCAL', operationId: 'ambiguous-timeout-operation');
            $this->fail('Expected the first provider request to time out.');
        } catch (ConnectionException) {
            // The provider may still have accepted the timed-out request.
        }

        $reconciled = $service->assign($account, 'USD', 'COLLECTION_ACCOUNT', 'LOCAL', operationId: 'ambiguous-timeout-operation');

        $this->assertNull($reconciled->provider_payment_id);
        $this->assertSame(NiumVirtualAccountOperation::MANUAL_REVIEW, NiumVirtualAccountOperation::query()->sole()->status);
        $this->assertSame(1, $postCount);
    }

    public function test_duplicate_webhook_delivery_is_idempotent(): void
    {
        [$provider, , $account] = $this->eligibleAccount();
        NiumVirtualAccount::query()->create([
            'user_provider_account_id' => $account->id,
            'currency' => 'USD',
            'account_category' => 'COLLECTION_ACCOUNT',
            'account_type' => 'LOCAL',
            'status' => 'pending',
        ]);
        Http::fake(fn () => Http::response(['content' => [[
            'uniquePaymentId' => 'VA-DUPLICATE-WEBHOOK',
            'accountName' => 'Webhook account',
        ]]]));

        $first = $this->deliverVaWebhook($provider, 'duplicate-event', 'VA-DUPLICATE-WEBHOOK');
        $duplicate = $this->deliverVaWebhook($provider, 'duplicate-event', 'VA-DUPLICATE-WEBHOOK');

        $this->assertFalse($first['duplicate'] ?? false);
        $this->assertTrue($duplicate['duplicate']);
        $this->assertSame(1, WebhookEvent::query()->where('event_id', 'duplicate-event')->count());
        $this->assertSame(0, NiumVirtualAccount::query()->where('provider_payment_id', 'VA-DUPLICATE-WEBHOOK')->count());
        $this->assertSame('observed', WebhookEvent::query()->where('event_id', 'duplicate-event')->sole()->processing_status);
        Http::assertNothingSent();
    }

    public function test_get_after_initialized_post_retains_manual_review_without_another_provider_post(): void
    {
        [, , $account] = $this->eligibleAccount();
        $getCount = 0;
        $postCount = 0;
        Http::fake(function (ClientRequest $request) use (&$getCount, &$postCount) {
            if ($request->method() === 'GET') {
                $getCount++;

                return Http::response(['content' => $getCount === 1 ? [] : [[
                    'uniquePaymentId' => 'VA-MISSING-WEBHOOK',
                    'currencyCode' => 'USD',
                    'accountCategory' => 'COLLECTION_ACCOUNT',
                    'accountType' => 'LOCAL',
                ]]]);
            }

            $postCount++;

            return Http::response([
                'uniquePaymentId' => 'INITIALIZED',
                'currencyCode' => 'USD',
                'accountCategory' => 'COLLECTION_ACCOUNT',
                'accountType' => 'LOCAL',
            ]);
        });

        $service = app(NiumPaymentIdService::class);
        $pending = $service->assign(
            $account,
            'USD',
            'COLLECTION_ACCOUNT',
            'LOCAL',
            operationId: 'missing-webhook-operation',
        );
        $operation = NiumVirtualAccountOperation::query()->sole();
        $this->artisan('nium:reconcile-payment-id-operation', [
            'operation' => $operation->id,
        ])->assertSuccessful();
        $reconciled = $pending->fresh();

        $this->assertSame($pending->id, $reconciled->id);
        $this->assertNull($reconciled->provider_payment_id);
        $this->assertSame(1, $postCount);
        $this->assertSame(NiumVirtualAccountOperation::MANUAL_REVIEW, $operation->fresh()->status);
    }

    public function test_evidence_persistence_failure_enters_unknown_and_blocks_repost(): void
    {
        [, , $account] = $this->eligibleAccount();
        $postCount = 0;
        DB::statement(<<<'SQL'
            CREATE TRIGGER fail_nium_va_evidence
            BEFORE INSERT ON api_request_logs
            WHEN NEW.request_method = 'POST'
            BEGIN
                SELECT RAISE(ABORT, 'synthetic evidence persistence failure');
            END
        SQL);
        Http::fake(function (ClientRequest $request) use (&$postCount) {
            if ($request->method() === 'GET') {
                return Http::response(['content' => []]);
            }

            $postCount++;

            return Http::response([
                'uniquePaymentId' => 'VA-EVIDENCE-UNKNOWN',
                'currencyCode' => 'USD',
                'accountCategory' => 'COLLECTION_ACCOUNT',
                'accountType' => 'LOCAL',
            ]);
        });

        try {
            app(NiumPaymentIdService::class)->assign(
                $account,
                'USD',
                'COLLECTION_ACCOUNT',
                'LOCAL',
                operationId: 'evidence-failure-operation',
            );
            $this->fail('Expected evidence persistence to fail after the provider response.');
        } catch (NiumEvidencePersistenceException) {
            // The provider outcome is deliberately retained as unknown.
        }

        $retry = app(NiumPaymentIdService::class)->assign(
            $account,
            'USD',
            'COLLECTION_ACCOUNT',
            'LOCAL',
            operationId: 'evidence-failure-operation',
        );

        $this->assertNull($retry->provider_payment_id);
        $this->assertSame(1, $postCount);
        $this->assertSame(NiumVirtualAccountOperation::MANUAL_REVIEW, NiumVirtualAccountOperation::query()->sole()->status);
    }

    public function test_concurrent_loser_observes_durable_claim_without_provider_post(): void
    {
        [, , $account] = $this->eligibleAccount();
        $pending = NiumVirtualAccount::query()->create([
            'user_provider_account_id' => $account->id,
            'currency' => 'USD',
            'account_category' => 'COLLECTION_ACCOUNT',
            'account_type' => 'LOCAL',
            'status' => 'pending',
        ]);
        NiumVirtualAccountOperation::query()->create([
            'user_provider_account_id' => $account->id,
            'nium_virtual_account_id' => $pending->id,
            'operation_id' => 'concurrent-operation',
            'currency' => 'USD',
            'account_category' => 'COLLECTION_ACCOUNT',
            'account_type' => 'LOCAL',
            'status' => NiumVirtualAccountOperation::SUBMITTING,
            'claimed_at' => now(),
            'submitted_at' => now(),
        ]);
        Http::fake();

        $observed = app(NiumPaymentIdService::class)->assign(
            $account,
            'USD',
            'COLLECTION_ACCOUNT',
            'LOCAL',
            operationId: 'concurrent-operation',
        );

        $this->assertSame($pending->id, $observed->id);
        Http::assertNothingSent();
    }

    public function test_http_503_outcome_remains_unresolved_and_is_never_reposted(): void
    {
        [, , $account] = $this->eligibleAccount();
        $postCount = 0;
        Http::fake(function (ClientRequest $request) use (&$postCount) {
            if ($request->method() === 'GET') {
                return Http::response(['content' => []]);
            }
            $postCount++;

            return Http::response(['message' => 'temporarily unavailable'], 503);
        });

        try {
            app(NiumPaymentIdService::class)->assign(
                $account,
                'USD',
                'COLLECTION_ACCOUNT',
                'LOCAL',
                operationId: 'http-unknown-operation',
            );
            $this->fail('Expected an unknown provider outcome.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('outcome is unknown', $exception->getMessage());
        }

        app(NiumPaymentIdService::class)->assign(
            $account,
            'USD',
            'COLLECTION_ACCOUNT',
            'LOCAL',
            operationId: 'http-unknown-operation',
        );

        $this->assertSame(1, $postCount);
        $this->assertSame(NiumVirtualAccountOperation::MANUAL_REVIEW, NiumVirtualAccountOperation::query()->sole()->status);
    }

    public function test_baseline_get_failure_stops_before_provider_post(): void
    {
        [, , $account] = $this->eligibleAccount();
        Http::fake(['*' => Http::failedConnection('baseline unavailable')]);

        try {
            app(NiumPaymentIdService::class)->assign(
                $account,
                'USD',
                'COLLECTION_ACCOUNT',
                'LOCAL',
                operationId: 'failed-baseline-operation',
            );
            $this->fail('Expected baseline preflight to remain unresolved.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('preflight is unresolved', $exception->getMessage());
        }

        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET');
        Http::assertNotSent(fn (ClientRequest $request): bool => $request->method() === 'POST');
        $this->assertSame(
            NiumVirtualAccountOperation::PREFLIGHT_FAILED,
            NiumVirtualAccountOperation::query()->sole()->status,
        );
    }

    public function test_incomplete_baseline_stops_before_provider_post(): void
    {
        [, , $account] = $this->eligibleAccount();
        $items = collect(range(1, 20))->map(fn (int $index): array => [
            'uniquePaymentId' => "VA-BASELINE-{$index}",
        ])->all();
        Http::fake(fn () => Http::response(['content' => $items]));

        try {
            app(NiumPaymentIdService::class)->assign(
                $account,
                'USD',
                'COLLECTION_ACCOUNT',
                'LOCAL',
                operationId: 'incomplete-baseline-operation',
            );
            $this->fail('Expected incomplete baseline preflight to stop assignment.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('preflight is unresolved', $exception->getMessage());
        }

        Http::assertSentCount(5);
        Http::assertNotSent(fn (ClientRequest $request): bool => $request->method() === 'POST');
        $this->assertSame('baseline_lookup_incomplete', NiumVirtualAccountOperation::query()->sole()->resolution_code);
    }

    public function test_failed_preflight_can_be_retried_before_the_first_provider_post(): void
    {
        [, , $account] = $this->eligibleAccount();
        $getCount = 0;
        $postCount = 0;
        Http::fake(function (ClientRequest $request) use (&$getCount, &$postCount) {
            if ($request->method() === 'GET') {
                $getCount++;
                if ($getCount === 1) {
                    throw new ConnectionException('baseline unavailable');
                }

                return Http::response(['content' => []]);
            }

            $postCount++;

            return Http::response([
                'uniquePaymentId' => 'VA-AFTER-PREFLIGHT-RETRY',
                'currencyCode' => 'USD',
                'accountCategory' => 'COLLECTION_ACCOUNT',
                'accountType' => 'LOCAL',
            ]);
        });

        try {
            app(NiumPaymentIdService::class)->assign(
                $account,
                'USD',
                'COLLECTION_ACCOUNT',
                'LOCAL',
                operationId: 'retryable-preflight-operation',
            );
        } catch (RuntimeException) {
            // No provider-changing request was made, so this operation may safely retry preflight.
        }

        $assigned = app(NiumPaymentIdService::class)->assign(
            $account,
            'USD',
            'COLLECTION_ACCOUNT',
            'LOCAL',
            operationId: 'retryable-preflight-operation',
        );

        $this->assertSame('VA-AFTER-PREFLIGHT-RETRY', $assigned->provider_payment_id);
        $this->assertSame(1, $postCount);
    }

    public function test_unproven_reconciliation_cannot_claim_an_id_or_mutate_sibling_history(): void
    {
        [, , $account] = $this->eligibleAccount();
        foreach (['overlap-one', 'overlap-two'] as $operationId) {
            $pending = NiumVirtualAccount::query()->create([
                'user_provider_account_id' => $account->id,
                'currency' => 'USD',
                'account_category' => 'COLLECTION_ACCOUNT',
                'account_type' => 'LOCAL',
                'status' => 'pending',
            ]);
            NiumVirtualAccountOperation::query()->create([
                'user_provider_account_id' => $account->id,
                'nium_virtual_account_id' => $pending->id,
                'operation_id' => $operationId,
                'currency' => 'USD',
                'account_category' => 'COLLECTION_ACCOUNT',
                'account_type' => 'LOCAL',
                'status' => NiumVirtualAccountOperation::PENDING,
                'baseline_payment_ids' => [],
                'baseline_complete' => true,
                'claimed_at' => now(),
                'submitted_at' => now(),
            ]);
        }
        Http::fake(fn () => Http::response(['content' => [[
            'uniquePaymentId' => 'VA-OVERLAP-UNATTRIBUTED',
            'currencyCode' => 'USD',
            'accountCategory' => 'COLLECTION_ACCOUNT',
            'accountType' => 'LOCAL',
        ]]]));

        $operation = NiumVirtualAccountOperation::query()->oldest('id')->firstOrFail();
        $this->artisan('nium:reconcile-payment-id-operation', ['operation' => $operation->id])->assertSuccessful();

        $this->assertSame(
            [NiumVirtualAccountOperation::MANUAL_REVIEW, NiumVirtualAccountOperation::PENDING],
            NiumVirtualAccountOperation::query()->orderBy('id')->pluck('status')->all(),
        );
        $this->assertSame(0, NiumVirtualAccount::query()->whereNotNull('provider_payment_id')->count());
        Http::assertNotSent(fn (ClientRequest $request): bool => $request->method() === 'POST');
    }

    public function test_reconciliation_cannot_claim_a_payment_id_owned_by_another_virtual_account(): void
    {
        [, , $account] = $this->eligibleAccount();
        NiumVirtualAccount::query()->create([
            'user_provider_account_id' => $account->id,
            'provider_payment_id' => 'VA-ALREADY-OWNED',
            'virtual_account_reference' => 'VA-ALREADY-OWNED',
            'currency' => 'USD',
            'account_category' => 'COLLECTION_ACCOUNT',
            'account_type' => 'LOCAL',
            'status' => 'assigned',
        ]);
        $pending = NiumVirtualAccount::query()->create([
            'user_provider_account_id' => $account->id,
            'currency' => 'USD',
            'account_category' => 'COLLECTION_ACCOUNT',
            'account_type' => 'LOCAL',
            'status' => 'pending',
        ]);
        $operation = NiumVirtualAccountOperation::query()->create([
            'user_provider_account_id' => $account->id,
            'nium_virtual_account_id' => $pending->id,
            'operation_id' => 'cannot-claim-owned-id',
            'currency' => 'USD',
            'account_category' => 'COLLECTION_ACCOUNT',
            'account_type' => 'LOCAL',
            'status' => NiumVirtualAccountOperation::UNKNOWN,
            'baseline_payment_ids' => [],
            'baseline_complete' => true,
            'claimed_at' => now(),
            'submitted_at' => now(),
        ]);
        Http::fake(fn () => Http::response(['content' => [[
            'uniquePaymentId' => 'VA-ALREADY-OWNED',
            'currencyCode' => 'USD',
            'accountCategory' => 'COLLECTION_ACCOUNT',
            'accountType' => 'LOCAL',
        ]]]));

        $this->artisan('nium:reconcile-payment-id-operation', ['operation' => $operation->id])->assertSuccessful();

        $this->assertSame(NiumVirtualAccountOperation::MANUAL_REVIEW, $operation->fresh()->status);
        $this->assertSame('legacy_assignment_evidence_unproven', $operation->fresh()->resolution_code);
        $this->assertNull($pending->fresh()->provider_payment_id);
    }

    public function test_api_idempotency_key_reuses_the_same_assignment_operation(): void
    {
        [$provider, $user] = $this->eligibleAccount();
        $user->profile()->create(['user_type' => 'business', 'country_code' => 'HK']);
        $postCount = 0;
        Http::fake(function (ClientRequest $request) use (&$postCount) {
            if ($request->method() === 'GET') {
                return Http::response(['content' => []]);
            }
            $postCount++;

            return Http::response([
                'uniquePaymentId' => 'VA-API-IDEMPOTENT',
                'currencyCode' => 'USD',
                'accountCategory' => 'COLLECTION_ACCOUNT',
                'accountType' => 'LOCAL',
            ]);
        });
        $payload = [
            'currency' => 'USD',
            'account_category' => 'COLLECTION_ACCOUNT',
            'account_type' => 'LOCAL',
        ];

        $first = $this->withToken($this->issueTokenFor($user))
            ->withHeader('Idempotency-Key', 'api-va-operation-001')
            ->postJson("/api/user/users/{$user->id}/provider-accounts/{$provider->code}/virtual-account", $payload);
        $retry = $this->withToken($this->issueTokenFor($user))
            ->withHeader('Idempotency-Key', 'api-va-operation-001')
            ->postJson("/api/user/users/{$user->id}/provider-accounts/{$provider->code}/virtual-account", $payload);

        $first->assertCreated()->assertJsonPath('operation_id', 'api-va-operation-001');
        $retry->assertCreated()->assertJsonPath('virtual_account.id', $first->json('virtual_account.id'));
        $this->assertSame(1, $postCount);
    }

    public function test_artisan_command_remains_compatible_without_operation_option(): void
    {
        [, , $account] = $this->eligibleAccount();
        Http::fake(fn (ClientRequest $request) => $request->method() === 'GET'
            ? Http::response(['content' => []])
            : Http::response([
                'uniquePaymentId' => 'VA-ARTISAN-COMPATIBLE',
                'currencyCode' => 'USD',
                'accountCategory' => 'SELF_FUNDING_ACCOUNT',
                'accountType' => 'LOCAL',
            ]));

        $this->artisan('nium:assign-payment-id', [
            'account' => $account->id,
            'currency' => 'USD',
        ])->assertSuccessful();

        $this->assertDatabaseHas('nium_virtual_accounts', [
            'provider_payment_id' => 'VA-ARTISAN-COMPATIBLE',
        ]);
    }

    public function test_historical_same_currency_virtual_accounts_remain_unchanged(): void
    {
        [, , $account] = $this->eligibleAccount();
        foreach (['VA-HISTORICAL-ONE', 'VA-HISTORICAL-TWO'] as $paymentId) {
            NiumVirtualAccount::query()->create([
                'user_provider_account_id' => $account->id,
                'provider_payment_id' => $paymentId,
                'virtual_account_reference' => $paymentId,
                'currency' => 'USD',
                'account_category' => 'COLLECTION_ACCOUNT',
                'account_type' => 'LOCAL',
                'status' => 'assigned',
            ]);
        }

        $this->assertSame(2, NiumVirtualAccount::query()->where('currency', 'USD')->count());
        $this->assertSame(
            ['VA-HISTORICAL-ONE', 'VA-HISTORICAL-TWO'],
            NiumVirtualAccount::query()->orderBy('id')->pluck('provider_payment_id')->all(),
        );
    }

    public function test_unapproved_account_is_blocked_before_provider_post(): void
    {
        [, , $account] = $this->eligibleAccount();
        $account->update(['status' => 'pending']);
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nium customer and wallet are not eligible yet');

        try {
            app(NiumPaymentIdService::class)->assign(
                $account->fresh(),
                'USD',
                'COLLECTION_ACCOUNT',
                'LOCAL',
            );
        } finally {
            Http::assertNothingSent();
        }
    }

    private function eligibleAccount(): array
    {
        config()->set('services.nium.base_url', 'https://gateway.sandbox.nium.test');
        config()->set('services.nium.client_id', 'client-test');
        config()->set('services.nium.auth', [
            'mode' => 'header',
            'header_name' => 'x-api-key',
            'header_value' => 'test-key',
        ]);
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'test-partner-key');

        $provider = IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);
        $user = User::factory()->create(['kyc_status' => 'verified']);
        $account = $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => 'customer-test',
            'external_account_id' => 'wallet-test',
            'status' => 'active',
            'provider_status' => 'clear',
            'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(),
            'provider_ids_verified_at' => now(),
        ]);

        return [$provider, $user, $account];
    }

    private function deliverVaWebhook(
        IntegrationProvider $provider,
        string $eventId,
        string $paymentId,
        string $assignedAt = '2026-10-20T10:00:00Z',
    ): array {
        $payload = [
            'eventId' => $eventId,
            'template' => 'VA_ASSIGNED',
            'customerHashId' => 'customer-test',
            'walletHashId' => 'wallet-test',
            'uniquePaymentId' => $paymentId,
            'currencyCode' => 'USD',
            'accountCategory' => 'COLLECTION_ACCOUNT',
            'accountType' => 'LOCAL',
            'assignedAt' => $assignedAt,
        ];
        $request = Request::create('/api/webhooks/providers/nium', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PARTNER_KEY' => 'test-partner-key',
        ], content: json_encode($payload, JSON_THROW_ON_ERROR));

        return app(NiumWebhookService::class)->handleWebhook($provider, $request);
    }

    private function issueTokenFor(User $user): string
    {
        $plainToken = Str::random(80);

        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'test-token',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        return $plainToken;
    }
}
