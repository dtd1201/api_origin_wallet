<?php

namespace Tests\Feature;

use App\Models\{BankAccount, IntegrationProvider, Transaction, Transfer, User, WebhookEvent};
use App\Services\Nium\{NiumCustomerOnboardingService, NiumDataSyncService, NiumService, NiumTransactionWriter, NiumWebhookService};
use App\Services\Wallet\Concurrency\OwnerFirst;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\Support\DynamicConfirmationDatabaseTestCase;
use Throwable;

final class NiumTransactionOwnershipIntegrityTest extends DynamicConfirmationDatabaseTestCase
{
    private IntegrationProvider $provider;
    private User $owner;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        config(['wallet.ledger.enabled' => false, 'services.nium.wallet_transactions_endpoint' => '/transactions',
            'services.nium.transaction_sync_page_size' => 100, 'services.nium.transaction_sync_max_pages' => 1]);
        $this->provider = IntegrationProvider::query()->create(['code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
        foreach ([$this->owner, $this->other] as $user) {
            $user->providerAccounts()->create(['provider_id' => $this->provider->id,
                'external_customer_id' => 'customer-'.$user->id, 'external_account_id' => 'wallet-'.$user->id]);
        }
    }

    private function transfer(User $owner, string $name, string $reference = 'shared-reference'): Transfer
    {
        return Transfer::query()->create(['user_id' => $owner->id, 'provider_id' => $this->provider->id,
            'transfer_no' => $name, 'client_reference' => $reference, 'transfer_type' => 'payout',
            'source_currency' => 'USD', 'target_currency' => 'USD', 'source_amount' => 10, 'target_amount' => 10,
            'status' => 'pending']);
    }

    private function sync(array $row): void
    {
        $nium = Mockery::mock(NiumService::class)->makePartial();
        $nium->shouldReceive('clientId')->andReturn('client');
        $nium->shouldReceive('customerId')->andReturn('customer-'.$this->owner->id);
        $nium->shouldReceive('walletId')->andReturn('wallet-'.$this->owner->id);
        $nium->shouldReceive('get')->once()->andReturnUsing(function () use ($row): Response {
            $this->assertSame(0, DB::transactionLevel());
            return new Response(new PsrResponse(200, [], json_encode(['content' => [$row], 'totalPages' => 1])));
        });
        (new NiumDataSyncService($nium, app(NiumCustomerOnboardingService::class)))->syncTransactions($this->provider, $this->owner);
    }

    private function receipt(array $resource): WebhookEvent
    {
        return WebhookEvent::query()->create(['provider_id' => $this->provider->id, 'event_id' => 'ownership-event',
            'event_type' => 'remittance.completed', 'processing_status' => 'failed',
            'payload' => ['eventType' => 'remittance.completed', 'data' => ['resource' => $resource]]]);
    }

    public function test_sync_never_reassigns_another_users_transaction_or_advances_checkpoint_on_conflict(): void
    {
        $existing = Transaction::query()->create(['user_id' => $this->other->id, 'provider_id' => $this->provider->id,
            'external_transaction_id' => 'TX-collision', 'currency' => 'USD', 'amount' => 10]);
        // Compare database-hydrated snapshots, including defaults, null columns and decimal representation.
        $before = $existing->fresh()->getAttributes();
        try {
            $this->sync(['transactionId' => 'TX-collision', 'currency' => 'USD', 'amount' => 99]);
            $this->fail('A transaction changed owner.');
        } catch (RuntimeException $e) {
            $this->assertSame('Nium transaction ownership conflict.', $e->getMessage());
        }
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertNull($this->owner->providerAccounts()->sole()->transactions_last_synced_at);
    }

    public function test_sync_scopes_shared_reference_to_user_and_provider(): void
    {
        $foreign = $this->transfer($this->other, 'FOREIGN');
        $owned = $this->transfer($this->owner, 'OWNED');
        $this->sync(['transactionId' => 'TX-scoped', 'clientReference' => 'shared-reference', 'currency' => 'USD', 'amount' => 10]);
        $this->assertSame($owned->id, Transaction::query()->sole()->transfer_id);
        $this->assertNotSame($foreign->id, Transaction::query()->sole()->transfer_id);
    }

    public function test_sync_never_links_a_foreign_user_or_provider_transfer(): void
    {
        $this->transfer($this->other, 'FOREIGN-ONLY');
        $otherProvider = IntegrationProvider::query()->create(['code' => 'synthetic-other', 'name' => 'Other', 'status' => 'active']);
        $foreignProviderTransfer = $this->transfer($this->owner, 'OTHER-PROVIDER');
        $foreignProviderTransfer->update(['provider_id' => $otherProvider->id]);
        $this->sync(['transactionId' => 'TX-no-link', 'clientReference' => 'shared-reference', 'currency' => 'USD', 'amount' => 10]);
        $this->assertSame($this->owner->id, Transaction::query()->sole()->user_id);
        $this->assertNull(Transaction::query()->sole()->transfer_id);
    }

    public function test_sync_cannot_replace_an_already_verified_transfer_with_another_transfer_of_the_same_user(): void
    {
        $verified = $this->transfer($this->owner, 'VERIFIED', 'old-reference');
        $incoming = $this->transfer($this->owner, 'INCOMING', 'new-reference');
        $record = Transaction::query()->create(['user_id' => $this->owner->id, 'provider_id' => $this->provider->id,
            'external_transaction_id' => 'TX-verified', 'transfer_id' => $verified->id, 'currency' => 'USD', 'amount' => 10]);
        try {
            $this->sync(['transactionId' => 'TX-verified', 'clientReference' => $incoming->client_reference, 'currency' => 'USD', 'amount' => 10]);
            $this->fail('Verified transfer linkage was replaced.');
        } catch (RuntimeException $e) {
            $this->assertSame('Nium transaction verified linkage conflict.', $e->getMessage());
        }
        $this->assertSame($verified->id, $record->fresh()->transfer_id);
    }

    public function test_missing_reference_preserves_verified_transfer_and_bank_links(): void
    {
        $transfer = $this->transfer($this->owner, 'VERIFIED');
        $bank = BankAccount::query()->create(['user_id' => $this->owner->id, 'provider_id' => $this->provider->id,
            'external_account_id' => 'verified-bank', 'account_type' => 'wallet', 'currency' => 'USD', 'status' => 'active']);
        $record = Transaction::query()->create(['user_id' => $this->owner->id, 'provider_id' => $this->provider->id,
            'external_transaction_id' => 'TX-linked', 'transfer_id' => $transfer->id, 'bank_account_id' => $bank->id,
            'currency' => 'USD', 'amount' => 10, 'status' => 'completed']);
        $this->sync(['transactionId' => 'TX-linked', 'currency' => 'USD', 'amount' => 10]);
        $this->assertSame($transfer->id, $record->fresh()->transfer_id);
        $this->assertSame($bank->id, $record->fresh()->bank_account_id);
        $this->assertSame('completed', $record->fresh()->status);
    }

    public function test_duplicate_references_within_one_user_fail_safe(): void
    {
        $this->transfer($this->owner, 'ONE');
        // Cross-column ambiguity is valid: distinct client references, but the second transfer_no matches the first client_reference.
        $this->transfer($this->owner, 'shared-reference', 'second-client-reference');
        try {
            $this->sync(['transactionId' => 'TX-ambiguous', 'clientReference' => 'shared-reference', 'currency' => 'USD', 'amount' => 10]);
            $this->fail('An ambiguous reference was linked.');
        } catch (RuntimeException $e) {
            $this->assertSame('Nium transfer reference is ambiguous.', $e->getMessage());
        }
        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_webhook_ownership_conflict_rolls_back_transfer_and_transaction_together(): void
    {
        $transfer = $this->transfer($this->owner, 'WEBHOOK');
        $existing = Transaction::query()->create(['user_id' => $this->other->id, 'provider_id' => $this->provider->id,
            'external_transaction_id' => 'TX-collision', 'currency' => 'USD', 'amount' => 10]);
        // Compare database-hydrated snapshots, including defaults, null columns and decimal representation.
        $before = $existing->fresh()->getAttributes();
        try {
            app(NiumWebhookService::class)->reprocessWebhookEvent($this->provider,
                $this->receipt(['clientReference' => $transfer->client_reference,
                    'customerHashId' => 'customer-'.$this->owner->id, 'walletHashId' => 'wallet-'.$this->owner->id,
                    'transactionId' => 'TX-collision', 'status' => 'COMPLETED']));
            $this->fail('Webhook reassigned a transaction.');
        } catch (RuntimeException $e) {
            $this->assertSame('Nium transaction ownership conflict.', $e->getPrevious()?->getMessage());
        }
        $this->assertSame('pending', $transfer->fresh()->status);
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertSame('failed', WebhookEvent::query()->sole()->processing_status);
    }

    public function test_unscoped_webhook_with_cross_user_reference_collision_changes_nothing(): void
    {
        $this->transfer($this->owner, 'ONE');
        $this->transfer($this->other, 'TWO');
        try {
            app(NiumWebhookService::class)->reprocessWebhookEvent($this->provider,
                $this->receipt(['clientReference' => 'shared-reference', 'transactionId' => 'TX-ambiguous', 'status' => 'COMPLETED']));
            $this->fail('Webhook guessed an owner.');
        } catch (RuntimeException $e) {
            $this->assertSame('Nium webhook transfer reference is ambiguous.', $e->getPrevious()?->getMessage());
        }
        $this->assertSame(2, Transfer::query()->where('status', 'pending')->count());
        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_concurrent_absent_row_upserts_for_two_users_have_one_immutable_owner(): void
    {
        $this->assertTrue(function_exists('pcntl_fork'));
        $start = tempnam(sys_get_temp_dir(), 'nium-owner-start-');
        $outputs = [tempnam(sys_get_temp_dir(), 'nium-owner-result-'), tempnam(sys_get_temp_dir(), 'nium-owner-result-')];
        $children = [];
        DB::disconnect();
        try {
            foreach ([$this->owner->id, $this->other->id] as $worker => $owner) {
                $pid = pcntl_fork();
                if ($pid === 0) {
                    try {
                        DB::purge(); DB::reconnect();
                        DB::statement("SET lock_timeout = '5s'");
                        DB::statement("SET statement_timeout = '10s'");
                        $deadline = microtime(true) + 10;
                        while (file_get_contents($start) !== 'go') {
                            if (microtime(true) > $deadline) throw new RuntimeException('Barrier timeout.');
                            usleep(1000);
                        }
                        OwnerFirst::users([$owner], fn () => app(NiumTransactionWriter::class)->save($this->provider,
                            $owner, 'TX-race', ['currency' => 'USD', 'amount' => 10]));
                        $result = 'ok:'.$owner;
                    } catch (Throwable $e) { $result = $e->getMessage(); }
                    file_put_contents($outputs[$worker], $result);
                    DB::disconnect(); exit(0);
                }
                $this->assertGreaterThan(0, $pid);
                $children[] = $pid;
            }
            file_put_contents($start, 'go');
            foreach ($children as $pid) { pcntl_waitpid($pid, $status); $this->assertSame(0, pcntl_wexitstatus($status)); }
            DB::purge(); DB::reconnect();
            $results = array_map('file_get_contents', $outputs);
            $this->assertCount(1, array_filter($results, fn ($r) => str_starts_with($r, 'ok:')));
            $this->assertContains('Nium transaction ownership conflict.', $results);
            $this->assertSame(1, Transaction::query()->count());
            $this->assertContains('ok:'.Transaction::query()->sole()->user_id, $results);
        } finally {
            file_put_contents($start, 'go');
            foreach ($children as $pid) pcntl_waitpid($pid, $status);
            foreach ([$start, ...$outputs] as $file) unlink($file);
            DB::purge(); DB::reconnect();
        }
    }
}
