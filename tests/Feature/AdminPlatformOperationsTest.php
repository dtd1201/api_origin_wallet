<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Balance;
use App\Models\IntegrationProvider;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminPlatformOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_read_audit_logs_wallets_and_ledger_entries(): void
    {
        $admin = $this->createAdminUser();
        $user = User::factory()->create([
            'email' => 'customer@example.com',
        ]);
        $provider = IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);
        $balance = Balance::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'external_account_id' => 'wallet_hash_123',
            'currency' => 'USD',
            'available_balance' => 1250.50,
            'ledger_balance' => 1300,
            'reserved_balance' => 49.50,
            'as_of' => now(),
        ]);
        LedgerEntry::query()->create([
            'balance_id' => $balance->id,
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'reference' => 'LEDGER-001',
            'entry_type' => 'credit',
            'status' => 'posted',
            'currency' => 'USD',
            'amount' => 100,
            'balance_after' => 1300,
            'source_type' => 'transfer',
            'source_id' => 'TRF-001',
            'description' => 'Incoming transfer',
            'posted_at' => now(),
        ]);
        AuditLog::query()->create([
            'user_id' => $admin->id,
            'action' => 'kyc.approved',
            'entity_type' => 'kyc_profile',
            'entity_id' => '42',
            'old_data' => ['status' => 'submitted'],
            'new_data' => ['status' => 'verified'],
            'ip_address' => '127.0.0.1',
        ]);

        $token = $this->issueTokenFor($admin);

        $this->withToken($token)
            ->getJson('/api/admin/audit-logs?entity_type=kyc_profile')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'kyc.approved')
            ->assertJsonPath('data.0.before.status', 'submitted')
            ->assertJsonPath('data.0.after.status', 'verified');

        $this->withToken($token)
            ->getJson('/api/admin/wallets?currency=USD')
            ->assertOk()
            ->assertJsonPath('data.0.account_reference', 'wallet_hash_123')
            ->assertJsonPath('data.0.hold_balance', '49.50000000')
            ->assertJsonPath('data.0.provider.code', 'nium');

        $this->withToken($token)
            ->getJson('/api/admin/ledger-entries?status=posted&search=LEDGER-001')
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'LEDGER-001')
            ->assertJsonPath('data.0.wallet.account_reference', 'wallet_hash_123')
            ->assertJsonPath('data.0.user.email', 'customer@example.com');
    }

    public function test_admin_can_check_provider_health_and_request_webhook_retry(): void
    {
        $admin = $this->createAdminUser();
        $provider = IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);
        $webhookEvent = WebhookEvent::query()->create([
            'provider_id' => $provider->id,
            'event_id' => 'evt_001',
            'event_type' => 'transfer.failed',
            'external_resource_id' => 'RT6431795378',
            'payload' => ['event_id' => 'evt_001'],
            'processing_status' => 'failed',
            'error_message' => 'Temporary processing error',
        ]);

        config()->set('services.nium.base_url', 'https://gateway.sandbox.nium.com');
        config()->set('services.nium.client_id', 'client_hash_123');
        config()->set('services.nium.auth', [
            'mode' => 'header',
            'header_name' => 'x-api-key',
            'header_value' => 'nium-api-key',
        ]);
        config()->set('services.nium.health_endpoint', '/api/v1/client/{client}');

        Http::fake([
            'https://gateway.sandbox.nium.com/api/v1/client/client_hash_123' => Http::response([
                'clientHashId' => 'client_hash_123',
                'status' => 'ACTIVE',
            ], 200),
        ]);

        $token = $this->issueTokenFor($admin);

        $this->withToken($token)
            ->postJson('/api/admin/provider-health/nium/check')
            ->assertOk()
            ->assertJsonPath('provider_health.status', 'operational')
            ->assertJsonPath('provider_health.provider_code', 'nium');

        $this->assertDatabaseHas('api_request_logs', [
            'provider_id' => $provider->id,
            'request_method' => 'GET',
            'response_status' => 200,
            'is_success' => true,
        ]);

        $this->withToken($token)
            ->getJson('/api/admin/provider-webhook-events?status=failed')
            ->assertOk()
            ->assertJsonPath('data.0.event_id', 'evt_001')
            ->assertJsonPath('data.0.status', 'failed');

        $this->withToken($token)
            ->postJson("/api/admin/provider-webhook-events/{$webhookEvent->id}/retry")
            ->assertOk()
            ->assertJsonPath('webhook_event.status', 'retrying')
            ->assertJsonPath('webhook_event.attempts', 1);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'webhook.retry_requested',
            'entity_type' => 'webhook_event',
            'entity_id' => (string) $webhookEvent->id,
        ]);
    }

    private function createAdminUser(): User
    {
        $user = User::factory()->create();
        $user->roles()->create([
            'role_code' => 'admin',
        ]);

        return $user;
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
