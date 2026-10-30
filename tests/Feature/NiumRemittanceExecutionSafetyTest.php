<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\Balance;
use App\Models\LedgerEntry;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Nium\NiumService;
use App\Services\Nium\NiumTransferService;
use App\Services\Wallet\LedgerService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesNiumRemittanceSafetyFixtures;
use Tests\Concerns\RefreshesCommittedPayoutDatabase;
use Tests\TestCase;

class NiumRemittanceExecutionSafetyTest extends TestCase
{
    use CreatesNiumRemittanceSafetyFixtures, RefreshesCommittedPayoutDatabase;

    public function test_remittance_refuses_an_outer_transaction_before_consuming_authorization(): void
    {
        [, $transfer] = $this->makeSubmittableTransfer();
        Http::fake();
        DB::beginTransaction();
        try {
            app(NiumService::class)->post('/synthetic-remittance', [], $transfer->user,
                $transfer->id, 'transfer_money', 'nium-'.Str::uuid());
            $this->fail('The dispatch marker must commit before provider I/O');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('outside transactions', $exception->getMessage());
        } finally {
            DB::rollBack();
        }
        Http::assertNothingSent();
        $this->assertNull($transfer->fresh()->raw_data['nium_execution']['dispatch_started_at'] ?? null);
    }

    public static function ambiguousStatuses(): array
    {
        return array_map(fn ($status) => [$status], [301, 302, 307, 308, 408, 409, 429, 500]);
    }

