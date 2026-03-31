<?php

namespace Tests\Feature;

use App\Models\Balance;
use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\User;
use App\Services\PingPong\PingPongTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PingPongTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_transfer_creates_pingpong_payment_and_updates_transfer(): void
    {
        Cache::flush();

        $provider = IntegrationProvider::query()->create([
            'code' => 'pingpong',
            'name' => 'PingPong',
            'status' => 'active',
        ]);

        $user = User::factory()->create();
        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'status' => 'active',
            'metadata' => [
                'client_id' => 'managed-client-123',
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
            'external_beneficiary_id' => 'R202501080950209789',
            'beneficiary_type' => 'personal',
            'full_name' => 'Jane Doe',
            'country_code' => 'US',
            'currency' => 'USD',
            'status' => 'active',
        ]);

        $transfer = Transfer::query()->create([
            'transfer_no' => 'TRF-ABC123456789',
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'beneficiary_id' => $beneficiary->id,
            'transfer_type' => 'bank',
            'source_currency' => 'USD',
            'target_currency' => 'USD',
            'source_amount' => 125.50,
            'target_amount' => 125.50,
            'purpose_code' => '1001',
            'reference_text' => 'Invoice 42',
            'status' => 'draft',
        ]);

        config()->set('services.pingpong.base_url', 'https://test-gateway.pingpongx.com');
        config()->set('services.pingpong.auth', [
            'mode' => 'pingpong_access_token',
            'token_endpoint' => '/v2/token/get',
            'app_id' => 'pingpong-app-id',
            'app_secret' => 'pingpong-app-secret',
            'cache_key' => 'tests:pingpong:transfer:token',
            'cache_buffer_seconds' => 300,
        ]);

        Http::fake([
            'https://test-gateway.pingpongx.com/v2/token/get*' => Http::response([
                'access_token' => 'pingpong-access-token',
                'expires_in' => 7200,
            ], 200),
            'https://test-gateway.pingpongx.com/api/payout/v2/create' => Http::response([
                'code' => 'SUCCESS',
                'data' => [
                    'order_id' => '202501092053115499258',
                    'partner_order_id' => 'TRF-ABC123456789',
                    'payout_status' => 'PENDING',
                ],
            ], 200),
        ]);

        $updated = app(PingPongTransferService::class)->submitTransfer(
            $provider,
            $transfer->fresh(['provider', 'user', 'beneficiary'])
        );

        $this->assertSame('202501092053115499258', $updated->external_transfer_id);
        $this->assertSame('202501092053115499258', $updated->external_payment_id);
        $this->assertSame('pending', $updated->status);
        $this->assertNotNull($updated->submitted_at);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://test-gateway.pingpongx.com/api/payout/v2/create'
                && $request->hasHeader('Authorization', 'pingpong-access-token')
                && $request->hasHeader('on-behalf-of', 'managed-client-123')
                && $data['partner_order_id'] === 'TRF-ABC123456789'
                && $data['to_account_id'] === 'R202501080950209789'
                && $data['origin_amount'] === 125.5;
        });
    }

    public function test_sync_transfer_status_queries_pingpong_and_updates_transfer(): void
    {
        Cache::flush();

        $provider = IntegrationProvider::query()->create([
            'code' => 'pingpong',
            'name' => 'PingPong',
            'status' => 'active',
        ]);

        $user = User::factory()->create();
        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'status' => 'active',
            'metadata' => [
                'client_id' => 'managed-client-123',
            ],
        ]);

        $beneficiary = Beneficiary::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'external_beneficiary_id' => 'R202501080950209789',
            'beneficiary_type' => 'personal',
            'full_name' => 'Jane Doe',
            'country_code' => 'US',
            'currency' => 'USD',
            'status' => 'active',
        ]);

        $transfer = Transfer::query()->create([
            'transfer_no' => 'TRF-ABC123456789',
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'beneficiary_id' => $beneficiary->id,
            'external_payment_id' => '202501092053115499258',
            'transfer_type' => 'bank',
            'source_currency' => 'USD',
            'target_currency' => 'USD',
            'source_amount' => 125.50,
            'target_amount' => 125.50,
            'purpose_code' => '1001',
            'reference_text' => 'Invoice 42',
            'status' => 'pending',
        ]);

        config()->set('services.pingpong.base_url', 'https://test-gateway.pingpongx.com');
        config()->set('services.pingpong.auth', [
            'mode' => 'pingpong_access_token',
            'token_endpoint' => '/v2/token/get',
            'app_id' => 'pingpong-app-id',
            'app_secret' => 'pingpong-app-secret',
            'cache_key' => 'tests:pingpong:transfer-sync:token',
            'cache_buffer_seconds' => 300,
        ]);

        Http::fake([
            'https://test-gateway.pingpongx.com/v2/token/get*' => Http::response([
                'access_token' => 'pingpong-access-token',
                'expires_in' => 7200,
            ], 200),
            'https://test-gateway.pingpongx.com/api/payout/v2/query*' => Http::response([
                'code' => 'SUCCESS',
                'data' => [
                    'order_id' => '202501092053115499258',
                    'partner_order_id' => 'TRF-ABC123456789',
                    'status' => 'SUCCESS',
                    'finished' => 1736427191000,
                ],
            ], 200),
        ]);

        $updated = app(PingPongTransferService::class)->syncTransferStatus(
            $provider,
            $transfer->fresh(['provider', 'user', 'beneficiary'])
        );

        $this->assertSame('completed', $updated->status);
        $this->assertNotNull($updated->completed_at);

        Http::assertSent(function ($request): bool {
            return str_starts_with($request->url(), 'https://test-gateway.pingpongx.com/api/payout/v2/query')
                && $request->hasHeader('Authorization', 'pingpong-access-token')
                && $request->hasHeader('on-behalf-of', 'managed-client-123');
        });
    }
}
