<?php

namespace Tests\Feature;

use App\Jobs\DispatchKycNotificationEventJob;
use App\Mail\KycStatusNotificationMail;
use App\Models\IntegrationProvider;
use App\Models\KycNotificationDelivery;
use App\Models\KycNotificationEvent;
use App\Models\NiumRfiCase;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumKycNotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

final class NiumKycNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.kyc_notifications.extra_recipient', 'datlua01@gmail.com');
        Queue::fake();
    }

    public function test_approved_event_targets_all_active_super_admins_extra_recipient_and_customer_only_once(): void
    {
        $account = $this->account('Customer@Example.com');
        $this->admin('one@example.com', 'super_admin');
        $this->admin('two@example.com', 'super_admin');
        $this->admin('ordinary@example.com', 'compliance_officer');
        $this->admin('inactive@example.com', 'super_admin', 'blocked');
        $this->admin('DATLUA01@gmail.com', 'super_admin');
        $mismatched = $this->admin('mismatched@example.com', 'support_agent');
        $mismatched->roles()->update(['role_code' => 'super_admin']);

        $this->service()->recordStateTransition($account, ['status' => 'submitted'], ['status' => 'active']);

        $event = KycNotificationEvent::query()->sole();
        $this->assertSame('approved', $event->event_type);
        $this->assertEqualsCanonicalizing(
            ['one@example.com', 'two@example.com', 'datlua01@gmail.com', 'customer@example.com'],
            $event->deliveries()->pluck('normalized_email')->all(),
        );
        Queue::assertPushed(DispatchKycNotificationEventJob::class, 1);
    }

    public function test_rejected_transition_notifies_customer_but_unchanged_polling_does_not_duplicate(): void
    {
        $account = $this->account('customer@example.com');
        $this->service()->recordStateTransition($account, ['status' => 'submitted'], ['status' => 'rejected', 'provider_status' => 'rejected']);
        $this->service()->recordStateTransition($account, ['status' => 'rejected'], ['status' => 'rejected', 'provider_status' => 'rejected']);

        $this->assertDatabaseCount('kyc_notification_events', 1);
        $this->assertDatabaseHas('kyc_notification_deliveries', [
            'normalized_email' => 'customer@example.com', 'recipient_type' => 'user',
        ]);
    }

    public function test_pending_and_under_review_transitions_do_not_create_events(): void
    {
        $account = $this->account('customer@example.com');
        $this->service()->recordStateTransition($account, ['status' => 'pending'], ['status' => 'submitted']);
        $this->service()->recordStateTransition($account, ['status' => 'submitted'], ['status' => 'under_review']);

        $this->assertDatabaseCount('kyc_notification_events', 0);
    }

    public function test_admin_review_url_appends_customer_as_query_parameter(): void
    {
        config()->set('services.kyc_notifications.admin_review_url', 'https://admin.originwallet.asia/admin/kyc-reviews');
        $account = $this->account('customer@example.com');

        $this->service()->recordStateTransition($account, ['status' => 'submitted'], ['status' => 'active']);

        $this->assertSame(
            'https://admin.originwallet.asia/admin/kyc-reviews?user_id='.$account->user_id,
            KycNotificationEvent::query()->sole()->payload['admin_review_url'],
        );
    }

    public function test_admin_review_url_preserves_existing_query_parameters(): void
    {
        config()->set('services.kyc_notifications.admin_review_url', 'https://admin.originwallet.asia/admin/kyc-reviews?source=email');
        $account = $this->account('customer@example.com');

        $this->service()->recordStateTransition($account, ['status' => 'submitted'], ['status' => 'active']);

        $this->assertSame(
            'https://admin.originwallet.asia/admin/kyc-reviews?source=email&user_id='.$account->user_id,
            KycNotificationEvent::query()->sole()->payload['admin_review_url'],
        );
    }

    public function test_empty_admin_review_url_is_omitted(): void
    {
        config()->set('services.kyc_notifications.admin_review_url', '');
        $account = $this->account('customer@example.com');

        $this->service()->recordStateTransition($account, ['status' => 'submitted'], ['status' => 'active']);

        $this->assertNull(KycNotificationEvent::query()->sole()->payload['admin_review_url']);
    }

    public function test_webhook_and_reconciliation_race_cannot_create_duplicate_transition_events(): void
    {
        $account = $this->account('customer@example.com');
        $this->service()->recordStateTransition($account, ['status' => 'submitted'], ['status' => 'active']);
        $this->service()->recordStateTransition($account, ['status' => 'submitted'], ['status' => 'active']);

        $this->assertDatabaseCount('kyc_notification_events', 1);
    }

    public function test_confirmed_rfi_notifies_admins_only_and_is_idempotent(): void
    {
        $account = $this->account('customer@example.com');
        $case = NiumRfiCase::query()->create([
            'provider_id' => $account->provider_id,
            'user_provider_account_id' => $account->id,
            'scope' => 'customer',
            'provider_reference_fingerprint' => hash('sha256', 'rfi-1'),
            'status' => 'requested',
            'evidence' => ['rfiHashId' => 'rfi-1', 'rfiStatus' => 'RFI_REQUESTED'],
        ]);

        $this->service()->recordConfirmedRfi($account, $case);
        $this->service()->recordConfirmedRfi($account, $case);

        $this->assertDatabaseCount('kyc_notification_events', 1);
        $this->assertDatabaseMissing('kyc_notification_deliveries', ['normalized_email' => 'customer@example.com']);
        $this->assertDatabaseHas('kyc_notification_deliveries', ['normalized_email' => 'datlua01@gmail.com']);
    }

    public function test_repeated_confirmed_rfi_does_not_abort_an_enclosing_postgres_transaction(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL.');
        }

        $account = $this->account('customer@example.com');
        $case = NiumRfiCase::query()->create([
            'provider_id' => $account->provider_id,
            'user_provider_account_id' => $account->id,
            'scope' => 'customer',
            'provider_reference_fingerprint' => hash('sha256', 'transaction-rfi'),
            'status' => 'requested',
            'evidence' => ['rfiHashId' => 'transaction-rfi', 'rfiStatus' => 'RFI_REQUESTED'],
        ]);

        DB::transaction(function () use ($account, $case): void {
            $this->service()->recordConfirmedRfi($account, $case);
            $this->service()->recordConfirmedRfi($account, $case);

            $this->assertSame(1, DB::table('kyc_notification_events')->count());
        });

        $this->assertDatabaseCount('kyc_notification_events', 1);
        $this->assertDatabaseCount('kyc_notification_deliveries', 1);
        Queue::assertPushed(DispatchKycNotificationEventJob::class, 1);
    }

    public function test_insert_or_ignore_does_not_hide_a_genuine_postgres_database_failure(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL.');
        }

        $account = $this->account('customer@example.com');
        $case = NiumRfiCase::query()->create([
            'provider_id' => $account->provider_id,
            'user_provider_account_id' => $account->id,
            'scope' => 'customer',
            'provider_reference_fingerprint' => hash('sha256', 'foreign-key-failure-rfi'),
            'status' => 'requested',
            'evidence' => ['rfiHashId' => 'foreign-key-failure-rfi', 'rfiStatus' => 'RFI_REQUESTED'],
        ]);
        $case->delete();
        $account->delete();

        try {
            DB::transaction(fn () => $this->service()->recordConfirmedRfi($account, $case));
            $this->fail('Expected the missing provider account foreign key to fail.');
        } catch (QueryException $exception) {
            $this->assertSame('23503', (string) ($exception->errorInfo[0] ?? ''));
        }
    }

    public function test_outbox_and_queue_dispatch_are_removed_by_transaction_rollback(): void
    {
        $account = $this->account('customer@example.com');
        try {
            DB::transaction(function () use ($account): void {
                $this->service()->recordStateTransition($account, ['status' => 'submitted'], ['status' => 'active']);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        $this->assertDatabaseCount('kyc_notification_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_retry_dispatch_skips_already_delivered_recipient(): void
    {
        Mail::fake();
        $account = $this->account('customer@example.com');
        $event = KycNotificationEvent::query()->create([
            'user_provider_account_id' => $account->id,
            'event_type' => 'approved',
            'idempotency_key' => hash('sha256', 'retry-event'),
            'payload' => ['display_name' => 'Customer', 'account_reference' => 'OW-1', 'verified_status' => 'clear'],
        ]);
        $event->deliveries()->create(['recipient_type' => 'admin', 'email' => 'done@example.com', 'normalized_email' => 'done@example.com', 'status' => 'delivered', 'attempts' => 1, 'delivered_at' => now()]);
        $event->deliveries()->create(['recipient_type' => 'user', 'email' => 'retry@example.com', 'normalized_email' => 'retry@example.com', 'status' => 'failed', 'attempts' => 1]);

        (new DispatchKycNotificationEventJob($event->id))->handle();

        Mail::assertSent(KycStatusNotificationMail::class, 1);
        $this->assertSame('delivered', KycNotificationDelivery::query()->where('normalized_email', 'retry@example.com')->value('status'));
        $this->assertNotNull($event->fresh()->completed_at);
    }

    public function test_transport_failure_is_recorded_and_remains_retryable(): void
    {
        $account = $this->account('customer@example.com');
        $event = KycNotificationEvent::query()->create([
            'user_provider_account_id' => $account->id,
            'event_type' => 'rejected',
            'idempotency_key' => hash('sha256', 'failed-event'),
            'payload' => ['display_name' => 'Customer', 'account_reference' => 'OW-1', 'verified_status' => 'rejected'],
        ]);
        $delivery = $event->deliveries()->create([
            'recipient_type' => 'user', 'email' => 'fail@example.com',
            'normalized_email' => 'fail@example.com', 'status' => 'pending',
        ]);
        Mail::shouldReceive('to')->once()->with('fail@example.com')->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new RuntimeException('smtp unavailable'));

        try {
            (new DispatchKycNotificationEventJob($event->id))->handle();
            $this->fail('Expected the job to request a retry.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('retryable recipient failures', $exception->getMessage());
        }

        $delivery->refresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame('smtp unavailable', $delivery->last_error);
        $this->assertNull($delivery->delivered_at);
        $this->assertNull($event->fresh()->completed_at);
    }

    private function service(): NiumKycNotificationService
    {
        return app(NiumKycNotificationService::class);
    }

    private function account(string $email): UserProviderAccount
    {
        $user = User::factory()->create(['email' => $email]);
        $provider = IntegrationProvider::query()->firstOrCreate(['code' => 'nium'], ['name' => 'Nium', 'status' => 'active']);

        return UserProviderAccount::query()->create(['user_id' => $user->id, 'provider_id' => $provider->id, 'status' => 'submitted']);
    }

    private function admin(string $email, string $roleCode, string $status = 'active'): User
    {
        $user = User::factory()->create(['email' => $email, 'status' => $status]);
        $role = Role::query()->firstOrCreate(['code' => $roleCode], ['name' => str($roleCode)->headline()]);
        $user->roles()->create(['role_id' => $role->id, 'role_code' => $roleCode]);

        return $user;
    }
}