    #[DataProvider('ambiguousStatuses')]
    public function test_ambiguous_response_never_retries_or_releases_and_preserves_uuid(int $status): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer();
        $posts = 0;
        $redirects = null;
        Http::fake(function ($request, $options) use (&$posts, &$redirects, $status) {
            if ($request->method() !== 'POST') {
                return Http::response([['description' => 'Synthetic purpose', 'purposeCode' => 'IR01811']]);
            }
            $posts++;
            $redirects = $options['allow_redirects'];

            return Http::response([], $status, ['Location' => 'https://synthetic.invalid/redirect']);
        });
        $manager = app(ProviderTransferManager::class);
        $unknown = $manager->submitTransfer($provider, $transfer);
        $this->assertFalse($redirects);
        $this->assertSame('submission_unknown', $unknown->status);
        $this->assertSame('10.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'release')->count());
        $log = ApiRequestLog::query()->where('operation', 'transfer_money')->sole();
        $this->assertSame($unknown->provider_operation_key, $log->external_reference);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->hasHeader('x-request-id', substr($unknown->provider_operation_key, 5)));
        // Even an erroneous administrative status reset cannot reuse an operation identity.
        $unknown->update(['status' => 'draft']);
        try {
            $manager->submitTransfer($provider, $unknown->fresh());
            $this->fail('Operation identity must never be reused');
        } catch (RuntimeException) {
        }
        $this->assertSame(1, $posts);
        $this->assertSame('10.00000000', Balance::query()->sole()->reserved_balance);
    }

    public function test_timeout_and_missing_reference_keep_owned_reservation(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer();
        Http::fake(function ($request) {
            if ($request->method() !== 'POST') {
                return Http::response([['description' => 'Synthetic purpose', 'purposeCode' => 'IR01811']]);
            }
            throw new ConnectionException('Synthetic timeout');
        });
        $unknown = app(ProviderTransferManager::class)->submitTransfer($provider, $transfer);
        $this->assertSame('submission_unknown', $unknown->status);
        $this->assertSame($unknown->provider_operation_key, LedgerEntry::query()->where('entry_type', 'hold')->sole()->raw_data['operation_key']);
        try {
            app(LedgerService::class)->releaseTransferHold($unknown);
            $this->fail('Unknown reservation cannot be released');
        } catch (RuntimeException) {
        }
        $this->assertSame('10.00000000', Balance::query()->sole()->reserved_balance);
    }

    public function test_stale_release_identity_and_conflicting_terminal_entry_are_refused(): void
    {
        [$provider, $stale] = $this->makeSubmittableTransfer();
        Http::fake(fn ($r) => Http::response($r->method() === 'POST'
            ? ['systemReferenceNumber' => 'synthetic-ref']
            : [['description' => 'Synthetic purpose', 'purposeCode' => 'IR01811']]));
        $pending = app(ProviderTransferManager::class)->submitTransfer($provider, $stale);
        $prepared = ['operation_key' => $pending->provider_operation_key, 'system_reference' => 'synthetic-ref',
            'status' => 'failed', 'response_data' => [], 'status_payload' => ['status' => 'FAILED']];
        app(NiumTransferService::class)->applyPreparedTransferStatus($provider, $pending, $prepared);
        try {
            app(LedgerService::class)->releaseTransferHold($stale);
            $this->fail('Stale owner must be refused');
        } catch (RuntimeException) {
        }
        $ledger = app(LedgerService::class);
        $ledger->applyTransferTerminalStatus($pending->fresh());
        $ledger->applyTransferTerminalStatus($pending->fresh());
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'release')->count());
        $this->assertSame('1000.00000000', Balance::query()->sole()->available_balance);
        $pending->refresh();
        $pending->update(['status' => 'completed']);
        $this->expectException(RuntimeException::class);
        $ledger->settleTransfer($pending);
    }

    public function test_provider_terminal_result_during_post_wins_over_late_timeout(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer();
        Http::fake(function ($request) use ($provider, $transfer) {
            if ($request->method() !== 'POST') {
                return Http::response([['description' => 'Synthetic purpose', 'purposeCode' => 'IR01811']]);
            }
            $current = $transfer->fresh();
            app(NiumTransferService::class)->applyPreparedTransferStatus($provider, $current, [
                'status' => 'completed', 'response_data' => [], 'status_payload' => ['status' => 'PAID'],
            ]);
            app(LedgerService::class)->applyTransferTerminalStatus($current->fresh());
            throw new ConnectionException('Synthetic delayed timeout after reconciliation');
        });
        $completed = app(ProviderTransferManager::class)->submitTransfer($provider, $transfer);
        $this->assertSame('completed', $completed->status);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'release')->count());
    }

    public function test_disabled_ledger_blocks_dispatch_before_claim_or_post(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer();
        config()->set('wallet.ledger.enabled', false);
        Http::fake(['*' => Http::response([['description' => 'Synthetic purpose', 'purposeCode' => 'IR01811']])]);
        try {
            app(ProviderTransferManager::class)->submitTransfer($provider, $transfer);
            $this->fail('Dispatch requires a reservation');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('enabled reservation ledger', $exception->getMessage());
        }
        $this->assertNull($transfer->fresh()->provider_operation_key);
        $this->assertSame('draft', $transfer->fresh()->status);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_remittance_transport_cannot_reuse_a_consumed_durable_authorization(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer();
        Http::fake(fn ($r) => Http::response($r->method() === 'POST'
            ? ['systemReferenceNumber' => 'synthetic-ref']
            : [['description' => 'Synthetic purpose', 'purposeCode' => 'IR01811']]));
        $pending = app(ProviderTransferManager::class)->submitTransfer($provider, $transfer);
        $this->assertNotEmpty($pending->raw_data['nium_execution']['dispatch_started_at']);
        // Even with a stale submitting state, the consumed wire authorization refuses another POST.
        $pending->update(['status' => 'submitting']);
        $this->expectExceptionMessage('authorization has already been consumed');
        try {
            app(NiumService::class)->post('/synthetic-remittance', [], $pending->user,
                $pending->id, 'transfer_money', $pending->provider_operation_key);
        } finally {
            $this->assertCount(1, collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'POST'));
            $this->assertSame('10.00000000', Balance::query()->sole()->reserved_balance);
        }
    }
}
