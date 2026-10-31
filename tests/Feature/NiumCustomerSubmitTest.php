<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\Balance;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Nium\NiumPayoutPilotPolicy;
use App\Services\Nium\NiumSgdLocalPayoutWorkflow;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesSgdLocalExecutionFixtures;
use Tests\Concerns\RefreshesCommittedPayoutDatabase;
use Tests\TestCase;

class NiumCustomerSubmitTest extends TestCase
{
    use CreatesSgdLocalExecutionFixtures, RefreshesCommittedPayoutDatabase;

    public function test_authenticated_submit_records_exact_consent_then_only_independent_admin_dispatches_once(): void
    {
        [, $transfer, $checker] = $this->sgdExecutionFixture();
        $customer = $this->sgdToken($transfer->user);
        $url = "/api/user/users/12/transfers/{$transfer->id}/sgd-local-submit";
        $payload = $this->reviewToken($transfer);
        $this->withToken($customer)->postJson($url, $payload)->assertOk()
            ->assertJsonPath('status', 'approval_required')
            ->assertJsonPath('payout_workflow.customer_authorization.accepted_by', 12)
            ->assertJsonPath('payout_workflow.customer_authorization.instruction.transfer_id', $transfer->id)
            ->assertJsonPath('payout_workflow.customer_authorization.instruction.fx_quote_id', $transfer->fx_quote_id)
            ->assertJsonPath('payout_workflow.dispatch_owner', 'checker_approval');
        $receipt = $transfer->fresh()->raw_data['sgd_local_workflow']['customer_authorization'];
        $this->withToken($customer)->postJson($url, $payload)->assertOk();
        $this->assertSame($receipt, $transfer->fresh()->raw_data['sgd_local_workflow']['customer_authorization']);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame(0, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
        $this->withToken($customer)->postJson("/api/admin/transfers/{$transfer->id}/approve")->assertForbidden();
        $admin = $this->sgdToken($checker);
        $approve = "/api/admin/transfers/{$transfer->id}/approve";
        $this->withToken($admin)->postJson($approve)->assertOk()->assertJsonPath('transfer.status', 'pending');
        $this->withToken($admin)->postJson($approve)->assertOk();
        $this->assertDatabaseCount('transfer_approvals', 1);
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
    }

    public static function invalidations(): array
    {
        return array_map(fn ($change) => [$change], ['expired_fx', 'stale_funding', 'missing_authorization',
            'changed_terms', 'changed_principal', 'changed_beneficiary', 'changed_receiving_account']);
    }

    #[DataProvider('invalidations')]
    public function test_approval_rejects_changed_or_missing_customer_instruction_without_dispatch(string $change): void
    {
        [, $transfer, $checker] = $this->sgdExecutionFixture();
        $this->submitSgd($transfer);
        $transfer = $transfer->fresh();
        switch ($change) {
            case 'expired_fx': $this->travel(11)->minutes();
                break;
            case 'stale_funding': Balance::query()->update(['as_of' => now()->subMinutes(6)]);
                break;
            case 'missing_authorization':
                $raw = $transfer->raw_data;
                unset($raw['sgd_local_workflow']['customer_authorization']);
                $transfer->update(['raw_data' => $raw]);
                break;
            case 'changed_terms': $this->installSyntheticPricing($transfer, ['pricing_version' => 'CHANGED']);
                break;
            case 'changed_principal': $transfer->update(['source_amount' => '6']);
                break;
            case 'changed_beneficiary':
                $other = $transfer->beneficiary->replicate();
                $other->external_beneficiary_id = 'other-account';
                $other->save();
                $transfer->update(['beneficiary_id' => $other->id]);
                break;
            case 'changed_receiving_account': $transfer->beneficiary->update(['account_number' => 'changed-account']);
                break;
        }
        $projection = app(NiumSgdLocalPayoutWorkflow::class)->presentation($transfer->fresh());
        $this->assertFalse($projection['checker_approval_allowed']);
        $this->withToken($this->sgdToken($checker))->postJson("/api/admin/transfers/{$transfer->id}/approve")->assertUnprocessable();
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame(0, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
    }

    public function test_uncapped_customer_acceptance_is_explicit_and_admin_risk_grant_cannot_replace_it(): void
    {
        [, $transfer, $checker] = $this->sgdExecutionFixture(false);
        $grant = app(NiumPayoutPilotPolicy::class)->authorizeOneTime($transfer, $checker, 'SYNTHETIC-RISK', true);
        config()->set('services.nium.payout_pilot', ['enabled' => true, 'authorization_id' => $grant->id]);
        $payload = $this->reviewToken($transfer);
        $url = "/api/user/users/12/transfers/{$transfer->id}/sgd-local-submit";
        $token = $this->sgdToken($transfer->user);
        $this->withToken($token)->postJson($url, [...$payload, 'accept_uncapped_charges' => false])->assertUnprocessable();
        $this->assertSame('draft', $transfer->fresh()->status);
        $this->assertArrayNotHasKey('customer_authorization', $transfer->fresh()->raw_data['sgd_local_workflow']);
        $this->withToken($token)->postJson($url, $payload)->assertOk()
            ->assertJsonPath('payout_workflow.customer_authorization.risk_acknowledgement', NiumPayoutPilotPolicy::UNCAPPED_RISK)
            ->assertJsonPath('payout_amounts.total_wallet_debit', null);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_submit_cannot_accept_changed_terms_or_another_customers_token(): void
    {
        [, $transfer, $checker] = $this->sgdExecutionFixture();
        $payload = $this->reviewToken($transfer);
        $url = "/api/user/users/12/transfers/{$transfer->id}/sgd-local-submit";
        $this->withToken($this->sgdToken($checker))->postJson($url, $payload)->assertForbidden();
        $this->installSyntheticPricing($transfer, ['pricing_version' => 'CHANGED-AFTER-REVIEW']);
        $this->withToken($this->sgdToken($transfer->user))->postJson($url, $payload)->assertUnprocessable();
        $this->assertSame('draft', $transfer->fresh()->status);
        $this->assertArrayNotHasKey('customer_authorization', $transfer->fresh()->raw_data['sgd_local_workflow']);
    }

    public function test_authoritative_reconciliation_preserves_receipt_and_provider_evidence(): void
    {
        [$provider, $transfer, $checker] = $this->sgdExecutionFixture();
        $this->submitSgd($transfer);
        $receipt = $transfer->fresh()->raw_data['sgd_local_workflow']['customer_authorization'];
        $this->withToken($this->sgdToken($checker))->postJson("/api/admin/transfers/{$transfer->id}/approve")->assertOk();
        $this->fakeSgdProvider('completed');
        $manager = app(ProviderTransferManager::class);
        $manager->syncTransferStatus($provider, $transfer->fresh());
        $evidence = $transfer->fresh()->raw_data['nium_actual_debit'];
        $manager->syncTransferStatus($provider, $transfer->fresh());
        $this->assertSame('principal_reconciled', $evidence['state']);
        $this->assertSame($receipt, $transfer->fresh()->raw_data['sgd_local_workflow']['customer_authorization']);
        $this->assertSame($evidence['state'], $transfer->fresh()->raw_data['nium_actual_debit']['state']);
        $this->assertDatabaseHas('ledger_entries', ['entry_type' => 'debit', 'amount' => '-5.00000000']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/transactions'));
    }
}
