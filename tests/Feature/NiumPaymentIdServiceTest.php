<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\NiumVirtualAccount;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Nium\NiumPaymentIdService;
use App\Services\Nium\NiumWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class NiumPaymentIdServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_assign_payment_id_uses_exact_v1_contract_and_persists_safe_virtual_account_reference(): void
    {
        [$provider, $user, $account] = $this->eligibleAccount();
        Http::fake(['*' => Http::response([
            'uniquePaymentId' => 'VA-123456',
            'currencyCode' => 'SGD',
            'accountCategory' => 'SELF_FUNDING_ACCOUNT',
            'bankAddress' => 'must-not-be-stored',
        ])]);

        $virtualAccount = app(NiumPaymentIdService::class)->assign(
            $account,
            'USD',
            'SELF_FUNDING_ACCOUNT',
            'JPM_SG',
        );

        $this->assertSame('VA-123456', $virtualAccount->provider_payment_id);
        $this->assertSame('assigned', $virtualAccount->status);
        $this->assertStringNotContainsString('must-not-be-stored', json_encode($virtualAccount->toArray()));
        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://gateway.sandbox.nium.test/api/v1/client/client-test/customer/customer-test/wallet/wallet-test/paymentId'
                && $request->data() === [
                    'bankName' => 'JPM_SG',
                    'currencyCode' => 'USD',
                    'accountCategory' => 'SELF_FUNDING_ACCOUNT',
                ];
        });
        $this->assertNull($virtualAccount->account_type);
        $this->assertSame('assign_payment_id', ApiRequestLog::query()->latest('id')->firstOrFail()->operation);
    }

    public function test_v1_required_fields_and_categories_fail_before_http(): void
    {
        [, , $account] = $this->eligibleAccount();
        Http::fake();

        foreach ([
            ['US', 'SELF_FUNDING_ACCOUNT', 'JPM_SG'],
            ['USD', 'SELF_FUNDING_AND_COLLECTION_ACCOUNT', 'JPM_SG'],
            ['USD', 'SELF_FUNDING_ACCOUNT', ''],
        ] as [$currencyCode, $accountCategory, $bankName]) {
            try {
                app(NiumPaymentIdService::class)->assign($account, $currencyCode, $accountCategory, $bankName);
                $this->fail('Expected invalid V1 contract to fail before HTTP.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'Invalid Nium Assign Payment ID V1 currency code, account category, or bank name.',
                    $exception->getMessage(),
                );
            }
        }

        Http::assertNothingSent();
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

    public function test_supplied_account_must_match_latest_authoritative_eligible_account_before_http(): void
    {
        [$provider, $user, $supplied] = $this->eligibleAccount();
        $otherProvider = IntegrationProvider::query()->create(['code' => 'other', 'name' => 'Other', 'status' => 'active']);
        $supplied->forceFill(['provider_id' => $otherProvider->id])->save();
        $eligible = $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => 'customer-latest',
            'external_account_id' => 'wallet-latest',
            'status' => 'active',
            'provider_status' => 'clear',
            'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(),
        ]);
        Http::fake();

        try {
            app(NiumPaymentIdService::class)->assign($supplied, 'USD', 'COLLECTION_ACCOUNT', 'JPM_SG');
            $this->fail('Expected exact account binding to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Supplied Nium provider account is not the exact authoritative eligible account.',
                $exception->getMessage(),
            );
        }

        $this->assertSame($eligible->id, $user->providerAccounts()->where('provider_id', $provider->id)->firstOrFail()->id);
        Http::assertNothingSent();
        $this->assertSame(0, NiumVirtualAccount::query()->count());
    }

    private function eligibleAccount(): array
    {
        config()->set('services.nium.base_url', 'https://gateway.sandbox.nium.test');
        config()->set('services.nium.client_id', 'client-test');
        config()->set('services.nium.assign_payment_id_endpoint', '/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/paymentId');
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
