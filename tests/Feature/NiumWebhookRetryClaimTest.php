<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\ProviderWebhookEventController;
use App\Models\{IntegrationProvider, Transaction, Transfer, User, WebhookEvent};
use App\Services\Integrations\ProviderRegistry;
use App\Services\Nium\{NiumWebhookProcessingClaim, NiumWebhookService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Event};
use RuntimeException;
use Tests\Support\DynamicConfirmationDatabaseTestCase;
use Throwable;

/** Offline, committed PostgreSQL sessions. No real provider transport. */
final class NiumWebhookRetryClaimTest extends DynamicConfirmationDatabaseTestCase
{
    private IntegrationProvider $provider;
    private Transfer $transfer;
    private WebhookEvent $receipt;

    protected function setUp(): void
    {
        parent::setUp();
        config(['wallet.ledger.enabled' => false, 'integrations.providers.nium.webhook' => NiumWebhookService::class]);
        $this->provider = IntegrationProvider::query()->create(['code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        $this->transfer = Transfer::query()->create(['user_id' => User::factory()->create()->id,
            'provider_id' => $this->provider->id, 'transfer_no' => 'CLAIM-TEST', 'external_transfer_id' => 'RT-claim',
            'transfer_type' => 'payout', 'source_currency' => 'USD', 'target_currency' => 'USD',
            'source_amount' => 10, 'target_amount' => 10, 'status' => 'pending']);
        $this->receipt = WebhookEvent::query()->create(['provider_id' => $this->provider->id,
            'event_id' => 'claim-retry', 'event_type' => 'remittance.completed', 'processing_status' => 'failed',
            'payload' => ['eventType' => 'remittance.completed', 'data' => ['resource' => [
                'systemReferenceNumber' => 'RT-claim', 'transactionId' => 'TX-claim', 'status' => 'COMPLETED']]]]);
    }

