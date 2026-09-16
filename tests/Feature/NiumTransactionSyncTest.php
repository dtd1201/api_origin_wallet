<?php

namespace Tests\Feature;

use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Nium\NiumDataSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NiumTransactionSyncTest extends TestCase
{
    use RefreshDatabase;


    public function test_transaction_sync_does_not_duplicate_transaction_already_created_by_webhook(): void
    {
        config()->set('services.nium.base_url', 'https://gateway.sandbox.nium.test');
        config()->set('services.nium.client_id', 'client-test');
        config()->set('services.nium.auth', [
            'mode' => 'header',
            'header_name' => 'x-api-key',
            'header_value' => 'test-key',
        ]);
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'test-partner-key');
        config()->set('wallet.ledger.enabled', false);
        config()->set('services.nium.transaction_sync_page_size', 100);
        config()->set('services.nium.transaction_sync_max_pages', 1);

        $provider = IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);

        $user = User::factory()->create();

        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => 'customer-test',
            'external_account_id' => 'wallet-test',
            'status' => 'active',
            'provider_status' => 'clear',
            'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(),
            'provider_ids_verified_at' => now(),
        ]);

        $transfer = Transfer::query()->create([
            'transfer_no' => 'TRF-TRANSACTION-DEDUP',
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'external_transfer_id' => 'RT-TRANSACTION-DEDUP',
            'transfer_type' => 'payout',
            'source_currency' => 'USD',
            'target_currency' => 'USD',
            'source_amount' => 10,
            'target_amount' => 10,
            'status' => 'pending',
        ]);

        $this->withHeader('x-partner-key', 'test-partner-key')
            ->postJson('/api/webhooks/providers/nium', [
                'eventId' => 'transaction-dedup-webhook-001',
                'eventType' => 'remittance.completed',
                'data' => [
                    'resource' => [
                        'systemReferenceNumber' => 'RT-TRANSACTION-DEDUP',
                        'transactionId' => 'TXN-TRANSACTION-DEDUP',
                        'status' => 'COMPLETED',
                    ],
                ],
            ])
            ->assertOk();

        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(
            'TXN-TRANSACTION-DEDUP',
            Transaction::query()->sole()->external_transaction_id
        );

        Http::fake([
            '*' => Http::response([
                'content' => [[
                    'transactionHashId' => 'HASH-TRANSACTION-DEDUP',
                    'transactionId' => 'TXN-TRANSACTION-DEDUP',
                    'systemReferenceNumber' => 'RT-TRANSACTION-DEDUP',
                    'currency' => 'USD',
                    'amount' => 10,
                    'status' => 'COMPLETED',
                    'dateTime' => now()->toISOString(),
                ]],
                'totalPages' => 1,
            ], 200),
        ]);

        app(NiumDataSyncService::class)->syncTransactions($provider, $user);

        $this->assertSame(
            1,
            Transaction::query()->count(),
            'Transaction sync must converge with a transaction already created by webhook.'
        );

        $transaction = Transaction::query()->sole();

        $this->assertSame($transfer->id, $transaction->transfer_id);
    }


    public function test_webhook_does_not_duplicate_transaction_already_created_by_sync(): void
    {
        config()->set('services.nium.base_url', 'https://gateway.sandbox.nium.test');
        config()->set('services.nium.client_id', 'client-test');
        config()->set('services.nium.auth', [
            'mode' => 'header',
            'header_name' => 'x-api-key',
            'header_value' => 'test-key',
        ]);
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'test-partner-key');
        config()->set('wallet.ledger.enabled', false);
        config()->set('services.nium.transaction_sync_page_size', 100);
        config()->set('services.nium.transaction_sync_max_pages', 1);

        $provider = IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);

        $user = User::factory()->create();

        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => 'customer-reverse',
            'external_account_id' => 'wallet-reverse',
            'status' => 'active',
            'provider_status' => 'clear',
            'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(),
            'provider_ids_verified_at' => now(),
        ]);

        $transfer = Transfer::query()->create([
            'transfer_no' => 'TRF-TRANSACTION-REVERSE',
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'external_transfer_id' => 'RT-TRANSACTION-REVERSE',
            'transfer_type' => 'payout',
            'source_currency' => 'USD',
            'target_currency' => 'USD',
            'source_amount' => 10,
            'target_amount' => 10,
            'status' => 'pending',
        ]);

        Http::fake([
            '*' => Http::response([
                'content' => [[
                    'transactionHashId' => 'HASH-TRANSACTION-REVERSE',
                    'transactionId' => 'TXN-TRANSACTION-REVERSE',
                    'systemReferenceNumber' => 'RT-TRANSACTION-REVERSE',
                    'currency' => 'USD',
                    'amount' => 10,
                    'status' => 'COMPLETED',
                    'dateTime' => now()->toISOString(),
                ]],
                'totalPages' => 1,
            ], 200),
        ]);

        app(NiumDataSyncService::class)->syncTransactions($provider, $user);

        $this->withHeader('x-partner-key', 'test-partner-key')
            ->postJson('/api/webhooks/providers/nium', [
                'eventId' => 'transaction-reverse-webhook-001',
                'eventType' => 'remittance.completed',
                'data' => [
                    'resource' => [
                        'systemReferenceNumber' => 'RT-TRANSACTION-REVERSE',
                        'transactionId' => 'TXN-TRANSACTION-REVERSE',
                        'status' => 'COMPLETED',
                    ],
                ],
            ])
            ->assertOk();

        $this->assertSame(
            1,
            Transaction::query()->count(),
            'Webhook and transaction sync must converge on the same Nium transaction.'
        );

        $this->assertSame($transfer->id, Transaction::query()->sole()->transfer_id);
    }

    public function test_transaction_sync_is_bounded_paginated_deduplicated_and_checkpointed(): void
    {
        config()->set('services.nium.base_url', 'https://gateway.sandbox.nium.test');
        config()->set('services.nium.client_id', 'client-test');
        config()->set('services.nium.auth', ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'test-key']);
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'test-partner-key');
        config()->set('services.nium.transaction_sync_page_size', 1);
        config()->set('services.nium.transaction_sync_max_pages', 2);

        $provider = IntegrationProvider::query()->create(['code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        $user = User::factory()->create();
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

        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $page = (int) ($query['page'] ?? 0);

            return Http::response([
                'content' => [[
                    'transactionId' => $page === 0 ? 'txn-1' : 'txn-2',
                    'currency' => 'USD',
                    'amount' => 10 + $page,
                    'status' => 'COMPLETED',
                    'dateTime' => now()->toISOString(),
                    'accountNumber' => 'must-not-be-stored',
                ]],
                'totalPages' => 2,
            ]);
        });

        $result = app(NiumDataSyncService::class)->syncTransactions($provider, $user);
        app(NiumDataSyncService::class)->syncTransactions($provider, $user);

        $this->assertSame(2, $result['synced_transactions']);
        $this->assertSame(2, Transaction::query()->count());
        $this->assertNotNull($account->fresh()->transactions_last_synced_at);
        $this->assertSame(4, Http::recorded()->count());
        $this->assertStringNotContainsString('must-not-be-stored', json_encode(Transaction::query()->pluck('raw_data')->all()));
    }
}
