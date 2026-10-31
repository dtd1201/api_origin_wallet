<?php

namespace Tests\Concerns;

use App\Models\Balance;
use App\Services\Nium\NiumPayoutPilotPolicy;
use App\Services\Nium\NiumSgdLocalPayoutWorkflow;
use App\Services\Wallet\TransferApprovalService;
use Illuminate\Support\Str;

/** Synthetic Transfer 3, an expired unused grant and an explicitly restarted FX review. */
trait CreatesRenewableNiumPilotFixtures
{
    protected function expiredTransferThreePilot(): array
    {
        [$provider, $seed, $checker] = $this->sgdExecutionFixture(false);
        $workflow = app(NiumSgdLocalPayoutWorkflow::class);
        for ($index = 2; $index <= 3; $index++) {
            $transfer = $workflow->prepare($seed->user, [
                'provider_id' => $seed->provider_id, 'beneficiary_id' => $seed->beneficiary_id,
                'source_currency' => 'USD', 'target_currency' => 'SGD', 'source_amount' => '5',
                'purpose_code' => $seed->purpose_code, 'client_reference' => 'RENEWAL-SYNTHETIC-'.$index,
                'expected_fee_amount' => '0', 'expected_fee_currency' => 'USD',
            ]);
        }
        $this->assertSame(3, $transfer->id);
        $transfer = $workflow->requestLock($transfer, Str::uuid()->toString());
        $grant = app(NiumPayoutPilotPolicy::class)->authorizeOneTime($transfer, $checker, 'SYNTHETIC-ORIGINAL-RISK-ACCEPTANCE', true);
        config()->set('services.nium.payout_pilot', ['enabled' => true, 'authorization_id' => $grant->id]);
        $approved = app(TransferApprovalService::class)->approve($workflow->requestApproval($transfer), $checker);
        $oldApproval = $approved->approvals()->sole();
        $this->travel(11)->minutes();
        $draft = $workflow->restartExpiredReview($approved->fresh());
        $fresh = $workflow->requestLock($draft, Str::uuid()->toString());
        // Stand-in for the separately synchronized source-wallet balance, never provider pricing proof.
        Balance::query()->sole()->update(['as_of' => now()]);

        return [$provider, $fresh->fresh(), $checker, $grant->fresh(), $oldApproval];
    }
}
