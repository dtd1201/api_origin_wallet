<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Services\Nium\NiumWebhookService;
use Illuminate\Database\Connection;
use ReflectionMethod;
use Tests\Concerns\CreatesDynamicConfirmationFixtures;
use Tests\Concerns\CreatesFinalizedUsdConfirmationFixtures;
use Tests\Concerns\CreatesNiumBalanceSyncObservation;

abstract class NiumOwnerFirstTestCase extends DynamicConfirmationDatabaseTestCase
{
    use CreatesDynamicConfirmationFixtures;
    use CreatesFinalizedUsdConfirmationFixtures;
    use CreatesNiumBalanceSyncObservation;

    protected const OPENING = '3000.12345678';
    protected const LEGACY = '10.00000001';

    protected function setUp(): void
    {
        parent::setUp();
        config(['wallet.ledger.enabled' => true, 'wallet.transfer_controls.require_admin_approval' => false,
            'services.nium.base_url' => 'https://gatewaysandbox.nium.com', 'services.nium.client_id' => 'fixture-client',
            'services.nium.payout_fx_enabled' => false]);
        $this->createDynamicFixtures(self::OPENING);
        $this->wallet->update(['currency' => 'USD']);
        $this->source->review = $this->usdReview();
    }

    protected function legacyTransfer(string $suffix = 'one'): Transfer
    {
        return Transfer::query()->create([
            'transfer_no' => 'PATCH268C-'.$suffix, 'user_id' => $this->owner->id,
            'provider_id' => $this->account->provider_id, 'beneficiary_id' => $this->beneficiary->id,
            'transfer_type' => 'payout', 'source_currency' => 'USD', 'target_currency' => 'USD',
            'source_amount' => self::LEGACY, 'fee_amount' => '0', 'fee_currency' => 'USD',
            'purpose_code' => 'IR01811', 'status' => 'draft',
        ]);
    }

    protected function funding(string $total): void
    {
        (new ReflectionMethod(NiumWebhookService::class, 'processFundingWebhook'))->invoke(
            app(NiumWebhookService::class), $this->account->provider,
            ['walletHashId' => 'fixture-wallet', 'transactionCurrency' => 'USD', 'walletBalance' => $total],
        );
    }

    protected function books(string $available, string $reserved, string $ledger): void
    {
        $wallet = $this->wallet->fresh();
        $this->assertSame(bcadd($available, '0', 8), $wallet->available_balance);
        $this->assertSame(bcadd($reserved, '0', 8), $wallet->reserved_balance);
        $this->assertSame(bcadd($ledger, '0', 8), $wallet->ledger_balance);
        $this->assertSame($wallet->ledger_balance, bcadd($wallet->available_balance, $wallet->reserved_balance, 8));
        foreach (LedgerEntry::query()->get() as $entry) {
            $this->assertSame((string) $this->owner->id, (string) $entry->user_id);
            $this->assertSame((string) $this->account->provider_id, (string) $entry->provider_id);
            $this->assertSame((string) $wallet->id, (string) $entry->balance_id);
            $this->assertSame('USD', $entry->currency);
        }
        $this->assertSame(LedgerEntry::query()->count(), LedgerEntry::query()->distinct()->count('reference'));
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'credit')->count());
    }

    protected function orderedRace(\Closure $left, \Closure $right, string $table = 'balances'): array
    {
        return (new PostgresLockRace)->run(
            function (PostgresLockRace $race) use ($left, $table): mixed {
                $race->pauseAfterLock($table);
                return $left();
            },
            function (PostgresLockRace $race) use ($right): mixed {
                $race->await('locked');
                return $right();
            },
            function (PostgresLockRace $race, Connection $observer, array $pids): void {
                $race->await('locked');
                $wait = $race->waitForBlock($observer, $pids[1], $pids[0]);
                $this->assertStringContainsString('users', $wait['query']);
                $race->signal('continue');
            },
        );
    }

    protected function assertRaceSucceeded(array $race): void
    {
        $this->assertSame(['ok', 'ok'], array_column($race['workers'], 'status'), json_encode($race));
        $this->assertCount(3, array_unique($race['pids']));
        foreach ($race['workers'] as $result) {
            $this->assertSame(0, $result['depth']);
            $this->assertFalse($result['pdo_transaction']);
        }
        foreach ($race['sql'] as $queries) {
            $this->assertSame([], array_values(array_filter(array_column($queries, 'sqlstate'))), json_encode($queries));
        }
    }
}
