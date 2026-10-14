<?php

namespace Tests\Feature;

use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumProviderAccountStateService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
        Http::fake(['*' => Http::response(['message' => 'temporary'], 503)]);

        $this->artisan('nium:reconcile-customers')->assertExitCode(1);

        $account->refresh();
        $this->assertSame('under_review', $account->status);
        $this->assertSame('pending', $account->provider_status);
        $this->assertSame('under_review', $account->provider_sub_status);
        $this->assertSame('scheduled_customer_get_failed', $account->reconciliation_error);
        $this->assertSame(1, data_get($account->metadata, 'nium_customer_reconciliation.failure_count'));
        $this->assertNotNull(data_get($account->metadata, 'nium_customer_reconciliation.next_attempt_at'));

        Http::fake();
        $this->artisan('nium:reconcile-customers')->assertExitCode(0);
        Http::assertNothingSent();
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
}