    public function test_nested_retry_does_not_reenter_the_same_session_claim(): void
    {
        $calls = 0;
        $result = app(NiumWebhookProcessingClaim::class)->process($this->receipt, function () use (&$calls): void {
            $calls++;
            $this->assertSame(0, DB::transactionLevel());
            $nested = app(NiumWebhookService::class)->reprocessWebhookEvent($this->provider, $this->receipt);
            $this->assertSame('processing', $nested->processing_status);
            app(NiumWebhookProcessingClaim::class)->process($this->receipt, function () use (&$calls): void { $calls++; });
        });
        $this->assertSame('processed', $result->processing_status);
        $this->assertSame(1, $calls);
        $this->assertSame(1, $result->nium_processing_claim['attempts']);
        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_two_admin_retries_on_independent_sessions_have_one_processor(): void
    {
        $this->assertTrue(function_exists('pcntl_fork'));
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_timeout($parent, 10);
        stream_set_timeout($child, 10);
        DB::disconnect();
        $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent);
            try {
                DB::purge(); DB::reconnect();
                Event::listen('eloquent.updating: '.Transfer::class, function () use ($child): void {
                    fwrite($child, "claimed\n");
                    if (trim((string) fgets($child)) !== 'release') throw new RuntimeException('Retry barrier timed out.');
                });
                app(NiumWebhookService::class)->reprocessWebhookEvent($this->provider, $this->receipt);
                fwrite($child, "ok\n");
            } catch (Throwable $e) {
                fwrite($child, $e::class.': '.$e->getMessage()."\n");
            }
            DB::disconnect(); fclose($child); exit(0);
        }
        $this->assertGreaterThan(0, $pid);
        fclose($child);
        $released = false;
        try {
            $this->assertSame('claimed', trim((string) fgets($parent)));
            DB::purge(); DB::reconnect();
            $response = app(ProviderWebhookEventController::class)->retry(
                Request::create('/internal/admin-webhook-retry', 'POST'), $this->receipt, app(ProviderRegistry::class));
            $this->assertSame(422, $response->getStatusCode());
            $this->assertSame('processing', $response->getData(true)['webhook_event']['status']);
            $this->assertSame(1, $this->receipt->fresh()->nium_processing_claim['attempts']);
            $this->assertSame(0, Transaction::query()->count());
            fwrite($parent, "release\n");
            $released = true;
            $this->assertSame('ok', trim((string) fgets($parent)));
        } finally {
            if (! $released) @fwrite($parent, "release\n");
            pcntl_waitpid($pid, $status);
            fclose($parent);
        }
        $this->assertSame('processed', $this->receipt->fresh()->processing_status);
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(1, $this->receipt->fresh()->nium_processing_claim['attempts']);
    }

    public function test_dead_process_releases_session_lock_and_next_retry_replaces_stale_token(): void
    {
        $this->assertTrue(function_exists('pcntl_fork'));
        DB::disconnect();
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::purge(); DB::reconnect();
            app(NiumWebhookProcessingClaim::class)->process($this->receipt, static function (): void { exit(0); });
            exit(1);
        }
        $this->assertGreaterThan(0, $pid);
        pcntl_waitpid($pid, $status);
        $this->assertSame(0, pcntl_wexitstatus($status));
        DB::purge(); DB::reconnect();
        $previous = $this->receipt->fresh()->nium_processing_claim['token'];
        $result = app(NiumWebhookService::class)->reprocessWebhookEvent($this->provider, $this->receipt);
        $this->assertSame('processed', $result->processing_status);
        $this->assertNotSame($previous, $result->nium_processing_claim['token']);
        $this->assertSame(2, $result->nium_processing_claim['attempts']);
    }

    public function test_token_takeover_fences_nested_authoritative_write_and_final_acknowledgement(): void
    {
        $config = config('database.connections.'.config('database.default'));
        config(['database.connections.claim_attacker' => $config]);
        $other = DB::connection('claim_attacker');
        $replacement = ['token' => 'replacement-token', 'attempts' => 99];
        try {
            app(NiumWebhookProcessingClaim::class)->process($this->receipt, function () use ($other, $replacement): void {
                $other->table('webhook_events')->where('id', $this->receipt->id)
                    ->update(['nium_processing_claim' => json_encode($replacement)]);
                DB::transaction(fn () => $this->transfer->update(['status' => 'completed']));
            });
            $this->fail('Lost token was allowed to write.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('lost its durable claim', $e->getMessage());
        } finally {
            DB::purge('claim_attacker');
        }
        $this->assertSame('pending', $this->transfer->fresh()->status);
        $this->assertSame($replacement, $this->receipt->fresh()->nium_processing_claim);
        $this->assertSame('processing', $this->receipt->fresh()->processing_status);
    }

    public function test_lost_advisory_lock_fences_writes_even_when_token_is_unchanged(): void
    {
        try {
            app(NiumWebhookProcessingClaim::class)->process($this->receipt, function (): void {
                DB::select('SELECT pg_advisory_unlock_all()', [], false);
                DB::transaction(fn () => $this->transfer->update(['status' => 'completed']));
            });
            $this->fail('Lost session lock was allowed to write.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('lost its session claim', $e->getMessage());
        }
        $this->assertSame('pending', $this->transfer->fresh()->status);
        $this->assertSame('processing', $this->receipt->fresh()->processing_status);
    }

    public function test_disconnected_session_cannot_reconnect_and_replay_an_authoritative_write(): void
    {
        try {
            app(NiumWebhookProcessingClaim::class)->process($this->receipt, function (): void {
                DB::disconnect();
                DB::transaction(fn () => $this->transfer->update(['status' => 'completed']));
            });
            $this->fail('A lost session reconnected during processing.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('lost its database session', $e->getMessage());
        }
        DB::reconnect();
        $this->assertSame('pending', $this->transfer->fresh()->status);
        $result = app(NiumWebhookService::class)->reprocessWebhookEvent($this->provider, $this->receipt);
        $this->assertSame('processed', $result->processing_status);
        $this->assertSame(2, $result->nium_processing_claim['attempts']);
    }

    public function test_original_client_digest_restores_redacted_receipt_scope_for_retry(): void
    {
        config(['services.nium.client_id' => 'claim-client', 'services.nium.webhook.static_header_name' => 'x-partner-key',
            'services.nium.webhook.static_header_value' => 'fixture-key']);
        $payload = ['eventId' => 'client-replay', 'clientHashId' => 'claim-client',
            'data' => ['resource' => ['clientHashId' => 'claim-client', 'systemReferenceNumber' => 'RT-claim',
                'transactionId' => 'TX-client-replay', 'status' => 'COMPLETED']]];
        $event = 'eloquent.updating: '.Transfer::class;
        Event::listen($event, static function (): void { throw new RuntimeException('Simulated first processing failure.'); });
        try {
            $request = Request::create('/webhook', 'POST', server: ['CONTENT_TYPE' => 'application/json',
                'HTTP_X_PARTNER_KEY' => 'fixture-key'], content: json_encode($payload));
            app(NiumWebhookService::class)->handleWebhook($this->provider, $request);
            $this->fail('Simulated write failure was ignored.');
        } catch (RuntimeException $e) {
            $this->assertSame('webhook_processing_failed', $e->getMessage());
        } finally {
            Event::forget($event);
        }
        $receipt = WebhookEvent::query()->where('event_id', 'client-replay')->sole();
        $this->assertSame('[REDACTED]', $receipt->payload['clientHashId']);
        $this->assertSame('[REDACTED]', $receipt->payload['data']['resource']['clientHashId']);
        $hashes = $receipt->nium_processing_claim['client_identity_sha256'];
        $this->assertSame(hash('sha256', 'claim-client'), $hashes['resource']);
        $result = app(NiumWebhookService::class)->reprocessWebhookEvent($this->provider, $receipt);
        $this->assertSame('processed', $result->processing_status);
        $this->assertSame($hashes, $result->nium_processing_claim['client_identity_sha256']);
        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_redacted_client_cannot_be_replaced_by_different_current_configuration(): void
    {
        config(['services.nium.client_id' => 'different-client']);
        $payload = $this->receipt->payload;
        $payload['clientHashId'] = '[REDACTED]';
        $this->receipt->update(['payload' => $payload, 'nium_processing_claim' => [
            'client_identity_sha256' => ['payload' => hash('sha256', 'original-client')],
        ]]);
        try {
            app(NiumWebhookService::class)->reprocessWebhookEvent($this->provider, $this->receipt);
            $this->fail('The configured client replaced an unverified original identity.');
        } catch (RuntimeException $e) {
            $this->assertSame('Nium webhook replay client scope cannot be verified.', $e->getPrevious()?->getMessage());
        }
        $this->assertSame('pending', $this->transfer->fresh()->status);
        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_failure_releases_exclusion_and_a_later_retry_finishes(): void
    {
        try {
            app(NiumWebhookProcessingClaim::class)->process($this->receipt, static function (): void {
                $level = DB::transactionLevel();
                if ($level !== 0) throw new RuntimeException('Transaction spans external work.');
                throw new RuntimeException('Simulated external read failure.');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated external read failure.', $e->getMessage());
        }
        $this->assertSame('failed', $this->receipt->fresh()->processing_status);
        $result = app(NiumWebhookService::class)->reprocessWebhookEvent($this->provider, $this->receipt);
        $this->assertSame('processed', $result->processing_status);
        $this->assertSame(2, $result->nium_processing_claim['attempts']);
    }
}
