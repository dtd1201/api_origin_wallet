<?php

namespace Tests\Feature;

use App\Models\IntegrationProvider;
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

    public function test_transaction_sync_is_bounded_paginated_deduplicated_and_checkpointed(): void
    {
        config()->set('services.nium.base_url', 'https://gateway.sandbox.nium.test');
        config()->set('services.nium.client_id', 'client-test');
        config()->set('services.nium.auth', ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'test-key']);
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'test-partner-key');
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

    public function test_targeted_transaction_sync_does_not_advance_global_watermark(): void
    {
        config()->set('services.nium.base_url', 'https://gateway.sandbox.nium.test');
        config()->set('services.nium.client_id', 'client-test');
        config()->set('services.nium.auth', ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'test-key']);
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'test-partner-key');
        config()->set('services.nium.transaction_sync_page_size', 20);
        $provider = IntegrationProvider::query()->create(['code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        $user = User::factory()->create();
        $oldWatermark = now()->subDays(5)->startOfSecond();
        $account = $user->providerAccounts()->create([
            'provider_id' => $provider->id, 'external_customer_id' => 'customer-targeted',
            'external_account_id' => 'wallet-targeted', 'status' => 'active', 'provider_status' => 'clear',
            'customer_id_verified_at' => now(), 'wallet_id_verified_at' => now(),
            'transactions_last_synced_at' => $oldWatermark,
        ]);
        Http::fake(['*' => Http::response(['content' => [], 'totalPages' => 1])]);

        app(NiumDataSyncService::class)->syncTransactionsFor($provider, $user, ['systemReferenceNumber' => 'SYS-TARGET']);
        $this->assertTrue($account->fresh()->transactions_last_synced_at->equalTo($oldWatermark));
        $targetedRequest = Http::recorded()[0][0];
        $this->assertSame('SYS-TARGET', $targetedRequest['systemReferenceNumber']);
        $this->assertArrayNotHasKey('startDate', $targetedRequest->data());
        $this->assertArrayNotHasKey('endDate', $targetedRequest->data());

        app(NiumDataSyncService::class)->syncTransactions($provider, $user);
        $this->assertTrue($account->fresh()->transactions_last_synced_at->greaterThan($oldWatermark));
        $broadRequest = Http::recorded()[1][0];
        $this->assertNotNull($broadRequest['startDate']);
        $this->assertNotNull($broadRequest['endDate']);
        Http::assertSent(fn (Request $request): bool => ($request['size'] ?? null) === 20);
    }
}
