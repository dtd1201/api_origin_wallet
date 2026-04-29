<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnlimitWebhookServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_unlimit_payout_callback_updates_transfer_and_deduplicates_event(): void
    {
        $provider = IntegrationProvider::query()->create([
            'code' => 'unlimit',
            'name' => 'Unlimit',
            'status' => 'active',
        ]);

        $user = User::factory()->create();
        $beneficiary = Beneficiary::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'beneficiary_type' => 'personal',
            'full_name' => 'Jane Doe',
            'country_code' => 'ID',
            'currency' => 'IDR',
            'account_number' => '1234567890',
            'status' => 'active',
        ]);

        $transfer = Transfer::query()->create([
            'transfer_no' => 'TRF-UNLIMIT1234',
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'beneficiary_id' => $beneficiary->id,
            'external_transfer_id' => '4237264',
            'transfer_type' => 'local',
            'source_currency' => 'IDR',
            'target_currency' => 'IDR',
            'source_amount' => 125000,
            'target_amount' => 125000,
            'status' => 'pending',
        ]);

        config()->set('services.unlimit.webhook_secret', 'callback-secret');
        config()->set('services.unlimit.webhook_signature_header', 'Signature');

        $payload = [
            'callback_time' => '2026-04-28T09:10:51Z',
            'merchant_order' => [
                'id' => $transfer->transfer_no,
            ],
            'payment_data' => [
                'id' => '362727264',
            ],
            'payment_method' => 'BANKTRANSFERSIDR',
            'payout_data' => [
                'id' => '4237264',
                'amount' => 125000,
                'currency' => 'IDR',
                'status' => 'COMPLETED',
                'created' => '2026-04-28T09:10:51Z',
            ],
        ];
        $body = json_encode($payload);
        $signature = hash('sha512', $body.'callback-secret');

        $this->call(
            'POST',
            '/api/webhooks/providers/unlimit',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_SIGNATURE' => $signature,
            ],
            $body,
        )->assertOk()
            ->assertJsonPath('event_id', '4237264:COMPLETED:2026-04-28T09:10:51Z');

        $updated = $transfer->fresh();

        $this->assertSame('completed', $updated->status);
        $this->assertSame('362727264', $updated->external_payment_id);
        $this->assertNotNull($updated->completed_at);
        $this->assertDatabaseHas('webhook_events', [
            'provider_id' => $provider->id,
            'event_id' => '4237264:COMPLETED:2026-04-28T09:10:51Z',
            'processing_status' => 'processed',
        ]);

        $this->call(
            'POST',
            '/api/webhooks/providers/unlimit',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_SIGNATURE' => $signature,
            ],
            $body,
        )->assertOk()
            ->assertJsonPath('duplicate', true);
    }
}
