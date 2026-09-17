<?php

namespace Tests\Feature;

use App\Models\Balance;
use App\Models\IntegrationProvider;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NiumWebhookApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_compliance_completed_keeps_transfer_and_transaction_pending(): void
    {
        $this->assertWebhookTransferStatus('COMPLIANCE_COMPLETED', 'pending');
    }

    public function test_pg_processing_keeps_transfer_and_transaction_pending(): void
    {
        $this->assertWebhookTransferStatus('PG_PROCESSING', 'pending');
    }

    public function test_completed_updates_transfer_and_transaction_to_completed(): void
    {
        $this->assertWebhookTransferStatus('COMPLETED', 'completed');
    }

    public function test_failed_updates_transfer_and_transaction_to_failed(): void
    {
        $this->assertWebhookTransferStatus('FAILED', 'failed');
    }

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
            'available_balance' => '263474.00',
            'ledger_balance' => '278474.00',
            'reserved_balance' => '15000.00',
            'as_of' => now()->subHour(),
        ]);
        $payload = [
            'name' => 'Nguyen Anh',
            'template' => 'CARD_WALLET_FUNDING_WEBHOOK',
            'walletHashId' => $account->external_account_id,
            'customerHashId' => $account->external_customer_id,
            'transactionAmount' => '2000.00',
            'transactionCurrency' => 'USD',
            'walletBalance' => '260474.0',
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
            'available_balance' => '260474.00000000',
            'ledger_balance' => '275474.00000000',
            'reserved_balance' => '15000.00000000',
        ]);
    }


    public function test_transfer_webhook_does_not_regress_terminal_state_to_pending(): void
    {
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'nium-webhook-test-key');
        config()->set('wallet.ledger.enabled', false);

        $provider = $this->provider();
        $transfer = $this->transfer($provider, 'NIUM-ORDER-1001');

        $this->withHeader('x-partner-key', 'nium-webhook-test-key')
            ->postJson('/api/webhooks/providers/nium', [
                'eventId' => 'nium-order-completed-1001',
                'eventType' => 'remittance.completed',
                'data' => [
                    'resource' => [
                        'systemReferenceNumber' => 'NIUM-ORDER-1001',
                        'transactionId' => 'NIUM-ORDER-TXN-1001',
                        'status' => 'COMPLETED',
                    ],
                ],
            ])
            ->assertOk();

        $this->assertSame('completed', $transfer->fresh()->status);

        $this->withHeader('x-partner-key', 'nium-webhook-test-key')
            ->postJson('/api/webhooks/providers/nium', [
                'eventId' => 'nium-order-pending-1001',
                'eventType' => 'remittance.updated',
                'data' => [
                    'resource' => [
                        'systemReferenceNumber' => 'NIUM-ORDER-1001',
                        'transactionId' => 'NIUM-ORDER-TXN-1001',
                        'status' => 'PENDING',
                    ],
                ],
            ])
            ->assertOk();

        $this->assertSame(
            'completed',
            $transfer->fresh()->status,
            'A later-arriving non-terminal webhook must not regress a terminal transfer.'
        );
    }


    public function test_transfer_webhook_ignores_older_non_terminal_status(): void
    {
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'nium-webhook-test-key');
        config()->set('wallet.ledger.enabled', false);

        $provider = $this->provider();
        $transfer = $this->transfer($provider, 'NIUM-ORDER-TIME-1001');

        $this->withHeader('x-partner-key', 'nium-webhook-test-key')
            ->postJson('/api/webhooks/providers/nium', [
                'eventId' => 'nium-processing-newer-1001',
                'eventType' => 'remittance.updated',
                'data' => [
                    'resource' => [
                        'systemReferenceNumber' => 'NIUM-ORDER-TIME-1001',
                        'transactionId' => 'NIUM-ORDER-TIME-TXN-1001',
                        'status' => 'PROCESSING',
                        'updatedAt' => '2026-09-16T10:05:00Z',
                    ],
                ],
            ])
            ->assertOk();

        $fresh = $transfer->fresh();

        $this->assertSame('pending', $fresh->status);
        $this->assertSame(
            '2026-09-16 10:05:00',
            $fresh->provider_status_at?->utc()->format('Y-m-d H:i:s')
        );

        $this->withHeader('x-partner-key', 'nium-webhook-test-key')
            ->postJson('/api/webhooks/providers/nium', [
                'eventId' => 'nium-pending-older-1001',
                'eventType' => 'remittance.updated',
                'data' => [
                    'resource' => [
                        'systemReferenceNumber' => 'NIUM-ORDER-TIME-1001',
                        'transactionId' => 'NIUM-ORDER-TIME-TXN-1001',
                        'status' => 'PENDING',
                        'updatedAt' => '2026-09-16T10:01:00Z',
                    ],
                ],
            ])
            ->assertOk();

        $fresh = $transfer->fresh();

        $this->assertSame(
            '2026-09-16 10:05:00',
            $fresh->provider_status_at?->utc()->format('Y-m-d H:i:s'),
            'An older webhook must not replace the latest provider status timestamp.'
        );

        $this->assertSame(
            'PROCESSING',
            data_get($fresh->raw_data, 'last_webhook_payload.data.resource.status'),
            'An older webhook must not overwrite the latest accepted provider payload.'
        );
    }

    public function test_transfer_webhook_rolls_back_status_when_terminal_ledger_application_fails(): void
    {
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'nium-webhook-test-key');
        config()->set('wallet.ledger.enabled', true);

        $provider = $this->provider();
        $transfer = $this->transfer($provider, 'NIUM-ATOMIC-1001');

        // Intentionally do not create a synced Balance.
        // A completed transfer therefore cannot be settled by LedgerService.
        $this->withHeader('x-partner-key', 'nium-webhook-test-key')
            ->postJson('/api/webhooks/providers/nium', [
                'eventId' => 'nium-atomic-completed-1001',
                'eventType' => 'remittance.completed',
                'data' => [
                    'resource' => [
                        'systemReferenceNumber' => 'NIUM-ATOMIC-1001',
                        'transactionId' => 'NIUM-ATOMIC-TXN-1001',
                        'status' => 'COMPLETED',
                    ],
                ],
            ])
            ->assertUnprocessable();

        $this->assertSame(
            'pending',
            $transfer->fresh()->status,
            'Transfer status must roll back when terminal ledger application fails.'
        );

        $this->assertDatabaseHas('webhook_events', [
            'provider_id' => $provider->id,
            'event_id' => 'nium-atomic-completed-1001',
            'processing_status' => 'failed',
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

    private function assertWebhookTransferStatus(string $providerStatus, string $expectedStatus): void
    {
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'nium-webhook-test-key');
        config()->set('wallet.ledger.enabled', false);

        $provider = $this->provider();
        $reference = 'NIUM-STATUS-'.str_replace('_', '-', $providerStatus);
        $transactionId = 'NIUM-TXN-'.str_replace('_', '-', $providerStatus);
        $transfer = $this->transfer($provider, $reference);

        $response = $this->withHeader('x-partner-key', 'nium-webhook-test-key')
            ->postJson('/api/webhooks/providers/nium', [
                'eventId' => 'nium-event-'.strtolower(str_replace('_', '-', $providerStatus)),
                'eventType' => 'remittance.completed',
                'data' => [
                    'resource' => [
                        'systemReferenceNumber' => $reference,
                        'transactionId' => $transactionId,
                        'status' => $providerStatus,
                    ],
                ],
            ]);

        $response->assertOk();

        $freshTransfer = $transfer->fresh();
        $transaction = Transaction::query()
            ->where('external_transaction_id', $transactionId)
            ->sole();

        $this->assertSame($providerStatus, $freshTransfer->provider_status);
        $this->assertSame($expectedStatus, $freshTransfer->status);
        $this->assertSame($freshTransfer->id, $transaction->transfer_id);
        $this->assertSame($expectedStatus, $transaction->status);
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
