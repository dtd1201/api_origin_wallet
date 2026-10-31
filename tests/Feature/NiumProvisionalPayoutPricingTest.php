<?php

namespace Tests\Feature;

use App\Contracts\NiumRemittancePricing;
use App\Models\LedgerEntry;
use App\Models\NiumApiExchangeEvidence;
use App\Models\Transfer;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Nium\NiumPayoutFxLockContract;
use App\Services\Nium\NiumProvisionalPayoutPricing;
use App\Services\Nium\NiumSgdLocalPayoutWorkflow;
use App\Services\Wallet\TransferApprovalService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesSgdLocalExecutionFixtures;
use Tests\Concerns\RefreshesCommittedPayoutDatabase;
use Tests\TestCase;

class NiumProvisionalPayoutPricingTest extends TestCase
{
    use CreatesSgdLocalExecutionFixtures, RefreshesCommittedPayoutDatabase;

    private function transferThree(): array
    {
        [, $seed, $checker] = $this->sgdExecutionFixture(false);
        $workflow = app(NiumSgdLocalPayoutWorkflow::class);
        // Allocate synthetic IDs naturally; no historical transfer is edited by these fixtures.
        for ($index = 2; $index <= 3; $index++) {
            $transfer = $workflow->prepare($seed->user, [
                'provider_id' => $seed->provider_id, 'beneficiary_id' => $seed->beneficiary_id,
                'source_currency' => 'USD', 'target_currency' => 'SGD', 'source_amount' => '5',
                'purpose_code' => $seed->purpose_code, 'client_reference' => 'PROVISIONAL-SYNTHETIC-'.$index,
                'expected_fee_amount' => '0', 'expected_fee_currency' => 'USD',
            ]);
        }
        $this->assertSame(3, $transfer->id);

        $locked = $workflow->requestLock($transfer, Str::uuid()->toString());

        // Compare persisted snapshots: SQLite's raw NUMERIC attributes can differ from
        // the just-assigned strings. The production decimal casts and contract stay exact.
        return [$locked->fresh(), $checker];
    }

    private function url(Transfer $transfer): string
    {
        return "/api/user/users/{$transfer->user_id}/transfers/{$transfer->id}/sgd-local-provisional-pricing";
    }

    private function confirmation(Transfer $transfer): array
    {
        return ['policy_id' => NiumProvisionalPayoutPricing::POLICY_ID, 'fx_quote_id' => $transfer->fx_quote_id,
            'confirm_provisional_pricing' => true];
    }

    private function record(Transfer $transfer): Transfer
    {
        $this->withToken($this->sgdToken($transfer->user))->postJson($this->url($transfer), $this->confirmation($transfer))->assertOk();

        return $transfer->fresh();
    }

