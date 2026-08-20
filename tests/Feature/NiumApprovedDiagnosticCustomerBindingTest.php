<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\AuditLog;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumApprovedDiagnosticCustomerBindingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class NiumApprovedDiagnosticCustomerBindingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFixture();
    }

    public function test_command_performs_exact_staging_transition_with_audit_and_no_http(): void
    {
        $this->app->detectEnvironment(fn (): string => 'staging');
        Http::fake();

        $this->artisan('nium:bind-approved-diagnostic-customer', [
            'customer-hash-id' => NiumApprovedDiagnosticCustomerBindingService::CUSTOMER_HASH_ID,
            'wallet-hash-id' => NiumApprovedDiagnosticCustomerBindingService::WALLET_HASH_ID,
            '--approve' => NiumApprovedDiagnosticCustomerBindingService::APPROVAL,
            '--operator' => 'sandbox-ticket-NIUM-143',
        ])->assertSuccessful();

        $account = UserProviderAccount::query()->findOrFail(7);
        $this->assertSame(NiumApprovedDiagnosticCustomerBindingService::CUSTOMER_HASH_ID, $account->external_customer_id);
        $this->assertSame(NiumApprovedDiagnosticCustomerBindingService::WALLET_HASH_ID, $account->external_account_id);
        $this->assertNotNull($account->customer_id_verified_at);
        $this->assertNotNull($account->wallet_id_verified_at);
        $this->assertNotNull($account->provider_ids_verified_at);

        $audit = AuditLog::query()->where('action', 'provider_account.nium_approved_diagnostic_binding')->sole();
        $this->assertSame('b4e39b04-08dc-4f03-810a-b96b60950ee1', $audit->old_data['old_customer_id']);
        $this->assertSame(NiumApprovedDiagnosticCustomerBindingService::APPROVAL, $audit->new_data['approval_marker']);
        $this->assertSame('b4e39b04-08dc-4f03-810a-b96b60950ee1', $audit->new_data['old_customer_id']);
        $this->assertSame('b005d6ca-ba6c-41d5-b379-d90d2b9be6bb', $audit->new_data['old_wallet_id']);
        $this->assertSame(NiumApprovedDiagnosticCustomerBindingService::CUSTOMER_HASH_ID, $audit->new_data['new_customer_id']);
        $this->assertSame(NiumApprovedDiagnosticCustomerBindingService::WALLET_HASH_ID, $audit->new_data['new_wallet_id']);
        $this->assertSame('sandbox-ticket-NIUM-143', $audit->new_data['operator_context']);
        $this->assertSame(143, $audit->new_data['evidence_log_id']);
        $this->assertNotEmpty($audit->new_data['timestamp']);
        Http::assertNothingSent();
    }

    public function test_wrong_approval_marker_is_rejected(): void
    {
        $this->app->detectEnvironment(fn (): string => 'staging');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('locked allowlist');

        app(NiumApprovedDiagnosticCustomerBindingService::class)->bind(
            NiumApprovedDiagnosticCustomerBindingService::CUSTOMER_HASH_ID,
            NiumApprovedDiagnosticCustomerBindingService::WALLET_HASH_ID,
            'WRONG_APPROVAL',
            'test',
        );
    }

    public function test_repeated_execution_after_successful_bind_is_rejected(): void
    {
        $this->app->detectEnvironment(fn (): string => 'staging');
        $service = app(NiumApprovedDiagnosticCustomerBindingService::class);
        $service->bind(
            NiumApprovedDiagnosticCustomerBindingService::CUSTOMER_HASH_ID,
            NiumApprovedDiagnosticCustomerBindingService::WALLET_HASH_ID,
            NiumApprovedDiagnosticCustomerBindingService::APPROVAL,
            'first-execution',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already executed');

        $service->bind(
            NiumApprovedDiagnosticCustomerBindingService::CUSTOMER_HASH_ID,
            NiumApprovedDiagnosticCustomerBindingService::WALLET_HASH_ID,
            NiumApprovedDiagnosticCustomerBindingService::APPROVAL,
            'second-execution',
        );
    }

    public function test_target_identifiers_without_audit_evidence_require_manual_review(): void
    {
        $this->app->detectEnvironment(fn (): string => 'staging');
        UserProviderAccount::query()->findOrFail(7)->update([
            'external_customer_id' => NiumApprovedDiagnosticCustomerBindingService::CUSTOMER_HASH_ID,
            'external_account_id' => NiumApprovedDiagnosticCustomerBindingService::WALLET_HASH_ID,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('without binding audit evidence; manual review is required');

        app(NiumApprovedDiagnosticCustomerBindingService::class)->bind(
            NiumApprovedDiagnosticCustomerBindingService::CUSTOMER_HASH_ID,
            NiumApprovedDiagnosticCustomerBindingService::WALLET_HASH_ID,
            NiumApprovedDiagnosticCustomerBindingService::APPROVAL,
            'test',
        );
    }

    public function test_binding_refuses_non_staging_environment(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('staging-only');

        app(NiumApprovedDiagnosticCustomerBindingService::class)->bind(
            NiumApprovedDiagnosticCustomerBindingService::CUSTOMER_HASH_ID,
            NiumApprovedDiagnosticCustomerBindingService::WALLET_HASH_ID,
            NiumApprovedDiagnosticCustomerBindingService::APPROVAL,
            'test',
        );
    }

    public function test_binding_refuses_arbitrary_identifiers_before_mutation(): void
    {
        $this->app->detectEnvironment(fn (): string => 'staging');

        try {
            app(NiumApprovedDiagnosticCustomerBindingService::class)->bind(
                '11111111-1111-1111-1111-111111111111',
                NiumApprovedDiagnosticCustomerBindingService::WALLET_HASH_ID,
                NiumApprovedDiagnosticCustomerBindingService::APPROVAL,
                'test',
            );
            $this->fail('Expected the locked allowlist to reject an arbitrary customer identifier.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('allowlist', $exception->getMessage());
        }

        $this->assertSame(
            'b4e39b04-08dc-4f03-810a-b96b60950ee1',
            UserProviderAccount::query()->findOrFail(7)->external_customer_id,
        );
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_binding_refuses_wrong_wallet_identifier_before_mutation(): void
    {
        $this->app->detectEnvironment(fn (): string => 'staging');

        try {
            app(NiumApprovedDiagnosticCustomerBindingService::class)->bind(
                NiumApprovedDiagnosticCustomerBindingService::CUSTOMER_HASH_ID,
                '22222222-2222-2222-2222-222222222222',
                NiumApprovedDiagnosticCustomerBindingService::APPROVAL,
                'test',
            );
            $this->fail('Expected the locked allowlist to reject an arbitrary wallet identifier.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('allowlist', $exception->getMessage());
        }

        $this->assertSame(
            'b005d6ca-ba6c-41d5-b379-d90d2b9be6bb',
            UserProviderAccount::query()->findOrFail(7)->external_account_id,
        );
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_binding_refuses_unlocked_account_state_or_missing_evidence(): void
    {
        $this->app->detectEnvironment(fn (): string => 'staging');
        UserProviderAccount::query()->findOrFail(7)->update(['provider_status' => 'clear']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('locked pre-transition fixture state');

        app(NiumApprovedDiagnosticCustomerBindingService::class)->bind(
            NiumApprovedDiagnosticCustomerBindingService::CUSTOMER_HASH_ID,
            NiumApprovedDiagnosticCustomerBindingService::WALLET_HASH_ID,
            NiumApprovedDiagnosticCustomerBindingService::APPROVAL,
            'test',
        );
    }

    private function seedFixture(): void
    {
        IntegrationProvider::query()->forceCreate([
            'id' => 7,
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);
        User::factory()->create([
            'id' => 9,
            'status' => 'active',
            'kyc_status' => 'verified',
        ]);
        UserProviderAccount::query()->forceCreate([
            'id' => 7,
            'user_id' => 9,
            'provider_id' => 7,
            'external_customer_id' => 'b4e39b04-08dc-4f03-810a-b96b60950ee1',
            'external_account_id' => 'b005d6ca-ba6c-41d5-b379-d90d2b9be6bb',
            'external_reference' => 'fixture-user-9',
            'status' => 'under_review',
            'provider_status' => 'pending',
            'provider_sub_status' => 'awaiting_kyc',
            'compliance_status' => null,
            'customer_id_verified_at' => now()->subDay(),
            'wallet_id_verified_at' => now()->subDay(),
            'provider_ids_verified_at' => now()->subDay(),
        ]);
        ApiRequestLog::query()->forceCreate([
            'id' => 143,
            'provider_id' => 7,
            'user_id' => 9,
            'operation' => 'customer_create_diagnostic_su_authorized_4',
            'request_method' => 'POST',
            'request_url' => 'https://sandbox.example.test/api/v5/client/redacted/customers',
            'response_status' => 200,
            'response_body' => [
                'status' => 'pending',
                'customer_hash_id' => NiumApprovedDiagnosticCustomerBindingService::CUSTOMER_HASH_ID,
                'wallet_hash_id' => NiumApprovedDiagnosticCustomerBindingService::WALLET_HASH_ID,
            ],
            'is_success' => true,
        ]);
    }
}
