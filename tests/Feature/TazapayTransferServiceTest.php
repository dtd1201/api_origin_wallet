<?php

namespace Tests\Feature;

use App\Models\Balance;
use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Tazapay\TazapayTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TazapayTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_transfer_creates_tazapay_payout_and_updates_transfer(): void
    {
        $provider = IntegrationProvider::query()->create([
            'code' => 'tazapay',
            'name' => 'Tazapay',
            'status' => 'active',
        ]);

        $user = User::factory()->create();
        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_account_id' => 'acc_connected_001',
            'status' => 'active',
            'metadata' => [
                'tz_account_id' => 'acc_connected_001',
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
            'external_beneficiary_id' => 'bnf_test_123',
            'beneficiary_type' => 'personal',
            'full_name' => 'Jane Doe',
            'country_code' => 'IN',
            'currency' => 'INR',
            'status' => 'active',
        ]);

        $transfer = Transfer::query()->create([
            'transfer_no' => 'TRF-TAZA12345678',
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'beneficiary_id' => $beneficiary->id,
            'transfer_type' => 'local',
            'source_currency' => 'USD',
            'target_currency' => 'INR',
            'source_amount' => 100,
            'target_amount' => 8300,
            'purpose_code' => 'PYR003',
            'reference_text' => 'Invoice 42',
            'client_reference' => 'INV-42',
            'status' => 'draft',
            'raw_data' => [
                'tazapay' => [
                    'type' => 'local',
                    'charge_type' => 'shared',
                    'local' => [
                        'fund_transfer_network' => 'imps',
                    ],
                ],
            ],
        ]);

        config()->set('services.tazapay.base_url', 'https://service-sandbox.tazapay.com');
        config()->set('services.tazapay.auth', [
            'mode' => 'basic_auth',
            'username' => 'tzp_key',
            'password' => 'tzp_secret',
        ]);

        Http::fake([
            'https://service-sandbox.tazapay.com/v3/payout' => Http::response([
                'status' => 'success',
                'message' => '',
                'data' => [
                    'id' => 'pot_test_123',
                    'amount' => 8300,
                    'status' => 'processing',
                    'tracking_details' => [
                        'tracking_number' => 'TZP-TRACK-001',
                    ],
                    'holding_fx_transaction' => [
                        'exchange_rate' => 83,
                    ],
                ],
            ], 200),
        ]);

        $updated = app(TazapayTransferService::class)->submitTransfer(
            $provider,
            $transfer->fresh(['provider', 'user', 'beneficiary'])
        );

        $this->assertSame('pot_test_123', $updated->external_transfer_id);
        $this->assertSame('TZP-TRACK-001', $updated->external_payment_id);
        $this->assertSame('pending', $updated->status);
        $this->assertNotNull($updated->submitted_at);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://service-sandbox.tazapay.com/v3/payout'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('tzp_key:tzp_secret'))
                && $data['beneficiary'] === 'bnf_test_123'
                && $data['amount'] === 8300.0
                && $data['holding_currency'] === 'USD'
                && $data['currency'] === 'INR'
                && $data['local']['fund_transfer_network'] === 'imps';
        });
    }

    public function test_sync_transfer_status_fetches_tazapay_payout_and_marks_transfer_completed(): void
    {
        $provider = IntegrationProvider::query()->create([
            'code' => 'tazapay',
            'name' => 'Tazapay',
            'status' => 'active',
        ]);

        $user = User::factory()->create();
        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_account_id' => 'acc_connected_001',
            'status' => 'active',
        ]);

        $beneficiary = Beneficiary::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'external_beneficiary_id' => 'bnf_test_123',
            'beneficiary_type' => 'personal',
            'full_name' => 'Jane Doe',
            'country_code' => 'IN',
            'currency' => 'INR',
            'status' => 'active',
        ]);

        $transfer = Transfer::query()->create([
            'transfer_no' => 'TRF-TAZA12345678',
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'beneficiary_id' => $beneficiary->id,
            'external_transfer_id' => 'pot_test_123',
            'transfer_type' => 'local',
            'source_currency' => 'USD',
            'target_currency' => 'INR',
            'source_amount' => 100,
            'target_amount' => 8300,
            'purpose_code' => 'PYR003',
            'reference_text' => 'Invoice 42',
            'status' => 'pending',
        ]);

        config()->set('services.tazapay.base_url', 'https://service-sandbox.tazapay.com');
        config()->set('services.tazapay.auth', [
            'mode' => 'basic_auth',
            'username' => 'tzp_key',
            'password' => 'tzp_secret',
        ]);

        Http::fake([
            'https://service-sandbox.tazapay.com/v3/payout/pot_test_123' => Http::response([
                'status' => 'success',
                'message' => '',
                'data' => [
                    'id' => 'pot_test_123',
                    'amount' => 8300,
                    'status' => 'succeeded',
                    'tracking_details' => [
                        'tracking_number' => 'TZP-TRACK-001',
                    ],
                    'payout_fx_transaction' => [
                        'final' => [
                            'amount' => 8300,
                        ],
                    ],
                    'holding_fx_transaction' => [
                        'exchange_rate' => 83,
                    ],
                    'created_at' => now()->toISOString(),
                ],
            ], 200),
        ]);

        $updated = app(TazapayTransferService::class)->syncTransferStatus(
            $provider,
            $transfer->fresh(['provider', 'user', 'beneficiary'])
        );

        $this->assertSame('completed', $updated->status);
        $this->assertNotNull($updated->completed_at);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://service-sandbox.tazapay.com/v3/payout/pot_test_123'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('tzp_key:tzp_secret'));
        });
    }
}
