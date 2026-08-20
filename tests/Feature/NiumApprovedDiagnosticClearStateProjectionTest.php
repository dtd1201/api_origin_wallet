<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\AuditLog;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumApprovedDiagnosticClearStateProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class NiumApprovedDiagnosticClearStateProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn (): string => 'staging');
        $this->seedFixture();
    }

    public function test_successful_projection_uses_state_machine_and_creates_audit_without_http(): void
    {
        Http::fake();

        $this->artisan('nium:project-approved-diagnostic-clear-state', [
            '--approve' => NiumApprovedDiagnosticClearStateProjectionService::APPROVAL,
            '--operator' => 'sandbox-ticket-NIUM-186',
        ])->assertSuccessful();

        $account = UserProviderAccount::query()->findOrFail(7);
        $this->assertSame('active', $account->status);
        $this->assertSame('clear', $account->provider_status);
        $this->assertNull($account->provider_sub_status);

        $audit = AuditLog::query()
            ->where('action', 'provider_account.nium_approved_diagnostic_clear_projection')
            ->sole();
        $this->assertSame('under_review', $audit->old_data['status']);
        $this->assertSame('awaiting_kyc', $audit->old_data['provider_sub_status']);
        $this->assertSame('active', $audit->new_data['status']);
        $this->assertSame('clear', $audit->new_data['provider_status']);
        $this->assertNull($audit->new_data['provider_sub_status']);
        $this->assertSame(NiumApprovedDiagnosticClearStateProjectionService::APPROVAL, $audit->new_data['approval_marker']);
        $this->assertSame('sandbox-ticket-NIUM-186', $audit->new_data['operator_context']);
        $this->assertSame(186, $audit->new_data['evidence_log_id']);
        Http::assertNothingSent();
    }

    public function test_wrong_approval_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('approval marker is invalid');

        app(NiumApprovedDiagnosticClearStateProjectionService::class)->project('WRONG_APPROVAL', 'test');
    }

    public function test_wrong_customer_is_rejected(): void
    {
        UserProviderAccount::query()->findOrFail(7)->update([
            'external_customer_id' => '11111111-1111-1111-1111-111111111111',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('identifiers do not match');

        $this->project();
    }

    public function test_missing_evidence_is_rejected(): void
    {
        ApiRequestLog::query()->whereKey(186)->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('evidence is unavailable or does not match');

        $this->project();
    }

    public function test_repeated_execution_is_rejected(): void
    {
        $service = app(NiumApprovedDiagnosticClearStateProjectionService::class);
        $service->project(
            NiumApprovedDiagnosticClearStateProjectionService::APPROVAL,
            'first-execution',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already executed');

        $service->project(
            NiumApprovedDiagnosticClearStateProjectionService::APPROVAL,
            'second-execution',
        );
    }

    private function project(): UserProviderAccount
    {
        return app(NiumApprovedDiagnosticClearStateProjectionService::class)->project(
            NiumApprovedDiagnosticClearStateProjectionService::APPROVAL,
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
            'external_customer_id' => NiumApprovedDiagnosticClearStateProjectionService::CUSTOMER_HASH_ID,
            'external_account_id' => NiumApprovedDiagnosticClearStateProjectionService::WALLET_HASH_ID,
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
            'id' => 186,
            'provider_id' => 7,
            'user_id' => 9,
            'operation' => NiumApprovedDiagnosticClearStateProjectionService::EVIDENCE_OPERATION,
            'request_method' => 'GET',
            'request_url' => 'https://sandbox.example.test/api/v5/client/redacted/customer/redacted',
            'response_status' => 200,
            'response_body' => [
                'status' => 'clear',
                'customer_hash_id' => NiumApprovedDiagnosticClearStateProjectionService::CUSTOMER_HASH_ID,
                'wallet_hash_id' => NiumApprovedDiagnosticClearStateProjectionService::WALLET_HASH_ID,
            ],
            'is_success' => true,
        ]);
    }
}
