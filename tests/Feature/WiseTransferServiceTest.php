<?php

namespace Tests\Feature;

use App\Models\Balance;
use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Wise\WiseTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WiseTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_transfer_creates_and_funds_wise_transfer(): void
    {
        $provider = IntegrationProvider::query()->create([
            'code' => 'wise',
            'name' => 'Wise',
            'status' => 'active',
        ]);

        $user = User::factory()->create();
        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => '123456',
            'status' => 'active',
            'metadata' => [
                'profile_id' => 123456,
                'access_token' => 'wise-user-token',
            ],
        ]);

        Balance::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'currency' => 'USD',
            'available_balance' => 1000,
            'ledger_balance' => 1000,
            'as_of' => now(),
        ]);

        $beneficiary = Beneficiary::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'external_beneficiary_id' => '40000000',
            'beneficiary_type' => 'personal',
            'full_name' => 'Jane Doe',
            'country_code' => 'DE',
            'currency' => 'EUR',
            'status' => 'active',
        ]);

        $transfer = Transfer::query()->create([
            'transfer_no' => 'TRF-WISE123456',
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'beneficiary_id' => $beneficiary->id,
            'transfer_type' => 'bank',
            'source_currency' => 'USD',
            'target_currency' => 'EUR',
            'source_amount' => 100,
            'reference_text' => 'Invoice 42',
            'client_reference' => 'client-ref-001',
            'status' => 'draft',
            'raw_data' => [
                'quote_ref' => '11144c35-9fe8-4c32-b7fd-d05c2a7734bf',
                'wise' => [
                    'funding_type' => 'BALANCE',
                ],
            ],
        ]);

        config()->set('services.wise.base_url', 'https://api.wise-sandbox.com');
        config()->set('services.wise.auth', [
            'mode' => 'client_credentials',
            'token_endpoint' => '/oauth/token',
            'client_id' => 'wise-client-id',
            'client_secret' => 'wise-client-secret',
            'credentials_in' => 'basic',
        ]);

        Http::fake([
            'https://api.wise-sandbox.com/v1/transfers' => Http::response([
                'id' => 700001,
                'status' => 'processing',
                'targetValue' => 92.34,
            ], 200),
            'https://api.wise-sandbox.com/v3/profiles/123456/transfers/700001/payments' => Http::response([
                'type' => 'BALANCE',
                'status' => 'COMPLETED',
                'errorCode' => null,
            ], 200),
        ]);

        $updated = app(WiseTransferService::class)->submitTransfer(
            $provider,
            $transfer->fresh(['provider', 'user', 'beneficiary'])
        );

        $this->assertSame('700001', $updated->external_transfer_id);
        $this->assertSame('pending', $updated->status);
        $this->assertNotNull($updated->submitted_at);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://api.wise-sandbox.com/v1/transfers') {
                return false;
            }

            $data = $request->data();

            return $request->hasHeader('Authorization', 'Bearer wise-user-token')
                && $data['targetAccount'] === 40000000
                && $data['quoteUuid'] === '11144c35-9fe8-4c32-b7fd-d05c2a7734bf'
                && $data['customerTransactionId'] === 'client-ref-001';
        });

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://api.wise-sandbox.com/v3/profiles/123456/transfers/700001/payments') {
                return false;
            }

            $data = $request->data();

            return $request->hasHeader('Authorization', 'Bearer wise-user-token')
                && $data['type'] === 'BALANCE';
        });
    }
}
