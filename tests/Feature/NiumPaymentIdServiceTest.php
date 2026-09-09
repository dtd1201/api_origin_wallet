<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\IntegrationProvider;
use App\Models\NiumVirtualAccount;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Nium\NiumPaymentIdService;
use App\Services\Nium\NiumWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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

    public function test_user_can_assign_virtual_account_through_provider_account_endpoint(): void
    {
        [$provider, $user, $account] = $this->eligibleAccount();
        $user->profile()->create([
            'user_type' => 'business',
            'country_code' => 'HK',
        ]);
        Http::fake(['*' => Http::response([
            'uniquePaymentId' => 'VA-API-123456',
            'currencyCode' => 'USD',
            'accountCategory' => 'SELF_FUNDING_ACCOUNT',
            'accountType' => 'LOCAL',
        ])]);

        $response = $this->withToken($this->issueTokenFor($user))
            ->postJson("/api/user/users/{$user->id}/provider-accounts/{$provider->code}/virtual-account", [
                'currency' => 'USD',
                'account_category' => 'SELF_FUNDING_ACCOUNT',
                'account_type' => 'LOCAL',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('virtual_account.user_provider_account_id', $account->id)
            ->assertJsonPath('virtual_account.provider_payment_id', 'VA-API-123456')
            ->assertJsonPath('virtual_account.currency', 'USD')
            ->assertJsonPath('virtual_account.status', 'assigned');

        $this->assertDatabaseHas('nium_virtual_accounts', [
            'user_provider_account_id' => $account->id,
            'provider_payment_id' => 'VA-API-123456',
            'currency' => 'USD',
            'status' => 'assigned',
        ]);
        $this->assertDatabaseHas('api_request_logs', [
            'operation' => 'assign_payment_id',
        ]);
        Http::assertSent(fn ($request): bool => $request->data() === [
            'currency' => 'USD',
            'accountCategory' => 'SELF_FUNDING_ACCOUNT',
            'accountType' => 'LOCAL',
            'bankName' => 'DBS_HK',
        ]);
    }

    public function test_virtual_account_endpoint_validates_nium_assignment_fields(): void
    {
        [$provider, $user] = $this->eligibleAccount();

        $this->withToken($this->issueTokenFor($user))
            ->postJson("/api/user/users/{$user->id}/provider-accounts/{$provider->code}/virtual-account", [
                'currency' => 'US',
                'account_category' => 'INVALID',
                'account_type' => 'INVALID',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'currency',
                'account_category',
                'account_type',
            ]);
    }

    public function test_virtual_accounts_endpoint_returns_only_matching_provider_accounts_records(): void
    {
        [$provider, $user, $account] = $this->eligibleAccount();
        $user->profile()->create([
            'user_type' => 'business',
            'country_code' => 'HK',
        ]);
        $assigned = NiumVirtualAccount::query()->create([
            'user_provider_account_id' => $account->id,
            'provider_payment_id' => 'VA-ASSIGNED-001',
            'currency' => 'USD',
            'account_type' => 'LOCAL',
            'status' => 'assigned',
        ]);
        $pending = NiumVirtualAccount::query()->create([
            'user_provider_account_id' => $account->id,
            'currency' => 'EUR',
            'account_type' => 'LOCAL',
            'status' => 'pending',
        ]);
        $otherUser = User::factory()->create();
        $otherAccount = $otherUser->providerAccounts()->create([
            'provider_id' => $provider->id,
            'status' => 'active',
        ]);
        NiumVirtualAccount::query()->create([
            'user_provider_account_id' => $otherAccount->id,
            'provider_payment_id' => 'VA-OTHER-USER',
            'currency' => 'USD',
            'account_type' => 'LOCAL',
            'status' => 'assigned',
        ]);
        $otherProvider = IntegrationProvider::query()->create([
            'code' => 'other-va-provider',
            'name' => 'Other VA Provider',
            'status' => 'active',
        ]);
        $otherProviderAccount = $user->providerAccounts()->create([
            'provider_id' => $otherProvider->id,
            'status' => 'active',
        ]);
        NiumVirtualAccount::query()->create([
            'user_provider_account_id' => $otherProviderAccount->id,
            'provider_payment_id' => 'VA-OTHER-PROVIDER',
            'currency' => 'USD',
            'account_type' => 'LOCAL',
            'status' => 'assigned',
        ]);

        $response = $this->withToken($this->issueTokenFor($user))
            ->getJson("/api/user/users/{$user->id}/provider-accounts/{$provider->code}/virtual-accounts");

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $pending->id)
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.1.id', $assigned->id)
            ->assertJsonPath('data.1.status', 'assigned');
        $this->assertNotContains('VA-OTHER-USER', $response->json('data.*.provider_payment_id'));
        $this->assertNotContains('VA-OTHER-PROVIDER', $response->json('data.*.provider_payment_id'));
    }

    public function test_virtual_accounts_endpoint_rejects_non_primary_provider(): void
    {
        [, $user] = $this->eligibleAccount();
        $user->profile()->create([
            'user_type' => 'business',
            'country_code' => 'HK',
        ]);
        $provider = IntegrationProvider::query()->create([
            'code' => 'other-provider',
            'name' => 'Other Provider',
            'status' => 'active',
        ]);

        $this->withToken($this->issueTokenFor($user))
            ->getJson("/api/user/users/{$user->id}/provider-accounts/{$provider->code}/virtual-accounts")
            ->assertNotFound();
    }

    public function test_initialized_response_creates_pending_virtual_account_without_persisting_the_sentinel(): void
    {
        [, , $account] = $this->eligibleAccount();
        Http::fake(['*' => Http::response([
            'uniquePaymentId' => 'INITIALIZED',
            'currencyCode' => 'USD',
            'accountCategory' => 'COLLECTION_ACCOUNT',
            'accountType' => 'LOCAL',
        ])]);

        $virtualAccount = app(NiumPaymentIdService::class)->assign(
            $account,
            'USD',
            'COLLECTION_ACCOUNT',
            'LOCAL',
        );

        $this->assertNull($virtualAccount->provider_payment_id);
        $this->assertNull($virtualAccount->virtual_account_reference);
        $this->assertSame('pending', $virtualAccount->status);
        $this->assertNull($virtualAccount->assigned_at);
        $this->assertDatabaseMissing('nium_virtual_accounts', ['provider_payment_id' => 'INITIALIZED']);

        app(NiumPaymentIdService::class)->assign(
            $account,
            'USD',
            'COLLECTION_ACCOUNT',
            'LOCAL',
        );

        $this->assertSame(2, NiumVirtualAccount::query()->where('status', 'pending')->count());
    }

    public function test_va_assigned_webhook_is_idempotent_and_maps_customer_wallet_payment_id(): void
    {
        [$provider, $user, $account] = $this->eligibleAccount();
        $pending = NiumVirtualAccount::query()->create([
            'user_provider_account_id' => $account->id,
            'currency' => 'USD',
            'account_category' => 'COLLECTION_ACCOUNT',
            'account_type' => 'LOCAL',
            'status' => 'pending',
        ]);
        $payload = [
            'eventId' => 'va-assigned-001',
            'template' => 'VA_ASSIGNED',
            'customerHashId' => 'customer-test',
            'walletHashId' => 'wallet-test',
            'uniquePaymentId' => 'VA-654321',
            'currencyCode' => 'USD',
            'accountCategory' => 'COLLECTION_ACCOUNT',
            'accountType' => 'LOCAL',
            'assignedAt' => '2026-09-09T08:30:00Z',
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
        $assigned = $pending->fresh();
        $this->assertSame($account->id, $assigned->user_provider_account_id);
        $this->assertSame('VA-654321', $assigned->virtual_account_reference);
        $this->assertSame('assigned', $assigned->status);
        $this->assertSame('2026-09-09 08:30:00', $assigned->assigned_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_real_nium_virtual_account_assigned_webhook_updates_existing_pending_record(): void
    {
        [$provider, , $account] = $this->eligibleAccount();
        $pending = NiumVirtualAccount::query()->create([
            'user_provider_account_id' => $account->id,
            'currency' => 'USD',
            'account_category' => 'COLLECTION_ACCOUNT',
            'account_type' => 'LOCAL',
            'status' => 'pending',
        ]);
        $payload = [
            'eventId' => 'virtual-account-assigned-webhook-001',
            'template' => 'VIRTUAL_ACCOUNT_ASSIGNED_WEBHOOK',
            'customerHashId' => 'customer-test',
            'walletHashId' => 'wallet-test',
            'uniquePaymentId' => 'VA-REAL-NIUM-001',
            'currencyCode' => 'USD',
            'accountType' => 'LOCAL',
        ];
        $request = Request::create('/api/webhooks/providers/nium', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PARTNER_KEY' => 'test-partner-key',
        ], content: json_encode($payload, JSON_THROW_ON_ERROR));

        $result = app(NiumWebhookService::class)->handleWebhook($provider, $request);

        $this->assertFalse($result['duplicate'] ?? false);
        $this->assertSame($pending->id, NiumVirtualAccount::query()->sole()->id);
        $this->assertSame('VA-REAL-NIUM-001', $pending->fresh()->provider_payment_id);
        $this->assertSame('assigned', $pending->fresh()->status);
        $this->assertSame(1, NiumVirtualAccount::query()->count());
    }

    public function test_va_assigned_webhook_updates_only_the_pending_record_with_matching_dimensions(): void
    {
        [$provider, , $account] = $this->eligibleAccount();
        $usd = NiumVirtualAccount::query()->create([
            'user_provider_account_id' => $account->id,
            'currency' => 'USD',
            'account_category' => 'COLLECTION_ACCOUNT',
            'account_type' => 'LOCAL',
            'status' => 'pending',
        ]);
        $sgd = NiumVirtualAccount::query()->create([
            'user_provider_account_id' => $account->id,
            'currency' => 'SGD',
            'account_category' => 'COLLECTION_ACCOUNT',
            'account_type' => 'LOCAL',
            'status' => 'pending',
        ]);
        $payload = [
            'eventId' => 'va-assigned-multiple-001',
            'template' => 'VA_ASSIGNED',
            'customerHashId' => 'customer-test',
            'walletHashId' => 'wallet-test',
            'uniquePaymentId' => 'VA-USD-001',
            'currencyCode' => 'USD',
            'accountCategory' => 'COLLECTION_ACCOUNT',
            'accountType' => 'LOCAL',
        ];
        $request = Request::create('/api/webhooks/providers/nium', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PARTNER_KEY' => 'test-partner-key',
        ], content: json_encode($payload, JSON_THROW_ON_ERROR));

        app(NiumWebhookService::class)->handleWebhook($provider, $request);

        $this->assertSame('assigned', $usd->fresh()->status);
        $this->assertSame('VA-USD-001', $usd->fresh()->provider_payment_id);
        $this->assertSame('pending', $sgd->fresh()->status);
        $this->assertNull($sgd->fresh()->provider_payment_id);
    }

    public function test_va_assigned_webhooks_assign_identical_pending_records_oldest_first(): void
    {
        [$provider, , $account] = $this->eligibleAccount();
        $oldest = NiumVirtualAccount::query()->create([
            'user_provider_account_id' => $account->id,
            'currency' => 'USD',
            'account_category' => 'COLLECTION_ACCOUNT',
            'account_type' => 'LOCAL',
            'status' => 'pending',
        ]);
        $newest = NiumVirtualAccount::query()->create([
            'user_provider_account_id' => $account->id,
            'currency' => 'USD',
            'account_category' => 'COLLECTION_ACCOUNT',
            'account_type' => 'LOCAL',
            'status' => 'pending',
        ]);

        $payload = [
            'eventId' => 'va-assigned-identical-001',
            'template' => 'VA_ASSIGNED',
            'customerHashId' => 'customer-test',
            'walletHashId' => 'wallet-test',
            'uniquePaymentId' => 'VA-IDENTICAL-001',
            'currencyCode' => 'USD',
            'accountType' => 'LOCAL',
        ];
        $firstRequest = Request::create('/api/webhooks/providers/nium', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PARTNER_KEY' => 'test-partner-key',
        ], content: json_encode($payload, JSON_THROW_ON_ERROR));

        app(NiumWebhookService::class)->handleWebhook($provider, $firstRequest);

        $this->assertSame('assigned', $oldest->fresh()->status);
        $this->assertSame('VA-IDENTICAL-001', $oldest->fresh()->provider_payment_id);
        $this->assertSame('pending', $newest->fresh()->status);
        $this->assertNull($newest->fresh()->provider_payment_id);

        $payload['eventId'] = 'va-assigned-identical-002';
        $payload['uniquePaymentId'] = 'VA-IDENTICAL-002';
        $secondRequest = Request::create('/api/webhooks/providers/nium', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PARTNER_KEY' => 'test-partner-key',
        ], content: json_encode($payload, JSON_THROW_ON_ERROR));

        app(NiumWebhookService::class)->handleWebhook($provider, $secondRequest);

        $this->assertSame('assigned', $newest->fresh()->status);
        $this->assertSame('VA-IDENTICAL-002', $newest->fresh()->provider_payment_id);
        $this->assertSame(2, NiumVirtualAccount::query()->where('status', 'assigned')->count());
    }

    public function test_va_assigned_webhook_falls_back_to_creating_an_assigned_record(): void
    {
        [$provider, , $account] = $this->eligibleAccount();
        $payload = [
            'eventId' => 'va-assigned-fallback-001',
            'template' => 'VA_ASSIGNED',
            'customerHashId' => 'customer-test',
            'walletHashId' => 'wallet-test',
            'uniquePaymentId' => 'VA-FALLBACK-001',
            'currencyCode' => 'USD',
            'accountCategory' => 'COLLECTION_ACCOUNT',
            'accountType' => 'LOCAL',
        ];
        $request = Request::create('/api/webhooks/providers/nium', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PARTNER_KEY' => 'test-partner-key',
        ], content: json_encode($payload, JSON_THROW_ON_ERROR));

        app(NiumWebhookService::class)->handleWebhook($provider, $request);

        $this->assertDatabaseHas('nium_virtual_accounts', [
            'user_provider_account_id' => $account->id,
            'provider_payment_id' => 'VA-FALLBACK-001',
            'virtual_account_reference' => 'VA-FALLBACK-001',
            'status' => 'assigned',
        ]);
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
