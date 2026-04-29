<?php

namespace Tests\Feature;

use App\Models\Balance;
use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Unlimit\UnlimitTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UnlimitTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_transfer_creates_unlimit_payout_and_updates_transfer(): void
    {
        Cache::flush();

        $provider = IntegrationProvider::query()->create([
            'code' => 'unlimit',
            'name' => 'Unlimit',
            'status' => 'active',
        ]);

        $user = User::factory()->create();
        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_account_id' => 'terminal-123',
            'status' => 'active',
        ]);

        Balance::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'currency' => 'IDR',
            'available_balance' => 500000,
            'ledger_balance' => 500000,
            'as_of' => now(),
        ]);

        $beneficiary = Beneficiary::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'beneficiary_type' => 'personal',
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.test',
            'phone' => '+6281234567890',
            'country_code' => 'ID',
            'currency' => 'IDR',
            'bank_name' => 'Bank Central Asia',
            'bank_code' => '014',
            'account_number' => '1234567890',
            'address_line1' => 'Jl Test 1',
            'city' => 'Jakarta',
            'status' => 'active',
        ]);

        $transfer = Transfer::query()->create([
            'transfer_no' => 'TRF-UNLIMIT1234',
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'beneficiary_id' => $beneficiary->id,
            'transfer_type' => 'local',
            'source_currency' => 'IDR',
            'target_currency' => 'IDR',
            'source_amount' => 125000,
            'target_amount' => 125000,
            'purpose_code' => 'services',
            'reference_text' => 'Invoice 42',
            'client_reference' => 'INV-42',
            'status' => 'draft',
            'raw_data' => [
                'unlimit' => [
                    'payment_method' => 'BANKTRANSFERSIDR',
                ],
            ],
        ]);

        config()->set('services.unlimit.base_url', 'https://sandbox.cardpay.com/api');
        config()->set('services.unlimit.auth', [
            'mode' => 'unlimit_access_token',
            'token_endpoint' => '/auth/token',
            'terminal_code' => 'terminal-123',
            'password' => 'terminal-secret',
            'cache_key' => 'tests:unlimit:token',
            'cache_buffer_seconds' => 30,
        ]);

        Http::fake([
            'https://sandbox.cardpay.com/api/auth/token' => Http::response([
                'access_token' => 'unlimit-access-token',
                'expires_in' => 300,
                'refresh_token' => 'refresh-token',
                'token_type' => 'bearer',
            ], 200),
            'https://sandbox.cardpay.com/api/payouts' => Http::response([
                'payment_method' => 'BANKTRANSFERSIDR',
                'merchant_order' => [
                    'id' => 'INV-42',
                ],
                'payment_data' => [
                    'id' => '362727264',
                ],
                'payout_data' => [
                    'id' => '4237264',
                    'amount' => 125000,
                    'currency' => 'IDR',
                    'status' => 'IN_PROGRESS',
                    'created' => '2026-04-28T09:10:51Z',
                ],
            ], 201),
        ]);

        $updated = app(UnlimitTransferService::class)->submitTransfer(
            $provider,
            $transfer->fresh(['provider', 'user', 'beneficiary'])
        );

        $this->assertSame('4237264', $updated->external_transfer_id);
        $this->assertSame('362727264', $updated->external_payment_id);
        $this->assertSame('pending', $updated->status);
        $this->assertNotNull($updated->submitted_at);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://sandbox.cardpay.com/api/payouts') {
                return false;
            }

            $data = $request->data();

            return $request->hasHeader('Authorization', 'Bearer unlimit-access-token')
                && $data['payment_method'] === 'BANKTRANSFERSIDR'
                && $data['request']['id'] === 'INV-42'
                && $data['merchant_order']['id'] === 'INV-42'
                && $data['payout_data']['amount'] === 125000.0
                && $data['payout_data']['currency'] === 'IDR'
                && $data['customer']['full_name'] === 'Jane Doe'
                && $data['ewallet_account']['id'] === '1234567890'
                && $data['ewallet_account']['bank_code'] === '014';
        });
    }

    public function test_sync_transfer_status_fetches_unlimit_payout(): void
    {
        Cache::flush();

        $provider = IntegrationProvider::query()->create([
            'code' => 'unlimit',
            'name' => 'Unlimit',
            'status' => 'active',
        ]);

        $user = User::factory()->create();
        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_account_id' => 'terminal-123',
            'status' => 'active',
        ]);

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

        config()->set('services.unlimit.base_url', 'https://sandbox.cardpay.com/api');
        config()->set('services.unlimit.auth', [
            'mode' => 'unlimit_access_token',
            'token_endpoint' => '/auth/token',
            'terminal_code' => 'terminal-123',
            'password' => 'terminal-secret',
            'cache_key' => 'tests:unlimit:token',
            'cache_buffer_seconds' => 30,
        ]);

        Http::fake([
            'https://sandbox.cardpay.com/api/auth/token' => Http::response([
                'access_token' => 'unlimit-access-token',
                'expires_in' => 300,
            ], 200),
            'https://sandbox.cardpay.com/api/payouts/4237264' => Http::response([
                'payment_method' => 'BANKTRANSFERSIDR',
                'payment_data' => [
                    'id' => '362727264',
                ],
                'payout_data' => [
                    'id' => '4237264',
                    'amount' => 125000,
                    'currency' => 'IDR',
                    'status' => 'COMPLETED',
                    'created' => '2026-04-28T09:10:51Z',
                ],
            ], 200),
        ]);

        $updated = app(UnlimitTransferService::class)->syncTransferStatus(
            $provider,
            $transfer->fresh(['provider', 'user', 'beneficiary'])
        );

        $this->assertSame('completed', $updated->status);
        $this->assertNotNull($updated->completed_at);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://sandbox.cardpay.com/api/payouts/4237264'
                && $request->hasHeader('Authorization', 'Bearer unlimit-access-token');
        });
    }
}