    public function test_explicit_confirmation_appends_only_internal_metadata_and_does_not_admit_payment(): void
    {
        [$transfer, $checker] = $this->transferThree();
        $contractRate = $transfer->fxQuote->raw_data['payout_fx_lock_contract']['rate'];
        $this->assertSame('1.275939120', $contractRate);
        $this->assertSame('1.2759391200', $transfer->fx_rate);
        $this->assertSame('1.2759391200', $transfer->fxQuote->net_rate);
        $this->assertSame(NiumPayoutFxLockContract::decimal($contractRate), NiumPayoutFxLockContract::decimal($transfer->fx_rate));
        $before = $transfer->getAttributes();
        $rawBefore = $transfer->raw_data;
        $quoteBefore = $transfer->fxQuote->getAttributes();
        $evidenceBefore = NiumApiExchangeEvidence::all()->map->getAttributes()->all();
        $calls = count(Http::recorded());
        $this->assertNull(app(NiumProvisionalPayoutPricing::class)->presentation($transfer));
        $recorded = $this->record($transfer);
        $rawAfter = $recorded->raw_data;
        $history = $rawAfter['nium_provisional_pricing_history'];
        unset($rawAfter['nium_provisional_pricing_history']);
        $this->assertSame($rawBefore, $rawAfter);
        $after = $recorded->getAttributes();
        unset($before['raw_data'], $before['updated_at'], $after['raw_data'], $after['updated_at']);
        $this->assertSame($before, $after);
        $this->assertCount(1, $history);
        $this->assertSame($transfer->user_id, $history[0]['approval']['user_id']);
        $this->assertSame($transfer->fx_quote_id, $history[0]['scope']['fx_quote_id']);
        $this->assertSame($contractRate, $history[0]['scope']['rate']);
        $this->assertSame('1.2759391200', $recorded->fx_rate);
        $this->assertSame($quoteBefore, $transfer->fxQuote->fresh()->getAttributes());
        $this->assertSame($evidenceBefore, NiumApiExchangeEvidence::all()->map->getAttributes()->all());
        $this->record($recorded);
        $this->assertSame($history, $recorded->fresh()->raw_data['nium_provisional_pricing_history']);
        $this->withToken($this->sgdToken($transfer->user))->getJson('/api/user/users/12/transfers/3')
            ->assertOk()->assertJsonPath('payout_workflow.provisional_pricing.status', 'current')
            ->assertJsonPath('payout_workflow.provisional_pricing.expected.additional_fee_amount', '0')
            ->assertJsonPath('payout_workflow.provisional_pricing.expected.total_wallet_debit', '5')
            ->assertJsonPath('payout_workflow.provisional_pricing.provider_verified', false)
            ->assertJsonPath('payout_workflow.provisional_pricing.financial_authorization', false)
            ->assertJsonPath('payout_workflow.provider_fee_status', 'unavailable')
            ->assertJsonPath('payout_workflow.approval_request_allowed', false)
            ->assertJsonPath('fx_rate', $contractRate)
            ->assertJsonPath('payout_amounts.fx_rate', $contractRate)
            ->assertJsonPath('payout_amounts.transfer_fee', null)
            ->assertJsonPath('payout_amounts.total_wallet_debit', null)
            ->assertJsonPath('payout_amounts.actual_debit', null);
        $this->assertSame('unavailable', app(NiumRemittancePricing::class)->quote($recorded)['status']);
        $this->withToken($this->sgdToken($transfer->user))->postJson('/api/user/users/12/transfers/3/sgd-local-request-approval', [
            'financial_terms_token' => str_repeat('a', 64),
        ])->assertUnprocessable();
        try {
            app(TransferApprovalService::class)->approve($recorded, $checker, manager: app(ProviderTransferManager::class));
            $this->fail('A provisional record must never authorize checker approval or dispatch.');
        } catch (RuntimeException) {
        }
        $this->assertSame('draft', $recorded->fresh()->status);
        $this->assertNull($recorded->fresh()->provider_operation_key);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->assertSame($calls, count(Http::recorded()));
    }

    public function test_confirmation_requires_authentication_ownership_and_the_exact_policy(): void
    {
        [$transfer] = $this->transferThree();
        $before = $transfer->getAttributes();
        $this->postJson($this->url($transfer), $this->confirmation($transfer))->assertUnauthorized();
        $other = User::factory()->create(['status' => 'active']);
        $this->withToken($this->sgdToken($other))->postJson($this->url($transfer), $this->confirmation($transfer))->assertForbidden();
        $this->withToken($this->sgdToken($transfer->user));
        $this->postJson($this->url($transfer), array_merge($this->confirmation($transfer), ['confirm_provisional_pricing' => false]))->assertUnprocessable();
        $this->postJson($this->url($transfer), array_merge($this->confirmation($transfer), ['policy_id' => 'unapproved-policy']))->assertUnprocessable();
        $this->postJson($this->url($transfer), array_merge($this->confirmation($transfer), ['fee_amount' => '0']))->assertUnprocessable();
        $this->assertSame($before, $transfer->fresh()->getAttributes());
    }

    public static function mismatches(): array
    {
        return array_map(fn ($case) => [$case], ['other_transfer', 'amount', 'purpose', 'wallet', 'quote_id',
            'expired', 'consumed', 'quote_tampered', 'operation', 'approval_started', 'ledger']);
    }

