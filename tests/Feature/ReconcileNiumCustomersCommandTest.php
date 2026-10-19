<?php

namespace Tests\Feature;

use App\Models\IntegrationProvider;
use App\Models\KycNotificationEvent;
use App\Models\NiumRfiCase;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumCorporateRfiService;
use App\Services\Nium\NiumCustomerReconciliationService;
use App\Services\Nium\NiumProviderAccountStateService;
use App\Services\Nium\NiumRfiWorkflowService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Monolog\Handler\TestHandler;
use RuntimeException;
use Tests\TestCase;

final class ReconcileNiumCustomersCommandTest extends TestCase
{
    use RefreshDatabase;

    private IntegrationProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'services.nium.base_url' => 'https://gateway.sandbox.nium.test',
            'services.nium.client_id' => 'client-reconcile-test',
            'services.nium.auth' => ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'test-key'],
            'services.nium.webhook.static_header_name' => 'x-partner-key',
            'services.nium.webhook.static_header_value' => 'test-partner-key',
            'services.nium.health_endpoint' => '/api/v1/client/{clientHashId}',
            'services.nium.customer_create_endpoint' => '/api/v5/client/{clientHashId}/customers',
            'services.nium.customer_list_endpoint' => '/api/v5/client/{clientHashId}/customers',
            'services.nium.customer_get_endpoint' => '/api/v5/client/{clientHashId}/customer/{customerHashId}',
            'services.nium.customer_rfi_fetch_endpoint' => '/api/v1/client/{clientHashId}/corporate/rfi',
            'services.nium.customer_reconciliation_enabled' => true,
            'services.nium.customer_reconciliation_batch_size' => 50,
            'services.nium.customer_reconciliation_poll_interval_seconds' => 900,
            'services.nium.customer_reconciliation_lock_ttl_seconds' => 180,
            'services.nium.customer_reconciliation_max_backoff_seconds' => 3600,
        ]);
        $this->provider = IntegrationProvider::query()->create(['code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
    }

    public function test_eligible_pending_customer_is_reconciled_and_clear_verified_ids_activate_it(): void
    {
        $account = $this->account();
        Http::fake(['*' => Http::response($this->customerPayload($account, 'clear', null), 200)]);

        $this->artisan('nium:reconcile-customers')->assertExitCode(0);

        $account->refresh();
        $this->assertSame('active', $account->status);
        $this->assertSame('reconciled', $account->reconciliation_status);
        $this->assertNotNull($account->reconciled_at);
        $this->assertNotNull($account->wallet_id_verified_at);
        $this->assertOnlyCustomerGetWasSent();
    }

    public function test_pending_under_review_remains_restricted(): void
    {
        $account = $this->account();
        Http::fake(['*' => Http::response($this->customerPayload($account, 'pending', 'under_review'), 200)]);

        $this->artisan('nium:reconcile-customers')->assertExitCode(0);

        $this->assertSame('under_review', $account->fresh()->status);
        $this->assertSame('under_review', $account->fresh()->provider_sub_status);
        $this->assertOnlyCustomerGetWasSent();
    }

    public function test_requested_rfi_is_authoritatively_enriched_without_duplicate_case_or_notification(): void
    {
        Queue::fake();
        $account = $this->account([
            'status' => 'under_review',
            'provider_status' => 'pending',
            'provider_sub_status' => 'rfi_requested',
            'rfi_status' => 'requested',
        ]);
        $rfiHashId = 'scheduled-rfi-hash-001';
        $case = NiumRfiCase::query()->create([
            'provider_id' => $this->provider->id,
            'user_provider_account_id' => $account->id,
            'scope' => 'customer',
            'origin' => 'webhook',
            'provider_reference_fingerprint' => hash('sha256', 'webhook-event-001'),
            'status' => 'provisional',
            'evidence' => ['eventId' => 'webhook-event-001'],
        ]);
        Http::fake(function (Request $request) use ($account, $rfiHashId) {
            if (str_contains($request->url(), '/corporate/rfi')) {
                return Http::response(['rfiTemplates' => [[
                    'rfiHashId' => $rfiHashId,
                    'templateId' => 'scheduled-template-001',
                    'template' => [
                        'name' => 'otherDocument',
                        'documentType' => 'document',
                        'type' => 'document',
                        'rfiType' => 'corporate',
                        'requiredFields' => [[
                            'fieldLabel' => 'Company Source of Funds and Source of Wealth',
                            'fieldValue' => 'document',
                            'type' => 'document',
                        ]],
                    ],
                    'remarks' => 'Provide company source of funds and source of wealth evidence.',
                    'status' => 'RFI_REQUESTED',
                ]]]);
            }

            return Http::response($this->customerPayload($account, 'pending', 'rfi_requested'));
        });

        $this->artisan('nium:reconcile-customers')->assertExitCode(0);
        $this->travel(16)->minutes();
        $this->artisan('nium:reconcile-customers')->assertExitCode(0);

        $case->refresh();
        $this->assertSame(['eventId' => 'webhook-event-001'], $case->evidence);
        $authoritative = NiumRfiCase::query()->where('origin', 'authoritative_fetch')->sole();
        $this->assertSame('scheduled-rfi-hash-001', data_get($authoritative->evidence, 'rfiHashId'));
        $this->assertSame('scheduled-template-001', data_get($authoritative->evidence, 'templateId'));
        $this->assertSame('otherDocument', data_get($authoritative->evidence, 'template.name'));
        $this->assertSame('Company Source of Funds and Source of Wealth', data_get($authoritative->evidence, 'template.requiredFields.0.fieldLabel'));
        $this->assertSame('under_review', $account->fresh()->status);
        $this->assertDatabaseCount('nium_rfi_cases', 2);
        $this->assertSame(1, KycNotificationEvent::query()->where('event_type', 'rfi')->count());
        Http::assertSentCount(4);
        $this->assertCount(0, collect(Http::recorded())->filter(fn ($pair): bool => $pair[0]->method() !== 'GET'));
    }

    public function test_real_four_rfi_shape_is_parsed_and_persisted_idempotently_without_touching_webhook_history(): void
    {
        $account = $this->account(['provider_status' => 'pending', 'provider_sub_status' => 'rfi_requested']);
        $historical = NiumRfiCase::query()->create([
            'provider_id' => $this->provider->id, 'user_provider_account_id' => $account->id,
            'scope' => 'customer', 'origin' => 'webhook',
            'provider_reference_fingerprint' => hash('sha256', 'original-webhook-event'),
            'status' => 'provisional', 'evidence' => ['eventId' => 'original-webhook-event'],
        ]);
        $templates = collect([
            ['businessRegistrationDocument', 'Business Registration Document'],
            ['otherDocument', 'Personal Source of Wealth'],
            ['otherDocument', 'Personal Source of Funds'],
            ['otherDocument', 'Company Source of Funds and Source of Wealth'],
        ])->map(fn (array $item, int $index): array => [
            'rfiHashId' => "provider-rfi-{$index}", 'templateId' => "template-{$index}",
            'template' => ['name' => $item[0], 'documentType' => 'document', 'type' => 'document', 'rfiType' => 'corporate',
                'requiredFields' => [['fieldLabel' => $item[1], 'fieldValue' => 'document', 'type' => 'document']]],
            'remarks' => "Authoritative instruction {$index}", 'status' => 'RFI_REQUESTED',
        ])->all();
        Http::fake(['*' => Http::response(['rfiTemplates' => $templates])]);

        $rfis = app(NiumCorporateRfiService::class)->fetch($account);
        app(NiumRfiWorkflowService::class)->reconcileCustomerEvidence($account, $rfis);
        app(NiumRfiWorkflowService::class)->reconcileCustomerEvidence($account, $rfis);

        $this->assertCount(4, $rfis);
        $this->assertDatabaseCount('nium_rfi_cases', 5);
        $this->assertSame(['eventId' => 'original-webhook-event'], $historical->fresh()->evidence);
        $this->assertSame(4, NiumRfiCase::query()->where('origin', 'authoritative_fetch')->distinct('provider_reference_fingerprint')->count());
        $this->assertNull(data_get($rfis, '0.requiredData'));
    }

    public function test_terminal_unverified_conflicted_and_unknown_create_accounts_are_skipped(): void
    {
        $rejected = $this->account(['status' => 'rejected', 'provider_status' => 'rejected']);
        $missing = $this->account(['external_customer_id' => null]);
        $unverified = $this->account(['customer_id_verified_at' => null]);
        $conflicted = $this->account(['security_conflict_at' => now(), 'security_conflict_reason' => 'external_customer_id_mismatch', 'reconciliation_status' => 'quarantined']);
        $unknown = $this->account(['reconciliation_status' => 'failed', 'reconciliation_error' => NiumProviderAccountStateService::CUSTOMER_CREATE_UNKNOWN]);
        Http::fake();

        $this->artisan('nium:reconcile-customers')->assertExitCode(0);

        foreach ([$rejected, $missing, $unverified, $conflicted, $unknown] as $account) {
            $this->assertNull($account->fresh()->reconciled_at);
        }
        Http::assertNothingSent();
    }

    public function test_authenticated_identifier_conflict_remains_quarantined(): void
    {
        $account = $this->account();
        Http::fake(['*' => Http::response([
            ...$this->customerPayload($account, 'clear', null),
            'customerHashId' => 'different-verified-customer',
        ], 200)]);

        $this->artisan('nium:reconcile-customers')->assertExitCode(1);

        $account->refresh();
        $this->assertSame('blocked', $account->status);
        $this->assertSame('quarantined', $account->reconciliation_status);
        $this->assertSame('verified_identifier_mismatch', $account->reconciliation_error);
        $this->assertNotNull($account->security_conflict_at);
        $this->assertOnlyCustomerGetWasSent();
    }

    public function test_poll_interval_prevents_premature_reconciliation(): void
    {
        $account = $this->account(['reconciliation_requested_at' => now()->subMinutes(5)]);
        Http::fake();

        $this->artisan('nium:reconcile-customers')->assertExitCode(0);

        $this->assertNull($account->fresh()->reconciled_at);
        Http::assertNothingSent();
    }

    public function test_account_lock_prevents_overlapping_get_calls(): void
    {
        $account = $this->account();
        $lock = Cache::lock('provider:nium:customer-reconciliation:'.$account->id, 180);
        $this->assertTrue($lock->get());
        Http::fake();
        try {
            $this->artisan('nium:reconcile-customers')->assertExitCode(0);
        } finally {
            $lock->release();
        }
        Http::assertNothingSent();
    }

    public function test_temporary_get_failure_preserves_trusted_state_and_records_backoff(): void
    {
        $account = $this->account(['provider_status' => 'pending', 'provider_sub_status' => 'under_review', 'status' => 'under_review']);
        $logs = $this->captureLogs();
        Http::fake(['*' => Http::response(['message' => 'sensitive-provider-payload'], 503)]);

        $this->assertSame(1, app(NiumCustomerReconciliationService::class)->reconcile()['failed']);

        $account->refresh();
        $this->assertSame('under_review', $account->status);
        $this->assertSame('pending', $account->provider_status);
        $this->assertSame('under_review', $account->provider_sub_status);
        $this->assertSame('scheduled_customer_get_failed', $account->reconciliation_error);
        $this->assertSame(1, data_get($account->metadata, 'nium_customer_reconciliation.failure_count'));
        $this->assertNotNull(data_get($account->metadata, 'nium_customer_reconciliation.next_attempt_at'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'provider_account.nium_scheduled_reconciliation_failed',
            'entity_id' => (string) $account->id,
        ]);
        $this->assertFailureLog($logs, $account, 'retrieve_customer', 'provider_request_failure');

        Http::fake();
        $this->artisan('nium:reconcile-customers')->assertExitCode(0);
        Http::assertNothingSent();
    }

    public function test_failure_logging_identifies_corporate_rfi_fetch_stage_without_retry(): void
    {
        $account = $this->account(['status' => 'under_review', 'provider_sub_status' => 'rfi_requested']);
        $logs = $this->captureLogs();
        Http::fake(function (Request $request) use ($account) {
            return str_contains($request->url(), '/corporate/rfi')
                ? Http::response(['message' => 'secret-rfi-provider-payload'], 503)
                : Http::response($this->customerPayload($account, 'pending', 'rfi_requested'));
        });

        $this->assertSame(1, app(NiumCustomerReconciliationService::class)->reconcile()['failed']);

        $this->assertFailureLog($logs, $account, 'fetch_corporate_rfi', 'provider_request_failure');
        Http::assertSentCount(2);
    }

    public function test_failure_logging_identifies_customer_evidence_reconciliation_stage(): void
    {
        $account = $this->account(['status' => 'under_review', 'provider_sub_status' => 'rfi_requested']);
        $logs = $this->captureLogs();
        Http::fake(function (Request $request) use ($account) {
            return str_contains($request->url(), '/corporate/rfi')
                ? Http::response(['rfiTemplates' => [$this->providerRfi('RFI_RESPONDED')]])
                : Http::response($this->customerPayload($account, 'pending', 'rfi_requested'));
        });

        $this->assertSame(1, app(NiumCustomerReconciliationService::class)->reconcile()['failed']);

        $this->assertFailureLog($logs, $account, 'reconcile_customer_evidence', 'validation_or_state_failure');
        Http::assertSentCount(2);
    }

    public function test_failure_logging_identifies_failure_metadata_cleanup_stage(): void
    {
        $account = $this->account(['metadata' => ['nium_customer_reconciliation' => ['failure_count' => 1]]]);
        $armed = false;
        $retrievalsAfterReconciliation = 0;
        UserProviderAccount::saved(function (UserProviderAccount $saved) use ($account, &$armed): void {
            if ($saved->id === $account->id && $saved->reconciliation_status === 'reconciled') {
                $armed = true;
            }
        });
        UserProviderAccount::retrieved(function (UserProviderAccount $retrieved) use ($account, &$armed, &$retrievalsAfterReconciliation): void {
            if ($armed && $retrieved->id === $account->id && ++$retrievalsAfterReconciliation === 3) {
                throw new RuntimeException('secret cleanup failure with provider payload');
            }
        });
        $logs = $this->captureLogs();
        Http::fake(['*' => Http::response($this->customerPayload($account, 'pending', null))]);

        $this->assertSame(1, app(NiumCustomerReconciliationService::class)->reconcile()['failed']);

        $this->assertFailureLog($logs, $account, 'clear_failure_metadata', 'validation_or_state_failure');
        Http::assertSentCount(1);
    }

    public function test_one_failed_account_does_not_prevent_later_eligible_account_reconciliation(): void
    {
        $failed = $this->account();
        $successful = $this->account();
        Http::fake(function (Request $request) use ($failed, $successful) {
            return str_contains($request->url(), (string) $failed->external_customer_id)
                ? Http::response(['message' => 'temporary'], 503)
                : Http::response($this->customerPayload($successful, 'pending', 'under_review'));
        });

        $this->artisan('nium:reconcile-customers')->assertExitCode(1);

        $this->assertSame('scheduled_customer_get_failed', $failed->fresh()->reconciliation_error);
        $this->assertSame('reconciled', $successful->fresh()->reconciliation_status);
        Http::assertSentCount(2);
    }

    public function test_batching_is_bounded_and_oldest_accounts_are_selected_first(): void
    {
        $oldest = $this->account(['reconciliation_requested_at' => now()->subHours(3)]);
        $middle = $this->account(['reconciliation_requested_at' => now()->subHours(2)]);
        $newest = $this->account(['reconciliation_requested_at' => now()->subHour()]);
        Http::fake(fn (Request $request) => Http::response($this->customerPayloadForUrl($request->url()), 200));

        $this->artisan('nium:reconcile-customers', ['--limit' => 2])->assertExitCode(0);

        $this->assertNotNull($oldest->fresh()->reconciled_at);
        $this->assertNotNull($middle->fresh()->reconciled_at);
        $this->assertNull($newest->fresh()->reconciled_at);
        Http::assertSentCount(2);
        $this->assertOnlyCustomerGetWasSent();
    }

    public function test_scheduler_registers_fifteen_minute_single_server_non_overlapping_command(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'nium:reconcile-customers'));

        $this->assertNotNull($event);
        $this->assertSame('*/15 * * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);
    }

    private function account(array $overrides = []): UserProviderAccount
    {
        $user = User::factory()->create(['status' => 'active']);
        return UserProviderAccount::query()->create(array_replace([
            'user_id' => $user->id, 'provider_id' => $this->provider->id,
            'external_customer_id' => 'customer-'.$user->id, 'external_reference' => 'reference-'.$user->id,
            'status' => 'submitted', 'provider_status' => 'pending', 'provider_sub_status' => null,
            'customer_id_verified_at' => now()->subDay(), 'reconciliation_status' => 'pending',
            'reconciliation_requested_at' => now()->subHour(), 'metadata' => [],
        ], $overrides));
    }

    private function customerPayload(UserProviderAccount $account, string $status, ?string $subStatus): array
    {
        return array_filter([
            'customerHashId' => $account->external_customer_id,
            'externalId' => $account->external_reference,
            'walletHashId' => 'wallet-'.$account->id,
            'status' => $status,
            'subStatus' => $subStatus,
        ], static fn ($value) => $value !== null);
    }

    private function customerPayloadForUrl(string $url): array
    {
        $account = UserProviderAccount::query()->get()->first(
            fn (UserProviderAccount $candidate): bool => str_contains($url, (string) $candidate->external_customer_id),
        );
        return $this->customerPayload($account, 'pending', 'under_review');
    }

    private function assertOnlyCustomerGetWasSent(): void
    {
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/api/v5/client/client-reconcile-test/customer/'));
        $this->assertCount(0, collect(Http::recorded())->filter(fn ($pair): bool => $pair[0]->method() !== 'GET'));
    }

    private function providerRfi(string $status): array
    {
        return [
            'rfiHashId' => 'synthetic-rfi-hash',
            'templateId' => 'synthetic-template-id',
            'template' => [
                'name' => 'otherDocument',
                'documentType' => 'document',
                'type' => 'document',
                'rfiType' => 'corporate',
                'requiredFields' => [[
                    'fieldLabel' => 'Synthetic bank statement',
                    'fieldValue' => 'document',
                    'type' => 'document',
                ]],
            ],
            'remarks' => 'Synthetic evidence only.',
            'status' => $status,
        ];
    }

    private function captureLogs(): TestHandler
    {
        $handler = new TestHandler();
        Log::getLogger()->pushHandler($handler);

        return $handler;
    }

    private function assertFailureLog(TestHandler $logs, UserProviderAccount $account, string $stage, string $diagnostic): void
    {
        $records = collect($logs->getRecords())->filter(
            fn ($record): bool => $record->message === 'Nium customer reconciliation failed.',
        )->values();
        $this->assertCount(1, $records);
        $context = $records->first()->context;
        $this->assertSame([
            'provider_account_id',
            'error_category',
            'failure_stage',
            'diagnostic_category',
        ], array_keys($context));
        $this->assertSame($account->id, $context['provider_account_id']);
        $this->assertSame('authoritative_get_customer_failed', $context['error_category']);
        $this->assertSame($stage, $context['failure_stage']);
        $this->assertSame($diagnostic, $context['diagnostic_category']);
        $serialized = json_encode($context, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret', $serialized);
        $this->assertStringNotContainsString((string) $account->external_customer_id, $serialized);
        $this->assertStringNotContainsString('test-key', $serialized);
    }
}
