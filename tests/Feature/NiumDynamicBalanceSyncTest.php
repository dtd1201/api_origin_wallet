<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Wallet\Concurrency\OwnerFirst;
use App\Models\LedgerEntry;
use App\Models\NiumDynamicReservation;
use App\Models\Transfer;
use App\Services\Nium\DynamicConfirmation\DynamicReservationLedger;
use App\Services\Nium\NiumWebhookService;
use App\Services\Wallet\LedgerService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesDynamicConfirmationFixtures;
use Tests\Concerns\CreatesFinalizedUsdConfirmationFixtures;
use Tests\Concerns\CreatesNiumBalanceSyncObservation;
use Tests\Support\DynamicConfirmationDatabaseTestCase;

final class NiumDynamicBalanceSyncTest extends DynamicConfirmationDatabaseTestCase
{
    use CreatesDynamicConfirmationFixtures;
    use CreatesFinalizedUsdConfirmationFixtures;
    use CreatesNiumBalanceSyncObservation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDynamicFixtures('3000');
        $this->wallet->update(['currency' => 'USD']);
        $this->source->review = $this->usdReview();
    }

    private function books(string $available, string $reserved, string $ledger): void
    {
        $wallet = $this->wallet->fresh();
        $this->assertSame($available.'.00000000', $wallet->available_balance);
        $this->assertSame($reserved.'.00000000', $wallet->reserved_balance);
        $this->assertSame($ledger.'.00000000', $wallet->ledger_balance);
    }

    public function test_sync_before_reservation_imports_balance_without_manufacturing_a_journal_entry(): void
    {
        $this->observeNiumBalance('4000');
        $this->books('4000', '0', '4000');
        $this->assertSame(0, LedgerEntry::query()->count());
        $this->assertNull($this->wallet->fresh()->raw_data['provider_balance_as_of']);
        $this->assertNotEmpty($this->wallet->fresh()->raw_data['provider_balance_observed_at']);
        $this->confirmDynamic();
        $this->books('3000', '1000', '4000');
    }

    public function test_provider_snapshots_before_and_after_debit_and_consumption_never_rewrite_books(): void
    {
        $intent = $this->confirmDynamic();
        $this->observeNiumBalance('3000'); // Provider has not debited yet.
        $this->books('2000', '1000', '3000');
        $this->observeNiumBalance('2000'); // Provider has debited, local hold remains.
        $this->books('2000', '1000', '3000');
        $this->assertSame(1, LedgerEntry::query()->count());

        // Exercise the journal primitive; authenticated reconciliation is covered
        // separately by NiumDynamicPayoutExecutionTest.
        OwnerFirst::users([$this->owner->id], function ($context) use ($intent): void {
            $intent = \App\Models\NiumDynamicIntent::query()->lockForUpdate()->findOrFail($intent->id);
            $reservation = NiumDynamicReservation::query()->lockForUpdate()->sole();
            (new DynamicReservationLedger)->consume($intent, $reservation, $this->wallet, $context);
            (new DynamicReservationLedger)->consume($intent, $reservation->fresh(), $this->wallet, $context);
        });
        $this->books('2000', '0', '2000');
        $this->observeNiumBalance('2000');
        $this->observeNiumBalance('3000'); // Late stale snapshot cannot recreate the debit.
        $this->books('2000', '0', '2000');
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'debit')->count());
    }

    public function test_multiple_reservations_survive_sync_and_release_exactly_once(): void
    {
        $first = $this->confirmDynamic('first');
        $this->source->review = $this->usdReview(changes: ['response' => ['id' => 'second-quote']]);
        $second = $this->confirmDynamic('second');
        $this->observeNiumBalance('3000');
        $this->books('1000', '2000', '3000');
        $this->confirmation->cancel($this->owner, $first->id);
        $this->observeNiumBalance('3000');
        $this->books('2000', '1000', '3000');
        $this->confirmation->cancel($this->owner, $second->id);
        $this->confirmation->cancel($this->owner, $second->id);
        $this->observeNiumBalance('3000');
        $this->books('3000', '0', '3000');
        $this->assertSame(2, LedgerEntry::query()->where('entry_type', 'release')->count());
    }

    public function test_sync_response_captured_before_confirmation_cannot_overwrite_new_hold(): void
    {
        $this->observeNiumBalance('3000', fn () => $this->confirmDynamic());
        $this->books('2000', '1000', '3000');
        $this->assertSame(1, LedgerEntry::query()->count());
    }

    public function test_low_observation_does_not_destroy_book_funds_during_reserve_or_cancellation(): void
    {
        $original = $this->confirmDynamic();
        $this->confirmation->cancel($this->owner, $original->id);
        $this->observeNiumBalance('1500');
        $this->source->review = $this->usdReview(changes: ['response' => ['id' => 'lower-ceiling-quote']]);
        $intent = $this->confirmDynamic('lower-ceiling');
        $this->books('2000', '1000', '3000'); // No permanent min(provider, books).
        $this->observeNiumBalance('100');
        $this->confirmation->cancel($this->owner, $intent->id);
        $this->books('3000', '0', '3000');
        $this->source->review = $this->usdReview(changes: ['response' => ['id' => 'recovered-quote']]);
        try {
            $this->confirmDynamic('recovered');
            $this->fail('Insufficient provider availability authorized another hold.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('dynamic_insufficient_available_funds', $exception->getMessage());
        }
        $this->observeNiumBalance('1500');
        $this->confirmDynamic('recovered');
        $this->books('2000', '1000', '3000');
    }

    public function test_unknown_funding_total_is_evidence_not_an_unaudited_credit_to_dynamic_books(): void
    {
        $intent = $this->confirmDynamic();
        $this->confirmation->cancel($this->owner, $intent->id);
        $this->observeNiumBalance('3500');
        $this->books('3000', '0', '3000');
        $this->assertSame('3500', $this->wallet->fresh()->raw_data['provider_available_balance']);
        $this->assertSame(2, LedgerEntry::query()->count());
    }

    public function test_account_rebinding_during_provider_read_cannot_import_an_old_wallet_response(): void
    {
        $sync = $this->balanceSyncObservation('4000',
            fn () => $this->account->update(['external_account_id' => 'changed-wallet']));
        try {
            $sync->syncBalances($this->account->provider, $this->owner);
            $this->fail('Rebound wallet accepted an old provider response.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Nium balance sync account binding changed.', $exception->getMessage());
        }
        $this->books('3000', '0', '3000');
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_funding_webhook_total_cannot_bypass_dynamic_ledger_ownership(): void
    {
        $this->confirmDynamic();
        // Invoke the existing funding handler without HTTP/authentication; this
        // test isolates accounting. Webhook authentication has its own suite.
        $handler = new \ReflectionMethod(NiumWebhookService::class, 'processFundingWebhook');
        $handler->invoke(app(NiumWebhookService::class), $this->account->provider, [
            'walletHashId' => 'fixture-wallet', 'transactionCurrency' => 'USD', 'walletBalance' => '3500',
        ]);
        $this->books('2000', '1000', '3000');
        $this->assertSame('3500', $this->wallet->fresh()->raw_data['nium_funding_balance_observation']['wallet_balance']);
        $this->assertSame(1, LedgerEntry::query()->count());
    }

    public function test_hk_swift_hold_and_terminal_ledger_reconciliation_survive_shared_balance_sync(): void
    {
        config(['wallet.ledger.enabled' => true]);
        $this->beneficiary->update(['country_code' => 'HK', 'currency' => 'USD', 'payout_method' => 'SWIFT']);
        $transfer = Transfer::query()->create([
            'transfer_no' => 'FIXTURE-HK-SWIFT-SYNC', 'user_id' => $this->owner->id,
            'provider_id' => $this->account->provider_id, 'beneficiary_id' => $this->beneficiary->id,
            'transfer_type' => 'payout', 'source_currency' => 'USD', 'target_currency' => 'USD',
            'source_amount' => '1000', 'status' => 'draft',
        ]);
        $ledger = app(LedgerService::class);
        $ledger->reserveTransfer($transfer);
        $this->observeNiumBalance('3000');
        $this->books('2000', '1000', '3000');
        $this->observeNiumBalance('2000');
        $this->books('2000', '1000', '3000');
        $transfer->update(['status' => 'completed']);
        $ledger->applyTransferTerminalStatus($transfer->fresh());
        $ledger->applyTransferTerminalStatus($transfer->fresh());
        $this->observeNiumBalance('2000');
        $this->books('2000', '0', '2000');
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame(0, NiumDynamicReservation::query()->count());
    }
}
