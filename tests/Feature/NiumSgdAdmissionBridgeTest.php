<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\Transfer;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Nium\NiumSgdAdmissionFence;
use App\Services\Wallet\TransferApprovalService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\CreatesSgdLocalExecutionFixtures;
use Tests\Concerns\RefreshesCommittedPayoutDatabase;
use Tests\TestCase;

/** Also copied into the baseline bridge; compatible with legacy audit fixtures. */
class NiumSgdAdmissionBridgeTest extends TestCase
{
    use CreatesSgdLocalExecutionFixtures, RefreshesCommittedPayoutDatabase;

    public function test_closed_bridge_rejects_already_approved_sgd_without_claim_or_financial_changes(): void
    {
        [$provider, $transfer, $checker] = $this->sgdExecutionFixture();
        $approved = app(TransferApprovalService::class)->approve($this->submitSgd($transfer), $checker);
        file_put_contents($this->sgdFenceDirectory.'/state', "CLOSED v1\n");
        $before = Http::recorded()->count();
        try {
            app(ProviderTransferManager::class)->submitTransfer($provider, $approved);
            $this->fail('A bridge must not submit SGD.');
        } catch (RuntimeException $exception) {
            $this->assertSame(NiumSgdAdmissionFence::CLOSED, $exception->getMessage());
        }
        $this->assertNull($approved->fresh()->provider_operation_key);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame($before, Http::recorded()->count());
    }

    public function test_hk_swift_still_approves_and_submits_with_closed_sgd_fence_and_fx_disabled(): void
    {
        [$provider, $fixture, $checker] = $this->sgdExecutionFixture();
        $beneficiary = Beneficiary::query()->create(['user_id' => $fixture->user_id, 'provider_id' => $provider->id,
            'external_beneficiary_id' => 'synthetic-hk', 'beneficiary_type' => 'business', 'full_name' => 'Synthetic HK',
            'country_code' => 'HK', 'currency' => 'USD', 'payout_method' => 'SWIFT', 'status' => 'active',
            'raw_data' => ['nium' => ['payoutMethod' => 'SWIFT']]]);
        file_put_contents($this->sgdFenceDirectory.'/state', "CLOSED v1\n");
        config()->set('services.nium.payout_fx_enabled', false);
        config()->set('wallet.transfer_controls.require_admin_approval', true);
        config()->set('wallet.transfer_controls.approval_threshold_amount', 0);
        $created = $this->withToken($this->sgdToken($fixture->user))
            ->postJson("/api/user/users/{$fixture->user_id}/transfers", [
                'provider_id' => $provider->id, 'beneficiary_id' => $beneficiary->id, 'source_amount' => '5',
                'purpose_code' => 'SYNTHETIC_PURPOSE', 'reference_text' => 'HK-FENCE-REGRESSION',
                'client_reference' => 'HK-FENCE-REGRESSION', 'transfer_type' => 'payout',
                'source_currency' => 'USD', 'target_currency' => 'USD',
            ])->assertCreated();
        $hk = Transfer::query()->findOrFail($created->json('id'));
        $result = app(TransferApprovalService::class)->approve($hk, $checker, manager: app(ProviderTransferManager::class));
        $this->assertSame('pending', $result->status);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/remittance')
            && $request['payout']['sourceCurrency'] === 'USD' && $request['payout']['destinationCurrency'] === 'USD'
            && ! isset($request['payout']['quoteId']));
    }
}
