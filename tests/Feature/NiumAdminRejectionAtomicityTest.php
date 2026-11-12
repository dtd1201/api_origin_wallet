<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Balance;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\TransferApproval;
use App\Models\User;
use App\Services\Wallet\LedgerService;
use App\Services\Wallet\TransferApprovalService;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\NiumOwnerFirstTestCase;

final class NiumAdminRejectionAtomicityTest extends NiumOwnerFirstTestCase
{
    public function test_rejection_releases_only_its_hold_and_duplicate_keeps_existing_response(): void
    {
        $transfer = $this->legacyTransfer();
        $other = $this->legacyTransfer('other');
        $admin = User::factory()->create();
        $ledger = app(LedgerService::class);
        $ledger->reserveTransfer($transfer);
        $ledger->reserveTransfer($other);
        app(TransferApprovalService::class)->reject($transfer, $admin, 'Fixture rejection');
        $this->assertSame('rejected', $transfer->fresh()->status);
        $this->assertSame(1, TransferApproval::query()->where('action', 'rejected')->count());
        $this->books(bcsub(self::OPENING, self::LEGACY, 8), self::LEGACY, self::OPENING);
        try {
            app(TransferApprovalService::class)->reject($transfer->fresh(), $admin);
            $this->fail('Duplicate rejection changed the existing response.');
        } catch (RuntimeException $exception) {
            $this->assertSame('This transfer cannot be rejected in its current status.', $exception->getMessage());
        }
        $this->assertSame(1, TransferApproval::query()->count());
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'release')->count());
    }

    public static function failedStages(): array
    {
        return ['receipt' => ['receipt'], 'status' => ['status'], 'balance' => ['balance'], 'journal' => ['journal']];
    }

    #[DataProvider('failedStages')]
    public function test_a_failed_rejection_stage_rolls_back_receipt_status_release_and_balance(string $stage): void
    {
        $transfer = $this->legacyTransfer();
        $admin = User::factory()->create();
        app(LedgerService::class)->reserveTransfer($transfer);
        // Event vetoes reproduce Eloquent returning false, not just thrown SQL exceptions.
        if ($stage === 'receipt') TransferApproval::creating(fn ($row) => $row->action === 'rejected' ? false : null);
        if ($stage === 'status') Transfer::updating(fn ($row) => $row->status === 'rejected' ? false : null);
        if ($stage === 'balance') Balance::updating(fn () => false);
        if ($stage === 'journal') LedgerEntry::creating(fn ($row) => $row->entry_type === 'release' ? false : null);
        try {
            app(TransferApprovalService::class)->reject($transfer, $admin);
            $this->fail('A failed financial stage was reported as rejection success.');
        } catch (RuntimeException) {
            $this->assertSame('draft', $transfer->fresh()->status);
            $this->assertSame(0, TransferApproval::query()->count());
            $this->assertSame(1, LedgerEntry::query()->count());
            $this->books(bcsub(self::OPENING, self::LEGACY, 8), self::LEGACY, self::OPENING);
        }
    }

    public function test_never_held_and_disabled_ledger_behavior_cannot_orphan_a_persisted_hold(): void
    {
        $admin = User::factory()->create();
        $neverHeld = $this->legacyTransfer('never');
        app(TransferApprovalService::class)->reject($neverHeld, $admin);
        $this->assertSame(0, LedgerEntry::query()->count());
        $held = $this->legacyTransfer('held');
        app(LedgerService::class)->reserveTransfer($held);
        config(['wallet.ledger.enabled' => false]);
        try {
            app(TransferApprovalService::class)->reject($held, $admin);
            $this->fail('Disabled ledger orphaned an existing hold.');
        } catch (RuntimeException) {
            $this->assertSame('draft', $held->fresh()->status);
            $this->assertSame(1, TransferApproval::query()->count());
            $this->books(bcsub(self::OPENING, self::LEGACY, 8), self::LEGACY, self::OPENING);
        }
    }

    public function test_submission_claim_blocks_rejection_and_release(): void
    {
        $transfer = $this->legacyTransfer();
        app(LedgerService::class)->reserveTransfer($transfer);
        $transfer->update(['provider_operation_key' => 'fixture-committed-claim']);
        try {
            app(TransferApprovalService::class)->reject($transfer, User::factory()->create());
            $this->fail('A claimed transfer was rejected.');
        } catch (RuntimeException) {
            $this->assertSame(0, TransferApproval::query()->count());
            $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'release')->count());
        }
    }

    public static function winnerOrders(): array
    {
        return ['reject first' => [true], 'dynamic first' => [false]];
    }

    #[DataProvider('winnerOrders')]
    public function test_rejection_and_dynamic_reserve_are_atomic_under_real_contention(bool $rejectFirst): void
    {
        $transfer = $this->legacyTransfer();
        $admin = User::factory()->create();
        app(LedgerService::class)->reserveTransfer($transfer);
        $reject = fn () => app(TransferApprovalService::class)->reject($transfer, $admin)->id;
        $dynamic = fn () => $this->confirmDynamic()->id;
        $race = $this->orderedRace($rejectFirst ? $reject : $dynamic, $rejectFirst ? $dynamic : $reject);
        $this->assertRaceSucceeded($race);
        $this->books(bcsub(self::OPENING, '1000', 8), '1000', self::OPENING);
        $this->assertSame('rejected', $transfer->fresh()->status);
        $this->assertSame(1, TransferApproval::query()->count());
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'release')->count());
    }

    public function test_reciprocal_customer_approver_operations_lock_both_users_in_same_order(): void
    {
        $admin = User::factory()->create();
        $first = $this->legacyTransfer('first');
        $second = $this->legacyTransfer('second');
        $second->update(['user_id' => $admin->id]);
        $race = $this->orderedRace(
            fn () => app(TransferApprovalService::class)->reject($first, $admin)->id,
            fn () => app(TransferApprovalService::class)->reject($second, $this->owner)->id,
            'transfers',
        );
        $this->assertRaceSucceeded($race);
        $this->assertSame(2, TransferApproval::query()->where('action', 'rejected')->count());
        $this->assertSame(0, LedgerEntry::query()->count());
    }
}
