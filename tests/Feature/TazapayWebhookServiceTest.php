<?php

namespace Tests\Feature;

use App\Models\IntegrationProvider;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Tazapay\TazapayWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class TazapayWebhookServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_webhook_updates_tazapay_transfer_status(): void
    {
        $provider = IntegrationProvider::query()->create([
            'code' => 'tazapay',
            'name' => 'Tazapay',
            'status' => 'active',
        ]);

        $user = User::factory()->create();

        $transfer = Transfer::query()->create([
            'transfer_no' => 'TRF-TAZA12345678',
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'external_transfer_id' => 'pot_test_123',
            'transfer_type' => 'local',
            'source_currency' => 'USD',
            'target_currency' => 'HKD',
            'source_amount' => 100,
            'target_amount' => 780,
            'status' => 'pending',
        ]);

        $transaction = Transaction::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'transfer_id' => $transfer->id,
            'external_transaction_id' => 'btr_test_123',
            'currency' => 'USD',
            'amount' => 100,
            'status' => 'pending',
        ]);

        $request = Request::create('/api/webhooks/providers/tazapay', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'type' => 'payout.succeeded',
            'id' => 'evt_test_123',
            'object' => 'event',
            'data' => [
                'id' => 'pot_test_123',
                'status' => 'succeeded',
                'tracking_details' => [
                    'tracking_number' => 'TZP-TRACK-001',
                ],
                'balance_transaction' => 'btr_test_123',
                'reference_id' => 'INV-42',
                'transaction_description' => 'Invoice 42 settlement',
            ],
        ]));

        $result = app(TazapayWebhookService::class)->handleWebhook($provider, $request);

        $this->assertSame('Webhook received.', $result['message']);
        $this->assertDatabaseHas('webhook_events', [
            'provider_id' => $provider->id,
            'event_id' => 'evt_test_123',
            'event_type' => 'payout.succeeded',
        ]);

        $this->assertSame('completed', $transfer->fresh()->status);
        $this->assertSame('TZP-TRACK-001', $transfer->fresh()->external_payment_id);
        $this->assertSame('completed', $transaction->fresh()->status);
        $this->assertSame('Invoice 42 settlement', $transaction->fresh()->description);
    }

    public function test_handle_webhook_returns_duplicate_response_for_same_event(): void
    {
        $provider = IntegrationProvider::query()->create([
            'code' => 'tazapay',
            'name' => 'Tazapay',
            'status' => 'active',
        ]);

        $request = Request::create('/api/webhooks/providers/tazapay', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'type' => 'payout.reversed',
            'id' => 'evt_duplicate_123',
            'object' => 'event',
            'data' => [
                'id' => 'pot_test_123',
                'status' => 'reversed',
            ],
        ]));

        $service = app(TazapayWebhookService::class);

        $service->handleWebhook($provider, $request);
        $result = $service->handleWebhook($provider, $request);

        $this->assertTrue($result['duplicate']);
        $this->assertSame('evt_duplicate_123', $result['event_id']);
    }
}
