<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Services\Nium\NiumCustomerOnboardingService;
use App\Services\Nium\NiumDataSyncService;
use App\Services\Nium\NiumService;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Mockery;

/** Synthetic observation at the service port; never sends an HTTP request. */
trait CreatesNiumBalanceSyncObservation
{
    protected function balanceSyncObservation(string $available, ?\Closure $duringRead = null): NiumDataSyncService
    {
        config(['services.nium.wallet_balance_endpoint' => '/fixture-balances']);
        $nium = Mockery::mock(NiumService::class)->makePartial();
        $nium->shouldReceive('clientId')->andReturn('fixture-client');
        $nium->shouldReceive('customerId')->andReturn('fixture-customer');
        $nium->shouldReceive('walletId')->andReturn('fixture-wallet');
        $nium->shouldReceive('get')->once()->andReturnUsing(function () use ($available, $duringRead): Response {
            $duringRead?->__invoke();
            return new Response(new PsrResponse(200, [], json_encode(['balances' => [[
                'walletHashId' => 'fixture-wallet', 'currency' => $this->wallet->currency,
                'availableBalance' => $available, 'ledgerBalance' => $available,
            ]]], JSON_THROW_ON_ERROR)));
        });
        return new NiumDataSyncService($nium, Mockery::mock(NiumCustomerOnboardingService::class));
    }

    protected function observeNiumBalance(string $available, ?\Closure $duringRead = null): void
    {
        $result = $this->balanceSyncObservation($available, $duringRead)
            ->syncBalances($this->account->provider, $this->owner);
        $this->assertSame(['synced_balances' => 1], $result);
    }
}
