<?php

namespace Tests\Feature;

use App\Models\IntegrationProvider;
use App\Models\NiumVirtualAccount;
use App\Models\Transaction;
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

    public function test_official_va_templates_assign_or_hold_without_fabrication(): void
    {
        $this->configureWebhook();
        $provider = $this->provider();
        $user = User::factory()->create();
        $account = $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => 'customer-7',
            'external_account_id' => 'wallet-7',
            'status' => 'active',
        ]);

        $this->postOfficial('va-assigned', [
            'template' => 'VIRTUAL_ACCOUNT_ASSIGNED_WEBHOOK',
            'customerHashId' => 'customer-7',
            'walletHashId' => 'wallet-7',
            'currencyCode' => 'USD',
            'uniquePaymentId' => 'payment-7',
            'accountName' => 'Sensitive Account Name',
        ])->assertOk();

        $this->assertDatabaseHas('nium_virtual_accounts', [
            'user_provider_account_id' => $account->id,
            'provider_payment_id' => 'payment-7',
            'currency' => 'USD',
        ]);

        $this->postOfficial('va-failed', [
            'template' => 'VIRTUAL_ACCOUNT_ASSIGNMENT_FAILED_WEBHOOK',
            'customerHashId' => 'customer-7',
            'walletHashId' => 'wallet-7',
            'currencyCode' => 'USD',
            'bankName' => 'Test Bank',
            'customMessage' => 'Manual review required',
        ])->assertOk();

        $this->assertSame(1, NiumVirtualAccount::query()->count());
        $this->assertSame('HOLD_VAN_ASSIGNMENT_FAILED', data_get($account->fresh()->metadata, 'nium_van_assignment_failure.state'));
        $this->assertSame('VIRTUAL_ACCOUNT_ASSIGNMENT_FAILED_WEBHOOK', WebhookEvent::query()->where('event_id', 'va-failed')->value('event_type'));
    }

    public function test_official_payout_templates_enforce_terminal_ordering_dedupe_and_pii_safe_evidence(): void
    {
        $this->configureWebhook();
        config()->set('wallet.ledger.enabled', false);
        $provider = $this->provider();
        $transfer = $this->transfer($provider, 'SYS-7');
        $pii = ['Jane Secret', '999988887777', 'Secret Remitter', 'ID-PRIVATE-77'];

        $base = [
            'systemReferenceNumber' => 'SYS-7',
            'externalId' => $transfer->transfer_no,
            'transactionId' => 'TXN-7',
            'beneficiaryName' => $pii[0],
            'beneficiaryAccountNumber' => $pii[1],
            'remitter' => ['name' => $pii[2], 'identificationNumber' => $pii[3]],
        ];

        $this->postOfficial('initiated-7', [...$base, 'template' => 'REMIT_TRANSACTION_INITIATED_WEBHOOK'])->assertOk();
        $this->assertSame('pending', $transfer->fresh()->status);
        $this->postOfficial('paid-7', [...$base, 'template' => 'REMIT_TRANSACTION_PAID_WEBHOOK'])->assertOk();
        $this->assertSame('completed', $transfer->fresh()->status);
        $this->postOfficial('substatus-7', [...$base, 'template' => 'REMIT_TRANSACTION_SUBSTATUS_UPDATE_WEBHOOK', 'subStatus' => 'Future_Provider_State'])->assertOk();
        $this->assertSame('completed', $transfer->fresh()->status);
        $this->assertSame('PAID', data_get($transfer->fresh()->raw_data, 'nium_webhook.provider_outcome'));
        $this->assertSame('Future_Provider_State', data_get($transfer->fresh()->raw_data, 'provider_sub_status'));
        $this->postOfficial('returned-7', [...$base, 'template' => 'REMIT_TRANSACTION_RETURNED_WEBHOOK', 'reason' => 'Beneficiary bank returned funds'])->assertOk();
        $this->assertSame('failed', $transfer->fresh()->status);
        $this->assertSame('RETURNED', data_get($transfer->fresh()->raw_data, 'nium_webhook.provider_outcome'));
        $this->postOfficial('returned-substatus-7', [...$base, 'template' => 'REMIT_TRANSACTION_SUBSTATUS_UPDATE_WEBHOOK', 'subStatus' => 'Processed_By_Clearing'])->assertOk();
        $this->assertSame('RETURNED', data_get($transfer->fresh()->raw_data, 'nium_webhook.provider_outcome'));
        $this->postOfficial('late-rejected-7', [...$base, 'template' => 'REMIT_TRANSACTION_REJECTED_WEBHOOK'])->assertOk();
        $this->assertSame('failed', $transfer->fresh()->status);
        $this->assertSame('RETURNED', data_get($transfer->fresh()->raw_data, 'nium_webhook.provider_outcome'));
        $this->postOfficial('late-paid-7', [...$base, 'template' => 'REMIT_TRANSACTION_PAID_WEBHOOK'])->assertOk();
        $this->assertSame('failed', $transfer->fresh()->status);

        $this->postOfficial('late-paid-7', [...$base, 'template' => 'REMIT_TRANSACTION_PAID_WEBHOOK'])
            ->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(7, WebhookEvent::query()->count());

        $rejected = $this->transfer($provider, 'SYS-8');
        $this->postOfficial('rejected-8', [
            'template' => 'REMIT_TRANSACTION_REJECTED_WEBHOOK',
            'systemReferenceNumber' => 'SYS-8',
            'errorCode' => 'R-42',
            'errorReasonCode' => 'BANK_REJECTED',
            'errorDescription' => 'Document K12345678 could not be processed',
            'reasonDescription' => 'Account 1234567890123456 is invalid; contact +85291234567 for verification',
        ])->assertOk();
        $this->assertSame('failed', $rejected->fresh()->status);
        $this->assertSame('BANK_REJECTED', data_get($rejected->fresh()->raw_data, 'nium_webhook.errorReasonCode'));
        $this->postOfficial('late-paid-8', [
            'template' => 'REMIT_TRANSACTION_PAID_WEBHOOK',
            'systemReferenceNumber' => 'SYS-8',
        ])->assertOk();
        $this->assertSame('failed', $rejected->fresh()->status);
        $this->assertSame('REJECTED', data_get($rejected->fresh()->raw_data, 'nium_webhook.provider_outcome'));
        $this->postOfficial('late-initiated-8', [
            'template' => 'REMIT_TRANSACTION_INITIATED_WEBHOOK',
            'systemReferenceNumber' => 'SYS-8',
        ])->assertOk();
        $this->assertSame('failed', $rejected->fresh()->status);
        $this->assertSame('REJECTED', data_get($rejected->fresh()->raw_data, 'nium_webhook.provider_outcome'));

        $paid = $this->transfer($provider, 'SYS-9');
        $this->postOfficial('paid-9', ['template' => 'REMIT_TRANSACTION_PAID_WEBHOOK', 'systemReferenceNumber' => 'SYS-9'])->assertOk();
        $this->postOfficial('late-rejected-9', ['template' => 'REMIT_TRANSACTION_REJECTED_WEBHOOK', 'systemReferenceNumber' => 'SYS-9'])->assertOk();
        $this->assertSame('completed', $paid->fresh()->status);
        $this->assertSame('PAID', data_get($paid->fresh()->raw_data, 'nium_webhook.provider_outcome'));

        $serialized = json_encode([
            WebhookEvent::query()->pluck('payload')->all(),
            $transfer->fresh()->raw_data,
            $rejected->fresh()->raw_data,
            $paid->fresh()->raw_data,
            Transaction::query()->pluck('raw_data')->all(),
        ]);
        foreach ($pii as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        foreach (['1234567890123456', 'K12345678', '+85291234567'] as $sensitiveLiteral) {
            $this->assertStringNotContainsString($sensitiveLiteral, $serialized);
            $this->assertStringNotContainsString($sensitiveLiteral, (string) $rejected->fresh()->failure_reason);
        }
    }

    private function configureWebhook(): void
    {
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'nium-webhook-test-key');
    }

    private function postOfficial(string $requestId, array $payload)
    {
        return $this->withHeaders([
            'x-partner-key' => 'nium-webhook-test-key',
            'x-request-id' => $requestId,
        ])->postJson('/api/webhooks/providers/nium', $payload);
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
            'transfer_no' => 'TRF-NIUM-WEBHOOK-'.$externalReference,
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
