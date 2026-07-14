<?php

namespace Tests\Feature;

use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\User;
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
