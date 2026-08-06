<?php

namespace Tests\Feature;

use App\Models\IntegrationProvider;
use App\Models\NiumVirtualAccount;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Nium\NiumPaymentIdService;
use App\Services\Nium\NiumWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NiumPaymentIdServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_assign_payment_id_uses_v2_contract_and_persists_safe_virtual_account_reference(): void
    {
        [$provider, $user, $account] = $this->eligibleAccount();
        Http::fake(['*' => Http::response([
            'uniquePaymentId' => 'VA-123456',
            'currencyCode' => 'SGD',
            'accountCategory' => 'SELF_FUNDING_ACCOUNT',
            'accountType' => 'LOCAL',
            'bankAddress' => 'must-not-be-stored',
        ])]);

        $virtualAccount = app(NiumPaymentIdService::class)->assign(
            $account,
            'SGD',
            'SELF_FUNDING_ACCOUNT',
            'LOCAL',
        );

        $this->assertSame('VA-123456', $virtualAccount->provider_payment_id);
        $this->assertSame('assigned', $virtualAccount->status);
        $this->assertStringNotContainsString('must-not-be-stored', json_encode($virtualAccount->toArray()));
        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://gateway.sandbox.nium.test/api/v2/client/client-test/customer/customer-test/wallet/wallet-test/paymentId'
                && $request->data() === [
                    'currency' => 'SGD',
                    'accountCategory' => 'SELF_FUNDING_ACCOUNT',
                    'accountType' => 'LOCAL',
                ];
        });
    }

    public function test_va_assigned_webhook_is_idempotent_and_maps_customer_wallet_payment_id(): void
    {
        [$provider, $user, $account] = $this->eligibleAccount();
        $payload = [
            'eventId' => 'va-assigned-001',
            'template' => 'VA_ASSIGNED',
            'customerHashId' => 'customer-test',
            'walletHashId' => 'wallet-test',
            'uniquePaymentId' => 'VA-654321',
            'currencyCode' => 'USD',
            'accountCategory' => 'COLLECTION_ACCOUNT',
            'accountType' => 'LOCAL',
        ];
        $request = Request::create('/api/webhooks/providers/nium', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PARTNER_KEY' => 'test-partner-key',
        ], content: json_encode($payload, JSON_THROW_ON_ERROR));

        $first = app(NiumWebhookService::class)->handleWebhook($provider, $request);
        $second = app(NiumWebhookService::class)->handleWebhook($provider, $request);

        $this->assertFalse($first['duplicate'] ?? false);
        $this->assertTrue($second['duplicate']);
        $this->assertSame(1, WebhookEvent::query()->where('event_id', 'va-assigned-001')->count());
        $this->assertSame(1, NiumVirtualAccount::query()->where('provider_payment_id', 'VA-654321')->count());
        $this->assertSame($account->id, NiumVirtualAccount::query()->firstOrFail()->user_provider_account_id);
    }

    private function eligibleAccount(): array
    {
        config()->set('services.nium.base_url', 'https://gateway.sandbox.nium.test');
        config()->set('services.nium.client_id', 'client-test');
        config()->set('services.nium.auth', ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'test-key']);
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'test-partner-key');
        $provider = IntegrationProvider::query()->create(['code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        $user = User::factory()->create(['kyc_status' => 'verified']);
        $account = $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => 'customer-test',
            'external_account_id' => 'wallet-test',
            'status' => 'active',
            'provider_status' => 'clear',
            'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(),
            'provider_ids_verified_at' => now(),
        ]);

        return [$provider, $user, $account];
    }
}
