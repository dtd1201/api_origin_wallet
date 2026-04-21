<?php

namespace Tests\Feature;

use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TazapayWebhookApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_webhook_route_processes_tazapay_event(): void
    {
        $provider = IntegrationProvider::query()->create([
            'code' => 'tazapay',
            'name' => 'Tazapay',
            'status' => 'active',
        ]);

        $user = User::factory()->create();

        $transfer = Transfer::query()->create([
            'transfer_no' => 'TRF-TAZAAPI1234',
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'external_transfer_id' => 'pot_api_test_123',
            'transfer_type' => 'local',
            'source_currency' => 'USD',
            'target_currency' => 'HKD',
            'source_amount' => 100,
            'target_amount' => 780,
            'status' => 'pending',
        ]);

        $response = $this->postJson('/api/webhooks/providers/tazapay', [
            'type' => 'payout.succeeded',
            'id' => 'evt_api_test_123',
            'object' => 'event',
            'data' => [
                'id' => 'pot_api_test_123',
                'status' => 'succeeded',
                'tracking_details' => [
                    'tracking_number' => 'TZP-API-TRACK-001',
                ],
                'reference_id' => 'INV-API-42',
                'transaction_description' => 'Settlement from route test',
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'Webhook received.',
                'provider' => 'tazapay',
                'event_id' => 'evt_api_test_123',
            ]);

        $this->assertDatabaseHas('webhook_events', [
            'provider_id' => $provider->id,
            'event_id' => 'evt_api_test_123',
            'event_type' => 'payout.succeeded',
        ]);

        $transfer->refresh();

        $this->assertSame('completed', $transfer->status);
        $this->assertSame('TZP-API-TRACK-001', $transfer->external_payment_id);
    }
}