    #[DataProvider('mismatches')]
    public function test_scope_and_fx_mismatches_cannot_record_a_policy(string $case): void
    {
        [$transfer] = $this->transferThree();
        $input = $this->confirmation($transfer);
        if ($case === 'other_transfer') {
            $transfer = Transfer::query()->findOrFail(1);
            $input = $this->confirmation($transfer);
        } elseif ($case === 'amount') {
            $transfer->update(['source_amount' => '6']);
        } elseif ($case === 'purpose') {
            $transfer->update(['purpose_code' => 'CHANGED']);
        } elseif ($case === 'wallet') {
            UserProviderAccount::query()->sole()->update(['external_account_id' => 'different-wallet']);
        } elseif ($case === 'quote_id') {
            $input['fx_quote_id']++;
        } elseif ($case === 'expired') {
            $this->travel(11)->minutes();
        } elseif ($case === 'consumed') {
            $quote = $transfer->fxQuote;
            $quote->update(['raw_data' => array_merge($quote->raw_data, ['consumed_at' => now()->toISOString()])]);
        } elseif ($case === 'quote_tampered') {
            // A one-unit change at the stored tenth decimal place must break binding.
            $transfer->fxQuote->update(['net_rate' => '1.2759391201']);
        } elseif ($case === 'operation') {
            $transfer->update(['provider_operation_key' => 'synthetic-existing-operation']);
        } elseif ($case === 'approval_started') {
            $transfer->update(['status' => 'approval_required']);
        } else {
            LedgerEntry::query()->create(['reference' => 'synthetic-existing-hold', 'entry_type' => 'hold',
                'currency' => 'USD', 'amount' => '-5', 'source_type' => 'transfer', 'source_id' => '3']);
        }
        $before = $transfer->fresh()->getAttributes();
        $calls = count(Http::recorded());
        $this->withToken($this->sgdToken($transfer->user))->postJson($this->url($transfer), $input)->assertUnprocessable();
        $this->assertSame($before, $transfer->fresh()->getAttributes());
        $this->assertSame($calls, count(Http::recorded()));
        $this->travelBack();
    }

    public function test_expiry_restart_and_reconfirmation_append_without_rewriting_the_previous_record(): void
    {
        [$transfer] = $this->transferThree();
        $recorded = $this->record($transfer);
        $first = $recorded->raw_data['nium_provisional_pricing_history'][0];
        $this->travel(11)->minutes();
        $policy = app(NiumProvisionalPayoutPricing::class);
        $this->assertSame('expired', $policy->presentation($recorded)['status']);
        $workflow = app(NiumSgdLocalPayoutWorkflow::class);
        $draft = $workflow->restartExpiredReview($recorded);
        $locked = $workflow->requestLock($draft, Str::uuid()->toString());
        $this->assertSame('expired', $policy->presentation($locked)['status']);
        $fresh = $this->record($locked);
        $history = $fresh->raw_data['nium_provisional_pricing_history'];
        $this->assertCount(2, $history);
        $this->assertSame($first, $history[0]);
        $this->assertNotSame($first['scope']['fx_quote_id'], $history[1]['scope']['fx_quote_id']);
        $this->assertSame('current', $policy->presentation($fresh)['status']);
        $this->assertSame('draft', $fresh->status);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->travelBack();
    }

    public function test_tampered_or_copied_metadata_never_becomes_a_current_policy_or_provider_evidence(): void
    {
        [$transfer] = $this->transferThree();
        $recorded = $this->record($transfer);
        $raw = $recorded->raw_data;
        $other = Transfer::query()->findOrFail(1);
        $other->update(['raw_data' => array_merge($other->raw_data, [
            'nium_provisional_pricing_history' => $raw['nium_provisional_pricing_history'],
        ])]);
        $policy = app(NiumProvisionalPayoutPricing::class);
        $this->assertNull($policy->presentation($other->fresh()));
        $raw['nium_provisional_pricing_history'][0]['policy']['provider_verified'] = true;
        $recorded->update(['raw_data' => $raw]);
        $view = $policy->presentation($recorded->fresh());
        $this->assertSame('invalid', $view['status']);
        $this->assertFalse($view['provider_verified']);
        $this->assertArrayNotHasKey('expected', $view);
        $before = $recorded->fresh()->getAttributes();
        $this->withToken($this->sgdToken($transfer->user))->postJson($this->url($recorded), $this->confirmation($recorded))->assertUnprocessable();
        $this->assertSame($before, $recorded->fresh()->getAttributes());
        $this->assertSame('unavailable', app(NiumRemittancePricing::class)->quote($recorded)['status']);
    }

