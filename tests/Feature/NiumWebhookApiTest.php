<?php

namespace Tests\Feature;

use App\Models\Balance;
use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NiumWebhookApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_nium_webhook_accepts_correct_static_partner_key_and_keeps_payout_flow(): void
    {
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'nium-webhook-test-key');
        config()->set('wallet.ledger.enabled', false);

        $provider = $this->provider();
        $transfer = $this->transfer($provider, 'NIUM-EXT-1001');
        $payload = [
            'eventId' => 'nium-event-1001',
            'eventType' => 'remittance.completed',
            'data' => [
                'resource' => [
                    'systemReferenceNumber' => 'NIUM-EXT-1001',
                    'transactionId' => 'NIUM-TXN-1001',
                    'status' => 'COMPLETED',
                ],
            ],
        ];

        $response = $this->withHeader('x-partner-key', 'nium-webhook-test-key')
            ->postJson('/api/webhooks/providers/nium', $payload);

        $response->assertOk()
            ->assertJsonPath('event_id', 'nium-event-1001');

        $this->assertSame('completed', $transfer->fresh()->status);
        $this->assertDatabaseHas('webhook_events', [
            'provider_id' => $provider->id,
            'event_id' => 'nium-event-1001',
            'processing_status' => 'processed',
        ]);

        $this->withHeader('x-partner-key', 'nium-webhook-test-key')
            ->postJson('/api/webhooks/providers/nium', $payload)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_nium_webhook_rejects_missing_or_incorrect_static_partner_key(): void
    {
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'expected-nium-key');
        $this->provider();
        $payload = ['eventId' => 'rejected-nium-event', 'eventType' => 'remittance.completed'];

        $this->postJson('/api/webhooks/providers/nium', $payload)
            ->assertForbidden();

        $this->withHeader('x-partner-key', 'wrong-nium-key')
            ->postJson('/api/webhooks/providers/nium', $payload)
            ->assertForbidden();

        $this->assertDatabaseMissing('webhook_events', [
            'event_id' => 'rejected-nium-event',
        ]);
    }

    public function test_card_wallet_funding_webhook_uses_template_event_type_and_updates_balance(): void
    {
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'nium-webhook-test-key');

        $provider = $this->provider();
        $user = User::factory()->create();
        $account = $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => 'customer-funding-001',
            'external_account_id' => 'wallet-funding-001',
            'status' => 'active',
        ]);
        Balance::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'external_account_id' => $account->external_account_id,
            'currency' => 'USD',
            'available_balance' => '100.00',
            'ledger_balance' => '100.00',
            'reserved_balance' => '25.00',
            'as_of' => now()->subHour(),
        ]);
        $payload = [
            'name' => 'Nguyen Anh',
            'template' => 'CARD_WALLET_FUNDING_WEBHOOK',
            'walletHashId' => $account->external_account_id,
            'customerHashId' => $account->external_customer_id,
            'transactionAmount' => '2000.00',
            'transactionCurrency' => 'USD',
            'walletBalance' => '259474.0',
        ];

        $this->withHeader('x-partner-key', 'nium-webhook-test-key')
            ->postJson('/api/webhooks/providers/nium', $payload)
            ->assertOk();

        $event = WebhookEvent::query()->sole();
        $this->assertSame('CARD_WALLET_FUNDING_WEBHOOK', $event->event_type);
        $this->assertSame('processed', $event->processing_status);
        $this->assertDatabaseHas('balances', [
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'external_account_id' => 'wallet-funding-001',
            'currency' => 'USD',
            'available_balance' => '259474.00000000',
            'ledger_balance' => '259474.00000000',
            'reserved_balance' => '25.00000000',
        ]);
    }

    private function provider(): IntegrationProvider
    {
        return IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);
    }

    private function transfer(IntegrationProvider $provider, string $externalReference): Transfer
    {
        $user = User::factory()->create();

        return Transfer::query()->create([
            'transfer_no' => 'TRF-NIUM-WEBHOOK-1',
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'external_transfer_id' => $externalReference,
            'transfer_type' => 'local',
            'source_currency' => 'USD',
            'target_currency' => 'EUR',
            'source_amount' => 100,
            'target_amount' => 90,
            'status' => 'pending',
        ]);
    }
}
