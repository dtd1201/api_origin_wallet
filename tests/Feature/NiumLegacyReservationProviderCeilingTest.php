<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Balance;
use App\Models\Beneficiary;
use App\Models\FxQuote;
use App\Models\IntegrationProvider;
use App\Models\LedgerEntry;
use App\Models\NiumDynamicReservation;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Nium\NiumSgdAdmissionFence;
use App\Services\Wallet\LedgerService;
use App\Services\Wallet\TransferApprovalService;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesDynamicConfirmationFixtures;
use Tests\Concerns\CreatesFinalizedUsdConfirmationFixtures;
use Tests\Support\DynamicConfirmationDatabaseTestCase;

final class NiumLegacyReservationProviderCeilingTest extends DynamicConfirmationDatabaseTestCase
{
    use CreatesDynamicConfirmationFixtures;
    use CreatesFinalizedUsdConfirmationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config(['wallet.ledger.enabled' => true]);
        $this->createDynamicFixtures('3000');
        $this->wallet->update(['currency' => 'USD']);
        $this->source->review = $this->usdReview();
    }

    public function test_lower_provider_ceiling_admits_without_rebasing_mixed_wallet_books(): void
    {
        $this->confirmDynamic(); // Real Dynamic history and an existing USD 1000 hold.
        $this->providerAvailable('2500');
        $transfer = $this->legacyTransfer();

        $entry = app(LedgerService::class)->reserveTransfer($transfer);

        $this->books('1500', '1500', '3000');
        $this->assertSame('transfer:'.$transfer->id.':hold', $entry->reference);
        $this->assertSame('-500.00000000', $entry->amount);
        $this->assertSame('1500.00000000', $entry->balance_after);
        $this->assertSame($this->wallet->id, $entry->balance_id);
        $this->assertSame($this->wallet->id, $transfer->fresh()->raw_data['wallet']['balance_id']);
        $this->assertSame('500.00000000', $transfer->fresh()->raw_data['wallet']['held_amount']);
        $this->assertSame('2500', $this->wallet->fresh()->raw_data['provider_available_balance']);
        $this->assertSame(1, $this->legacyJournalCount($transfer));
        $this->assertDynamicHoldIntact();
    }

    public function test_lower_provider_ceiling_also_preserves_books_without_dynamic_history(): void
    {
        $this->providerAvailable('2500');

        app(LedgerService::class)->reserveTransfer($this->legacyTransfer());

        $this->books('2500', '500', '3000');
        $this->assertSame(1, LedgerEntry::query()->count());
    }

    public function test_provider_ceiling_equal_to_debit_is_sufficient_after_existing_holds(): void
    {
        $this->confirmDynamic();
        $this->providerAvailable('1500');

        app(LedgerService::class)->reserveTransfer($this->legacyTransfer());

        $this->books('1500', '1500', '3000');
        $this->assertDynamicHoldIntact();
    }

    public function test_provider_ceiling_one_decimal_unit_below_debit_rejects_without_mutation(): void
    {
        $this->confirmDynamic();
        $this->providerAvailable('1499.99999999'); // Spendable = 499.99999999, not 500.

        $this->assertReservationRejected($this->legacyTransfer(), 'Insufficient available balance for this transfer.');
        $this->books('2000', '1000', '3000');
        $this->assertDynamicHoldIntact();
    }

    public function test_high_provider_ceiling_cannot_authorize_insufficient_origin_funds(): void
    {
        $this->confirmDynamic();
        $this->providerAvailable('10000');

        $this->assertReservationRejected($this->legacyTransfer('2000.00000001'), 'Insufficient available balance for this transfer.');
        $this->books('2000', '1000', '3000');
        $this->assertDynamicHoldIntact();
    }

    public static function missingProviderEvidence(): array
    {
        return ['missing' => [null], 'invalid' => ['unknown']];
    }

    #[DataProvider('missingProviderEvidence')]
    public function test_unknown_provider_ceiling_cannot_reserve(?string $observation): void
    {
        $this->confirmDynamic();
        $this->providerAvailable($observation);

        $this->assertReservationRejected($this->legacyTransfer(),
            'Nium provider available balance is required before reserving this transfer.');
        $this->books('2000', '1000', '3000');
    }

    public function test_reserve_then_cancel_releases_only_legacy_hold_exactly_once(): void
    {
        $this->confirmDynamic();
        $this->providerAvailable('2500');
        $transfer = $this->legacyTransfer();
        $ledger = app(LedgerService::class);
        $ledger->reserveTransfer($transfer);
        $this->books('1500', '1500', '3000');

        $transfer->update(['status' => 'cancelled']);
        $ledger->applyTransferTerminalStatus($transfer->fresh());
        $ledger->applyTransferTerminalStatus($transfer->fresh());

        $this->books('2000', '1000', '3000');
        $release = LedgerEntry::query()->where('reference', 'transfer:'.$transfer->id.':release')->sole();
        $this->assertSame('500.00000000', $release->amount);
        $this->assertSame('2000.00000000', $release->balance_after);
        $this->assertSame(2, $this->legacyJournalCount($transfer));
        $this->assertDynamicHoldIntact();
    }

    public function test_reserve_then_settle_consumes_only_legacy_hold_exactly_once(): void
    {
        $this->confirmDynamic();
        $this->providerAvailable('2500');
        $transfer = $this->legacyTransfer();
        $ledger = app(LedgerService::class);
        $ledger->reserveTransfer($transfer);
        $this->books('1500', '1500', '3000');

        // Exercise the ledger primitive only; this is not provider settlement evidence.
        $transfer->update(['status' => 'completed']);
        $ledger->applyTransferTerminalStatus($transfer->fresh());
        $ledger->applyTransferTerminalStatus($transfer->fresh());

        $this->books('1500', '1000', '2500');
        $debit = LedgerEntry::query()->where('reference', 'transfer:'.$transfer->id.':debit')->sole();
        $this->assertSame('-500.00000000', $debit->amount);
        $this->assertSame('2500.00000000', $debit->balance_after);
        $this->assertSame(2, $this->legacyJournalCount($transfer));
        $this->assertDynamicHoldIntact();
    }

    public function test_inconsistent_dynamic_history_books_fail_closed_without_repair(): void
    {
        $this->confirmDynamic();
        $this->wallet->update(['ledger_balance' => '2999.99999999']);

        $this->assertReservationRejected($this->legacyTransfer(), 'Dynamic-history wallet book balances are inconsistent.');
        $this->assertSame('2999.99999999', $this->wallet->fresh()->ledger_balance);
        $this->assertDynamicHoldIntact();
    }

    public function test_inconsistent_books_are_rejected_after_dynamic_hold_was_released(): void
    {
        $intent = $this->confirmDynamic();
        $this->confirmation->cancel($this->owner, $intent->id);
        $this->wallet->update(['available_balance' => '2999.99999999']);

        $this->assertReservationRejected($this->legacyTransfer(), 'Dynamic-history wallet book balances are inconsistent.');

        $this->assertSame('0.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame('2999.99999999', $this->wallet->fresh()->available_balance);
        $this->assertSame('released', NiumDynamicReservation::query()->sole()->state);
    }

    public function test_duplicate_reservation_returns_original_journal_without_holding_again(): void
    {
        $this->confirmDynamic();
        $this->providerAvailable('2500');
        $transfer = $this->legacyTransfer();
        $ledger = app(LedgerService::class);
        $entry = $ledger->reserveTransfer($transfer);
        $this->providerAvailable('0'); // Later observation cannot turn a retry into another hold.
        $before = $this->wallet->fresh()->getAttributes();
        $transferBefore = $transfer->fresh()->getAttributes();

        $duplicate = $ledger->reserveTransfer($transfer->fresh());

        $this->assertSame($entry->id, $duplicate->id);
        $this->assertSame($entry->reference, $duplicate->reference);
        $this->assertSame($before, $this->wallet->fresh()->getAttributes());
        $this->assertSame($transferBefore, $transfer->fresh()->getAttributes());
        $this->assertSame(1, $this->legacyJournalCount($transfer));
        $this->books('1500', '1500', '3000');
        $this->assertDynamicHoldIntact();
    }

    public static function invalidWalletBindings(): array
    {
        return ['owner' => ['owner'], 'provider' => ['provider'], 'currency' => ['currency'], 'wallet' => ['wallet']];
    }

    #[DataProvider('invalidWalletBindings')]
    public function test_pinned_balance_cannot_bypass_wallet_ownership_or_binding(string $mismatch): void
    {
        $this->confirmDynamic();
        $attributes = match ($mismatch) {
            'owner' => [
                'user_id' => User::factory()->create()->id,
                'external_account_id' => 'foreign-owner-wallet',
            ],
            'provider' => ['provider_id' => IntegrationProvider::query()->create([
                'code' => 'foreign-provider', 'name' => 'Foreign fixture', 'status' => 'active',
            ])->id],
            'currency' => ['currency' => 'EUR'],
            'wallet' => ['external_account_id' => 'foreign-wallet'],
        };
        $foreignBalance = Balance::query()->create(array_replace([
            'user_id' => $this->owner->id, 'provider_id' => $this->account->provider_id,
            'external_account_id' => 'fixture-wallet', 'currency' => 'USD',
            'available_balance' => '3000', 'reserved_balance' => '0', 'ledger_balance' => '3000',
            'as_of' => now(), 'raw_data' => ['provider_available_balance' => '3000'],
        ], $attributes));
        if ($mismatch === 'owner') {
            // Wallet identity is unique across owners. Match the account binding
            // so only the balance owner prevents reservation of this pinned row.
            $this->account->update(['external_account_id' => $foreignBalance->external_account_id]);
            $this->assertNotSame($this->owner->id, $foreignBalance->user_id);
            $this->assertSame($this->account->provider_id, $foreignBalance->provider_id);
            $this->assertSame('USD', $foreignBalance->currency);
            $this->assertSame($this->account->fresh()->external_account_id, $foreignBalance->external_account_id);
        }
        $before = $foreignBalance->fresh()->getAttributes();
        $transfer = $this->legacyTransfer();
        $transfer->update(['raw_data' => ['wallet' => ['balance_id' => $foreignBalance->id]]]);

        $this->assertReservationRejected($transfer, 'A synced wallet balance is required before submitting this transfer.');

        $this->assertSame($before, $foreignBalance->fresh()->getAttributes());
        $this->books('2000', '1000', '3000');
    }

    public function test_quote_bound_to_previous_account_wallet_cannot_reserve_after_rebinding(): void
    {
        $this->confirmDynamic();
        $transfer = $this->legacyTransfer();
        $quote = FxQuote::query()->create([
            'user_id' => $this->owner->id, 'provider_id' => $this->account->provider_id,
            'quote_ref' => 'fixture-legacy-binding', 'source_currency' => 'USD', 'target_currency' => 'SGD',
            'source_amount' => '500', 'target_amount' => '650',
            'raw_data' => ['provider_context' => ['wallet' => 'fixture-wallet']],
        ]);
        $transfer->update(['fx_quote_id' => $quote->id, 'raw_data' => ['wallet' => ['balance_id' => $this->wallet->id]]]);
        $this->account->update(['external_account_id' => 'rebound-wallet']);

        $this->assertReservationRejected($transfer, 'Nium reservation wallet binding does not match the provider account.');
        $this->books('2000', '1000', '3000');
    }

    public function test_hk_swift_ledger_lifecycle_does_not_gain_sgd_provider_ceiling(): void
    {
        $this->confirmDynamic();
        $this->providerAvailable('1');
        $transfer = $this->legacyTransfer(targetCurrency: 'USD');
        $ledger = app(LedgerService::class);

        $ledger->reserveTransfer($transfer);
        $this->books('1500', '1500', '3000');
        $transfer->update(['status' => 'completed']);
        $ledger->applyTransferTerminalStatus($transfer->fresh());
        $ledger->applyTransferTerminalStatus($transfer->fresh());

        $this->books('1500', '1000', '2500');
        $this->assertSame(2, $this->legacyJournalCount($transfer));
        $this->assertDynamicHoldIntact();
    }

    public function test_successful_reservation_does_not_authorize_sgd_dispatch(): void
    {
        $this->confirmDynamic();
        $this->providerAvailable('2500');
        $transfer = $this->legacyTransfer();
        app(LedgerService::class)->reserveTransfer($transfer);
        $transfer->update(['status' => 'approved']);
        config(['wallet.transfer_controls.require_admin_approval' => false]);
        $before = $this->wallet->fresh()->getAttributes();
        $transferBefore = $transfer->fresh()->getAttributes();

        try {
            app(TransferApprovalService::class)->ensureApprovedForSubmission($transfer->fresh());
            $this->fail('A ledger hold must not authorize SGD dispatch.');
        } catch (RuntimeException $exception) {
            $this->assertSame(NiumSgdAdmissionFence::DISPATCH_HOLD, $exception->getMessage());
        }

        $this->assertSame($before, $this->wallet->fresh()->getAttributes());
        $this->assertSame($transferBefore, $transfer->fresh()->getAttributes());
        $this->assertSame(1, $this->legacyJournalCount($transfer));
        $this->assertDynamicHoldIntact();
    }

    private function legacyTransfer(string $amount = '500', string $targetCurrency = 'SGD'): Transfer
    {
        $beneficiary = Beneficiary::query()->create([
            'user_id' => $this->owner->id, 'provider_id' => $this->account->provider_id,
            'external_beneficiary_id' => 'fixture-legacy-payee', 'beneficiary_type' => 'individual',
            'full_name' => 'Legacy fixture', 'country_code' => $targetCurrency === 'USD' ? 'HK' : 'SG',
            'currency' => $targetCurrency, 'payout_method' => $targetCurrency === 'USD' ? 'SWIFT' : 'LOCAL',
            'account_number' => 'FIXTURE-LEGACY-ACCOUNT', 'status' => 'active',
        ]);

        return Transfer::query()->create([
            'transfer_no' => 'FIXTURE-LEGACY-CEILING', 'user_id' => $this->owner->id,
            'provider_id' => $this->account->provider_id, 'beneficiary_id' => $beneficiary->id,
            'transfer_type' => 'payout', 'source_currency' => 'USD', 'target_currency' => $targetCurrency,
            'source_amount' => $amount, 'status' => 'draft',
        ]);
    }

    private function providerAvailable(?string $amount): void
    {
        $raw = $this->wallet->fresh()->raw_data;
        if ($amount === null) {
            unset($raw['provider_available_balance']);
        } else {
            $raw['provider_available_balance'] = $amount;
        }
        $this->wallet->update(['raw_data' => $raw]);
    }

    private function books(string $available, string $reserved, string $ledger): void
    {
        $wallet = $this->wallet->fresh();
        $this->assertSame(bcadd($available, '0', 8), $wallet->available_balance);
        $this->assertSame(bcadd($reserved, '0', 8), $wallet->reserved_balance);
        $this->assertSame(bcadd($ledger, '0', 8), $wallet->ledger_balance);
        $this->assertSame($wallet->ledger_balance, bcadd($wallet->available_balance, $wallet->reserved_balance, 8));
    }

    private function assertDynamicHoldIntact(): void
    {
        $reservation = NiumDynamicReservation::query()->sole();
        $this->assertSame('held', $reservation->state);
        $this->assertSame('1000.00000000', $reservation->amount);
        $this->assertNull($reservation->released_at);
        $this->assertNull($reservation->release_entry_id);
        $this->assertNull($reservation->debit_entry_id);
        $this->assertSame(1, LedgerEntry::query()->where('source_type', 'nium_dynamic_reservation')->count());
    }

    private function legacyJournalCount(Transfer $transfer): int
    {
        return LedgerEntry::query()->where('source_type', 'transfer')->where('source_id', (string) $transfer->id)->count();
    }

    private function assertReservationRejected(Transfer $transfer, string $message): void
    {
        $before = $this->wallet->fresh()->getAttributes();
        $transferBefore = $transfer->fresh()->getAttributes();
        $journalCount = LedgerEntry::query()->count();

        try {
            app(LedgerService::class)->reserveTransfer($transfer);
            $this->fail('Reservation must fail before mutating books or journals.');
        } catch (RuntimeException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }

        $this->assertSame($before, $this->wallet->fresh()->getAttributes());
        $this->assertSame($transferBefore, $transfer->fresh()->getAttributes());
        $this->assertSame($journalCount, LedgerEntry::query()->count());
        $this->assertSame(0, $this->legacyJournalCount($transfer));
    }
}
