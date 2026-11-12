<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Wallet\Concurrency\OwnerFirst;
use App\Models\Balance;
use App\Models\IntegrationProvider;
use App\Models\LedgerEntry;
use App\Models\NiumDynamicReservation;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Wallet\LedgerService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesDynamicConfirmationFixtures;
use Tests\Concerns\CreatesFinalizedUsdConfirmationFixtures;
use Tests\Support\DynamicConfirmationDatabaseTestCase;

final class NiumLegacyHoldLifecycleTest extends DynamicConfirmationDatabaseTestCase
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
        $this->confirmDynamic(); // Real USD 1000 Dynamic hold, never provider evidence.
    }

    public function test_released_hold_cannot_be_reused_or_settled_again(): void
    {
        $transfer = $this->transfer();
        $ledger = app(LedgerService::class);
        $hold = $ledger->reserveTransfer($transfer);
        $release = $ledger->releaseTransferHold($transfer);
        $this->assertSame($release->id, $ledger->releaseTransferHold($transfer)->id);
        $before = $this->wallet->fresh()->getAttributes();

        $this->reject(fn () => $ledger->reserveTransfer($transfer));
        $this->reject(fn () => $ledger->settleTransfer($transfer));

        $this->assertSame($before, $this->wallet->fresh()->getAttributes());
        $this->assertSame($hold->id, LedgerEntry::query()->where('reference', $hold->reference)->sole()->id);
        $this->assertSame(2, $this->journalCount($transfer));
        $this->books('2000', '1000', '3000');
    }

    public function test_debited_hold_cannot_be_reserved_or_release_dynamic_funds(): void
    {
        $transfer = $this->transfer();
        $ledger = app(LedgerService::class);
        $ledger->reserveTransfer($transfer);
        $debit = $ledger->settleTransfer($transfer);
        $this->assertSame($debit->id, $ledger->settleTransfer($transfer)->id);

        $this->reject(fn () => $ledger->releaseTransferHold($transfer));
        $this->reject(fn () => $ledger->reserveTransfer($transfer));

        $this->books('1500', '1000', '2500');
        $this->assertSame(2, $this->journalCount($transfer));
    }

    public function test_duplicate_active_hold_is_validated_and_does_not_grant_cleanup_authority(): void
    {
        $transfer = $this->transfer();
        $ledger = app(LedgerService::class);
        $created = $ledger->reserveTransfer($transfer);
        $duplicate = $ledger->reserveTransfer($transfer);

        $this->assertTrue($created->wasRecentlyCreated);
        $this->assertFalse($duplicate->wasRecentlyCreated);
        $this->assertSame($created->id, $duplicate->id);
        $this->assertNull($ledger->releaseOwnedTransferHoldBeforeWire($transfer, $duplicate));
        $this->assertSame(1, $this->journalCount($transfer));
        $this->books('1500', '1500', '3000');
    }

    public function test_settlement_without_own_hold_cannot_consume_dynamic_reserved(): void
    {
        $transfer = $this->transfer();
        $before = $this->wallet->fresh()->getAttributes();

        $this->reject(fn () => app(LedgerService::class)->settleTransfer($transfer));

        $this->assertSame($before, $this->wallet->fresh()->getAttributes());
        $this->assertSame(0, $this->journalCount($transfer));
        $this->books('2000', '1000', '3000');
    }

    public function test_never_reserved_release_is_a_noop_and_fabricated_receipt_is_rejected(): void
    {
        $transfer = $this->transfer();
        $ledger = app(LedgerService::class);
        $this->assertNull($ledger->releaseTransferHold($transfer));
        $transfer->update(['raw_data' => ['wallet' => [
            'balance_id' => $this->wallet->id, 'hold_reference' => 'transfer:'.$transfer->id.':hold',
            'held_amount' => '500.00000000',
        ]]]);

        $this->reject(fn () => $ledger->releaseTransferHold($transfer));
        $this->reject(fn () => $ledger->reserveTransfer($transfer));
        $this->assertSame(0, $this->journalCount($transfer));
        $this->books('2000', '1000', '3000');
    }

    public static function changedFinancialIdentity(): array
    {
        return array_combine(
            ['owner', 'provider', 'currency', 'amount', 'amount_and_receipt', 'pinned_balance', 'wallet'],
            array_map(fn ($field) => [$field], ['owner', 'provider', 'currency', 'amount', 'amount_and_receipt', 'pinned_balance', 'wallet']),
        );
    }

    #[DataProvider('changedFinancialIdentity')]
    public function test_changed_financial_identity_cannot_reuse_settle_or_release_hold(string $field): void
    {
        $transfer = $this->transfer();
        $ledger = app(LedgerService::class);
        $ledger->reserveTransfer($transfer);
        match ($field) {
            'owner' => $transfer->update(['user_id' => User::factory()->create()->id]),
            'provider' => $transfer->update(['provider_id' => IntegrationProvider::query()->create([
                'code' => 'foreign-fixture', 'name' => 'Foreign fixture', 'status' => 'active',
            ])->id]),
            'currency' => $transfer->update(['source_currency' => 'EUR']),
            'amount' => $transfer->update(['source_amount' => '1500']),
            'amount_and_receipt' => $transfer->update(['source_amount' => '1500', 'raw_data' => ['wallet' => array_replace(
                $transfer->fresh()->raw_data['wallet'], ['held_amount' => '1500.00000000'],
            )]]),
            'pinned_balance' => $transfer->update(['raw_data' => ['wallet' => array_replace(
                $transfer->fresh()->raw_data['wallet'], ['balance_id' => $this->foreignBalance()->id],
            )]]),
            'wallet' => $this->wallet->update(['external_account_id' => 'changed-wallet']),
        };
        $before = $this->wallet->fresh()->getAttributes();
        $journals = LedgerEntry::query()->orderBy('id')->get()->toArray();

        $this->reject(fn () => $ledger->reserveTransfer($transfer));
        $this->reject(fn () => $ledger->settleTransfer($transfer));
        $this->reject(fn () => $ledger->releaseTransferHold($transfer));

        $this->assertSame($before, $this->wallet->fresh()->getAttributes());
        $this->assertSame($journals, LedgerEntry::query()->orderBy('id')->get()->toArray());
        $this->assertSame(1, $this->journalCount($transfer));
    }

    public function test_new_hk_hold_cannot_reserve_a_pinned_foreign_wallet(): void
    {
        $transfer = $this->transfer();
        $foreign = $this->foreignBalance();
        $transfer->update(['raw_data' => ['wallet' => ['balance_id' => $foreign->id]]]);
        $before = $foreign->fresh()->getAttributes();

        $this->reject(fn () => app(LedgerService::class)->reserveTransfer($transfer));

        $this->assertSame($before, $foreign->fresh()->getAttributes());
        $this->assertSame(0, $this->journalCount($transfer));
        $this->books('2000', '1000', '3000');
    }

    public function test_short_reserved_balance_is_not_partially_released(): void
    {
        $transfer = $this->transfer();
        $ledger = app(LedgerService::class);
        $ledger->reserveTransfer($transfer);
        // Consistent arithmetic is insufficient proof of reservation ownership.
        $this->wallet->update(['reserved_balance' => '499', 'available_balance' => '2501']);
        $before = $this->wallet->fresh()->getAttributes();

        $this->reject(fn () => $ledger->releaseTransferHold($transfer));
        $this->reject(fn () => $ledger->settleTransfer($transfer));

        $this->assertSame($before, $this->wallet->fresh()->getAttributes());
        $this->assertSame(1, $this->journalCount($transfer));
    }

    public static function invalidJournalEvidence(): array
    {
        return ['unposted' => ['unposted'], 'duplicate' => ['duplicate'], 'conflicting terminals' => ['conflict']];
    }

    #[DataProvider('invalidJournalEvidence')]
    public function test_missing_or_contradictory_journal_evidence_fails_closed(string $case): void
    {
        $transfer = $this->transfer();
        $ledger = app(LedgerService::class);
        $hold = $ledger->reserveTransfer($transfer);
        if ($case === 'unposted') {
            $hold->update(['status' => 'pending']); // Historical corruption fixture only.
        } else {
            $events = $case === 'duplicate' ? ['hold'] : ['debit', 'release'];
            foreach ($events as $event) {
                LedgerEntry::query()->create(array_replace($hold->only([
                    'balance_id', 'user_id', 'provider_id', 'source_type', 'source_id', 'currency', 'balance_after',
                ]), [
                    'reference' => $case === 'duplicate' ? 'duplicate-fixture-hold' : 'transfer:'.$transfer->id.':'.$event,
                    'entry_type' => $event, 'status' => 'posted', 'amount' => $event === 'release' ? '500' : '-500',
                ]));
            }
        }
        $before = $this->wallet->fresh()->getAttributes();
        $count = $this->journalCount($transfer);

        $this->reject(fn () => $ledger->reserveTransfer($transfer));
        $this->reject(fn () => $ledger->settleTransfer($transfer));
        $this->reject(fn () => $ledger->releaseTransferHold($transfer));

        $this->assertSame($before, $this->wallet->fresh()->getAttributes());
        $this->assertSame($count, $this->journalCount($transfer));
    }

    public function test_inconsistent_books_cannot_be_settled_or_released_without_repair(): void
    {
        $transfer = $this->transfer();
        $ledger = app(LedgerService::class);
        $ledger->reserveTransfer($transfer);
        $this->wallet->update(['ledger_balance' => '2999.99999999']);
        $before = $this->wallet->fresh()->getAttributes();

        $this->reject(fn () => $ledger->reserveTransfer($transfer));
        $this->reject(fn () => $ledger->settleTransfer($transfer));
        $this->reject(fn () => $ledger->releaseTransferHold($transfer));

        $this->assertSame($before, $this->wallet->fresh()->getAttributes());
        $this->assertSame(1, $this->journalCount($transfer));
    }

    public function test_cleanup_creator_is_fenced_after_another_request_begins_submission(): void
    {
        $transfer = $this->transfer();
        $ledger = app(LedgerService::class);
        $created = $ledger->reserveTransfer($transfer);
        $transfer->update(['status' => 'submitting', 'provider_operation_key' => 'competing-claim']);

        $this->assertNull($ledger->releaseOwnedTransferHoldBeforeWire($transfer, $created));
        $this->books('1500', '1500', '3000');
        $this->assertSame(1, $this->journalCount($transfer));
    }

    public function test_submission_wallet_must_match_the_frozen_hold_balance(): void
    {
        $transfer = $this->transfer();
        $ledger = app(LedgerService::class);
        $ledger->reserveTransfer($transfer);

        $this->reject(fn () => OwnerFirst::transfer($transfer, fn () => $ledger->assertTransferReserved($transfer, 'other-provider-wallet')));
        $this->books('1500', '1500', '3000');
    }

    public function test_exact_eight_place_reservation_release_round_trip(): void
    {
        $transfer = $this->transfer('0.00000001');
        $ledger = app(LedgerService::class);
        $ledger->reserveTransfer($transfer);
        $this->books('1999.99999999', '1000.00000001', '3000');
        $ledger->releaseTransferHold($transfer);
        $this->books('2000', '1000', '3000');
    }

    private function transfer(string $amount = '500'): Transfer
    {
        return Transfer::query()->create([
            'transfer_no' => 'PATCH258-'.bin2hex(random_bytes(8)), 'user_id' => $this->owner->id,
            'provider_id' => $this->account->provider_id, 'beneficiary_id' => $this->beneficiary->id,
            'transfer_type' => 'payout', 'source_currency' => 'USD', 'target_currency' => 'USD',
            'source_amount' => $amount, 'status' => 'draft',
        ]);
    }

    private function foreignBalance(): Balance
    {
        return Balance::query()->create([
            'user_id' => $this->owner->id, 'provider_id' => $this->account->provider_id,
            'external_account_id' => 'foreign-wallet', 'currency' => 'USD',
            'available_balance' => '3000', 'reserved_balance' => '0', 'ledger_balance' => '3000', 'as_of' => now(),
        ]);
    }

    private function reject(callable $operation): void
    {
        try {
            $operation();
        } catch (RuntimeException) {
            return;
        }
        $this->fail('Invalid legacy hold lifecycle must fail closed.');
    }

    private function journalCount(Transfer $transfer): int
    {
        return LedgerEntry::query()->where('source_type', 'transfer')->where('source_id', (string) $transfer->id)->count();
    }

    private function books(string $available, string $reserved, string $ledger): void
    {
        $wallet = $this->wallet->fresh();
        $this->assertSame(bcadd($available, '0', 8), $wallet->available_balance);
        $this->assertSame(bcadd($reserved, '0', 8), $wallet->reserved_balance);
        $this->assertSame(bcadd($ledger, '0', 8), $wallet->ledger_balance);
        $this->assertSame($wallet->ledger_balance, bcadd($wallet->available_balance, $wallet->reserved_balance, 8));
        $this->assertSame('held', NiumDynamicReservation::query()->sole()->state);
        $this->assertSame('1000.00000000', NiumDynamicReservation::query()->sole()->amount);
    }
}