    public function test_changed_binding_makes_the_record_inapplicable_without_overwriting_it(): void
    {
        [$transfer] = $this->transferThree();
        $recorded = $this->record($transfer);
        $history = $recorded->raw_data['nium_provisional_pricing_history'];
        $recorded->update(['source_amount' => '6']);
        $this->assertSame('binding_mismatch', app(NiumProvisionalPayoutPricing::class)->presentation($recorded->fresh())['status']);
        $this->assertSame($history, $recorded->fresh()->raw_data['nium_provisional_pricing_history']);
    }

    public function test_json_object_reordering_preserves_the_local_integrity_seal(): void
    {
        [$transfer] = $this->transferThree();
        $recorded = $this->record($transfer);
        $raw = $recorded->raw_data;
        $record = $raw['nium_provisional_pricing_history'][0];
        $record['scope']['provider_context'] = array_reverse($record['scope']['provider_context'], true);
        $record['scope'] = array_reverse($record['scope'], true);
        $record['policy']['expected'] = array_reverse($record['policy']['expected'], true);
        $raw['nium_provisional_pricing_history'][0] = array_reverse($record, true);
        $recorded->update(['raw_data' => $raw]);
        $this->assertSame('current', app(NiumProvisionalPayoutPricing::class)->presentation($recorded->fresh())['status']);
    }

    public static function observedFees(): array
    {
        return [['0', 'observed_match', '5'], ['1.25', 'observed_discrepancy', '6.25']];
    }

    #[DataProvider('observedFees')]
    public function test_only_authenticated_reconciliation_supplies_actuals_and_preserves_provisional_history(string $fee, string $comparison, string $total): void
    {
        [$transfer, $checker] = $this->transferThree();
        $recorded = $this->record($transfer);
        $history = $recorded->raw_data['nium_provisional_pricing_history'];
        $policy = app(NiumProvisionalPayoutPricing::class);
        $this->assertSame('awaiting_authenticated_actual_debit', $policy->presentation($recorded)['comparison']['status']);
        // Explicitly synthetic existing execution fixture: not a claim about NIUM's real pricing.
        // Admission still needs the separate verified-pricing interface; provisional metadata cannot provide it.
        $this->installSyntheticPricing($recorded);
        $requested = app(NiumSgdLocalPayoutWorkflow::class)->requestApproval($recorded);
        $pending = app(TransferApprovalService::class)->approve($requested, $checker, manager: app(ProviderTransferManager::class));
        $this->assertSame('awaiting_authenticated_actual_debit', $policy->presentation($pending)['comparison']['status']);
        $this->travel(11)->minutes();
        $this->fakeSgdProvider('completed', $fee === '0' ? null : $fee);
        $manager = app(ProviderTransferManager::class);
        $manager->syncTransferStatus($pending->provider, $pending);
        $completed = $pending->fresh();
        $view = $policy->presentation($completed);
        $this->assertSame('expired', $view['status']);
        $this->assertSame($comparison, $view['comparison']['status']);
        $this->assertSame($total, $view['comparison']['observed_wallet_debit']);
        $this->assertSame($fee, $view['comparison']['fee_difference']);
        $this->assertSame($fee, $view['comparison']['wallet_debit_difference']);
        $this->assertSame('5', $view['expected']['total_wallet_debit']);
        $this->assertSame('not_proven_by_absence_of_fee_rows', $view['comparison']['charge_completeness']);
        $this->assertSame($history, $completed->raw_data['nium_provisional_pricing_history']);
        $entries = LedgerEntry::count();
        $manager->syncTransferStatus($completed->provider, $completed);
        $this->assertSame($entries, LedgerEntry::count(), 'Repeated reconciliation cannot duplicate accounting.');
        $this->assertSame($history, $completed->fresh()->raw_data['nium_provisional_pricing_history']);
        $raw = $completed->fresh()->raw_data;
        $raw['nium_actual_debit']['observed_total_debit'] = 'inconsistent-summary';
        $completed->update(['raw_data' => $raw]);
        $invalidActual = $policy->presentation($completed->fresh());
        $this->assertSame('expired', $invalidActual['status'], 'A bad actual summary must not relabel the sealed policy as invalid.');
        $this->assertSame('actual_debit_inconsistent', $invalidActual['comparison']['status']);
        $this->assertSame('5', $invalidActual['expected']['total_wallet_debit']);
        $this->travelBack();
    }
}
