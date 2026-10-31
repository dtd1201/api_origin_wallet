<?php

namespace Tests\Feature;

use App\Contracts\NiumRemittancePricing;
use App\Models\ApiRequestLog;
use App\Models\Balance;
use App\Models\LedgerEntry;
use App\Models\NiumPayoutPilotAuthorization;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Nium\NiumPayoutPilotPolicy;
use App\Services\Nium\NiumSgdLocalPayoutAmounts;
use App\Services\Nium\NiumSgdLocalPayoutGate;
use App\Services\Nium\NiumSgdLocalPayoutWorkflow;
use App\Services\Wallet\TransferApprovalService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesSgdLocalExecutionFixtures;
use Tests\Concerns\RefreshesCommittedPayoutDatabase;
use Tests\TestCase;

class NiumPayoutPilotPolicyTest extends TestCase
{
    use CreatesSgdLocalExecutionFixtures, RefreshesCommittedPayoutDatabase;

    private function stageOneTime(string $available = '85'): array
    {
        [$provider, $transfer, $checker] = $this->sgdExecutionFixture(false);
        Balance::query()->sole()->update(['available_balance' => $available, 'ledger_balance' => $available]);
        $this->artisan('nium:authorize-payout-pilot', ['transfer' => $transfer->id, 'authorizer' => $checker->id,
            'authorization_reference' => 'SYNTHETIC-EXPLICIT-ONE-TIME-AUTHORIZATION', '--accept-uncapped-charges' => true])->assertSuccessful();
        $grant = NiumPayoutPilotAuthorization::query()->sole();
        config()->set('services.nium.payout_pilot', ['enabled' => true, 'authorization_id' => $grant->id]);

        return [$provider, $transfer->fresh(), $checker, $grant];
    }

    public static function oneTimeOutcomes(): array
    {
        return [['pending', 'pending'], ['timeout', 'submission_unknown']];
    }

    #[DataProvider('oneTimeOutcomes')]
    public function test_one_time_admin_approval_reaches_remittance_transport_without_pricing_and_cannot_repeat(string $outcome, string $status): void
    {
        [, $transfer, $checker, $grant] = $this->stageOneTime('5');
        $pricing = $this->mock(NiumRemittancePricing::class);
        $pricing->shouldNotReceive('quote')->withArgs(fn ($candidate) => $candidate->id === $transfer->id);
        // A copied intent still reaches the unchanged ordinary admission policy.
        $pricing->shouldReceive('quote')->withArgs(fn ($candidate) => $candidate->id !== $transfer->id)
            ->andReturn(['status' => 'unavailable']);
        $pricing->shouldNotReceive('refresh');
        $this->assertNull($grant->charge_ceiling);
        $this->assertNull($grant->reserve_ledger_entry_id);
        $this->assertDatabaseCount('ledger_entries', 0);
        $projection = app(NiumSgdLocalPayoutWorkflow::class)->presentation($transfer);
        $this->assertSame('unavailable', $projection['provider_fee_status']);
        $this->assertNull($projection['expected_fee']['amount']);
        $this->assertNull($projection['business_policy']['expected_fee_amount']);
        $this->assertNull($projection['pilot_policy']['reserve_amount']);
        $this->assertTrue($projection['approval_request_allowed']);
        $this->withToken($this->sgdToken($transfer->user))->postJson("/api/user/users/12/transfers/{$transfer->id}/sgd-local-request-approval",
            $this->reviewToken($transfer))->assertOk()
            ->assertJsonPath('payout_amounts.total_wallet_debit', null)->assertJsonPath('payout_amounts.transfer_fee', null);
        $this->denied(fn () => app(TransferApprovalService::class)->approve($transfer->fresh(), $transfer->user));
        $this->fakeSgdProvider($outcome);
        $token = $this->sgdToken($checker);
        $url = "/api/admin/transfers/{$transfer->id}/approve";
        $this->withToken($token)->postJson($url)->assertOk()
            ->assertJsonPath('transfer.payout_amounts.actual_debit.pilot_charge_exposure', 'uncapped')
            ->assertJsonPath('transfer.payout_workflow.expected_fee.amount', null);
        $current = $transfer->fresh();
        $this->assertSame($status, $current->status);
        $this->assertSame($current->provider_operation_key, $grant->fresh()->consumed_operation_key);
        $this->assertSame('0.00000000', Balance::query()->sole()->available_balance);
        $this->assertSame('5.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame('-5.00000000', LedgerEntry::query()->where('entry_type', 'hold')->sole()->amount);
        if ($outcome === 'pending') {
            Http::assertSent(fn ($r) => $r->method() === 'POST'
                && $r->url() === 'https://nium.test/api/v1/client/synthetic-client/customer/synthetic-customer/wallet/synthetic-wallet/remittance'
                && $r->hasHeader('x-api-key', 'synthetic-only')
                && $r->data() === ['beneficiary' => ['id' => 'synthetic-external-beneficiary'],
                    'payout' => ['sourceAmount' => 5, 'sourceCurrency' => 'USD', 'auditId' => (int) $transfer->fxQuote->quote_ref],
                    'purposeCode' => 'SYNTHETIC_PURPOSE', 'sourceOfFunds' => 'Corporate Account']);
        }
        $this->withToken($token)->postJson($url)->assertOk();
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
        $this->assertDatabaseCount('transfer_approvals', 1);
        $copy = $transfer->replicate();
        $copy->transfer_no = 'COPIED-ONE-TIME-PAYOUT';
        $copy->client_reference = 'COPIED-ONE-TIME-REFERENCE';
        $copy->save();
        $this->expectExceptionMessage(NiumSgdLocalPayoutGate::BLOCK_REASON);
        NiumSgdLocalPayoutGate::assertSubmissionAllowed($copy);
    }

    public static function oneTimeChanges(): array
    {
        return array_map(fn ($change) => [$change], ['amount', 'maker', 'beneficiary', 'purpose', 'wallet', 'reference',
            'audit', 'expired', 'disabled', 'unfunded', 'revoked', 'missing risk acceptance']);
    }

    #[DataProvider('oneTimeChanges')]
    public function test_one_time_pricing_exception_does_not_admit_changed_or_ineligible_transfers(string $change): void
    {
        [, $transfer, , $grant] = $this->stageOneTime();
        match ($change) {
            'amount' => $transfer->update(['source_amount' => '4']),
            'maker' => $transfer->update(['user_id' => 13]),
            'beneficiary' => $transfer->update(['beneficiary_id' => null]),
            'purpose' => $transfer->update(['purpose_code' => 'ALTERNATE_SYNTHETIC_PURPOSE']),
            'wallet' => $transfer->user->providerAccounts()->update(['external_account_id' => 'another-wallet']),
            'reference' => $transfer->update(['client_reference' => 'ANOTHER-REFERENCE']),
            'audit' => $transfer->fxQuote->update(['quote_ref' => '123']),
            'expired' => $grant->forceFill(['expires_at' => now()->subSecond()])->save(),
            'disabled' => config()->set('services.nium.payout_pilot.enabled', false),
            'unfunded' => Balance::query()->sole()->update(['available_balance' => '4.99']),
            'revoked' => $grant->forceFill(['revoked_at' => now()])->save(),
            'missing risk acceptance' => $grant->forceFill(['verification' => ['authorization_reference' => 'forged']])->save(),
        };
        $this->denied(fn () => NiumSgdLocalPayoutGate::assertSubmissionAllowed($transfer->fresh()));
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_one_time_uncapped_authorization_requires_explicit_acceptance_and_cannot_be_reissued(): void
    {
        [, $transfer, $checker] = $this->sgdExecutionFixture(false);
        $this->artisan('nium:authorize-payout-pilot', ['transfer' => $transfer->id, 'authorizer' => $checker->id,
            'authorization_reference' => 'SYNTHETIC-AUTHORIZATION'])->assertFailed();
        $this->assertDatabaseCount('nium_payout_pilot_authorizations', 0);
        $grant = app(NiumPayoutPilotPolicy::class)->authorizeOneTime($transfer, $checker, 'SYNTHETIC-AUTHORIZATION', true);
        $this->expectException(QueryException::class);
        try {
            app(NiumPayoutPilotPolicy::class)->authorizeOneTime($transfer, $checker, 'ANOTHER-AUTHORIZATION', true);
        } finally {
            $this->assertDatabaseCount('nium_payout_pilot_authorizations', 1);
            $this->assertSame($grant->id, NiumPayoutPilotAuthorization::query()->sole()->id);
            $this->assertDatabaseCount('ledger_entries', 0);
        }
    }

    public function test_uncapped_actual_fee_is_recorded_separately_once_even_if_it_exceeds_available_funds(): void
    {
        [$provider, $transfer, $checker] = $this->stageOneTime('5');
        $pending = app(TransferApprovalService::class)->approve($this->submitSgd($transfer),
            $checker, manager: app(ProviderTransferManager::class));
        config()->set('services.nium.payout_pilot.enabled', false);
        $this->travel(11)->minutes();
        $this->fakeSgdProvider('completed', '1.25');
        $manager = app(ProviderTransferManager::class);
        $manager->syncTransferStatus($provider, $pending);
        $manager->syncTransferStatus($provider, $pending->fresh());
        $this->assertSame('-1.25000000', Balance::query()->sole()->available_balance);
        $this->assertSame('-1.25000000', Balance::query()->sole()->ledger_balance);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame('-5.00000000', LedgerEntry::query()->where('entry_type', 'debit')->sole()->amount);
        $this->assertSame('-1.25000000', LedgerEntry::query()->where('entry_type', 'adjustment')->sole()->amount);
        $actual = app(NiumSgdLocalPayoutAmounts::class)->forTransfer($pending->fresh())['actual_debit'];
        $this->assertSame('6.25000000', $actual['observed_total_debit']);
        $this->assertSame('1.25000000', $actual['observed_charge_debit']);
        $this->assertSame('uncapped', $actual['pilot_charge_exposure']);
        $this->assertTrue($actual['pilot_additional_charges_observed']);
        $this->assertNull($actual['pilot_charge_ceiling_breached']);
        $this->assertSame('not_proven_by_absence_of_fee_rows', $actual['charge_completeness']);
        $this->travelBack();
    }

    private function stage(bool $activate = true): array
    {
        [$provider, $transfer, $checker] = $this->sgdExecutionFixture(false);
        // Synthetic separate settled funding, already accounted by the funding ledger.
        $balance = Balance::query()->sole();
        $balance->update(['available_balance' => '87', 'ledger_balance' => '87']);
        $funding = LedgerEntry::query()->create(['balance_id' => $balance->id, 'user_id' => 12, 'provider_id' => $provider->id,
            'reference' => 'synthetic-separate-settled-funding', 'entry_type' => 'credit', 'status' => 'posted', 'currency' => 'USD',
            'amount' => '2', 'balance_after' => '87', 'source_type' => 'verified_funding', 'source_id' => 'synthetic-funding', 'posted_at' => now()]);
        $grant = app(NiumPayoutPilotPolicy::class)->authorize($transfer, $checker, $funding, '2', [
            'funding_reference' => $funding->reference, 'charge_bound_reference' => 'SYNTHETIC-BOUND-NOT-NIUM-EVIDENCE',
            'review_document_sha256' => hash('sha256', 'Synthetic verified funding and binding all-charges ceiling'),
            'attestation' => 'settled_separate_funding_and_binding_all_charges_usd_ceiling_same_wallet',
        ]);
        config()->set('services.nium.payout_pilot', ['enabled' => $activate, 'authorization_id' => $grant->id]);

        return [$provider, $transfer->fresh(), $checker, $grant];
    }

    private function denied(callable $attempt): void
    {
        try {
            $attempt();
            $this->fail('A scoped one-use authorization must fail closed.');
        } catch (RuntimeException) {
            $this->assertSame(0, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
        }
    }

    public function test_staged_reserve_and_customer_metadata_cannot_enable_the_disabled_policy(): void
    {
        [, $transfer] = $this->stage(false);
        $raw = $transfer->raw_data;
        $raw['pilot_policy'] = ['enabled' => true, 'verified' => true];
        $transfer->update(['raw_data' => $raw]);
        $this->denied(fn () => $this->submitSgd($transfer->fresh()));
        $this->assertSame('2.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->assertNull($transfer->fresh()->provider_operation_key);
    }

    public function test_independent_admin_endpoint_dispatches_only_the_authorized_principal_once_with_unknown_fees(): void
    {
        [, $transfer, $checker, $grant] = $this->stage();
        $amounts = app(NiumSgdLocalPayoutAmounts::class)->forTransfer($transfer);
        $this->assertNull($amounts['transfer_fee']);
        $this->assertNull($amounts['total_wallet_debit']);
        $workflow = app(NiumSgdLocalPayoutWorkflow::class);
        $projection = $workflow->presentation($transfer);
        $this->assertSame('unavailable', $projection['provider_fee_status']);
        $this->assertTrue($projection['approval_request_allowed']);
        $requested = $this->submitSgd($transfer);
        $this->denied(fn () => app(TransferApprovalService::class)->approve($requested, $requested->user));
        $token = $this->sgdToken($checker);
        $url = "/api/admin/transfers/{$transfer->id}/approve";
        $this->withToken($token)->postJson($url)->assertOk();
        $pending = $transfer->fresh();
        $this->assertSame('pending', $pending->status);
        $this->assertSame($pending->provider_operation_key, $grant->fresh()->consumed_operation_key);
        $this->assertSame('-5.00000000', LedgerEntry::query()->where('reference', "transfer:{$transfer->id}:hold")->sole()->amount);
        $this->assertSame('7.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame('80.00000000', Balance::query()->sole()->available_balance);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->data() === [
            'beneficiary' => ['id' => 'synthetic-external-beneficiary'],
            'payout' => ['sourceAmount' => 5, 'sourceCurrency' => 'USD', 'auditId' => (int) $transfer->fxQuote->quote_ref],
            'purposeCode' => 'SYNTHETIC_PURPOSE', 'sourceOfFunds' => 'Corporate Account',
        ]);
        $this->withToken($token)->postJson($url)->assertOk();
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
        $this->assertDatabaseCount('transfer_approvals', 1);
        $this->expectExceptionMessage('already been consumed');
        DB::transaction(fn () => app(NiumPayoutPilotPolicy::class)->consume($pending, $pending->raw_data['sgd_local_approval']['financial_terms']));
    }

    public function test_copied_authorization_and_fresh_transfer_reference_cannot_reuse_the_exception(): void
    {
        [, $transfer, , $grant] = $this->stage();
        $copy = $transfer->replicate();
        $copy->transfer_no = 'ANOTHER-TRANSFER';
        $copy->client_reference = 'ANOTHER-INTENT';
        $copy->save();
        $this->denied(fn () => app(NiumPayoutPilotPolicy::class)->terms($copy));
        $this->denied(fn () => NiumSgdLocalPayoutGate::assertSubmissionAllowed($copy));
        $this->assertNull($grant->fresh()->consumed_operation_key);
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->assertSame('2.00000000', Balance::query()->sole()->reserved_balance);
    }

    public static function changedAuthorization(): array
    {
        return array_map(fn ($name) => [$name], ['above cap', 'different principal', 'different maker', 'different beneficiary',
            'different currency', 'different purpose', 'different reference', 'different wallet', 'different audit',
            'missing reserve', 'insufficient reserve', 'changed funding', 'expired', 'revoked', 'consumed elsewhere', 'wrong configured grant']);
    }

    #[DataProvider('changedAuthorization')]
    public function test_authorization_cannot_apply_to_changed_intent_or_unfunded_conditions(string $change): void
    {
        [, $transfer, , $grant] = $this->stage();
        match ($change) {
            'above cap' => $transfer->update(['source_amount' => '5.01']),
            'different principal' => $transfer->update(['source_amount' => '4']),
            'different maker' => $transfer->update(['user_id' => 13]),
            'different beneficiary' => $transfer->update(['beneficiary_id' => null]),
            'different currency' => $transfer->update(['source_currency' => 'SGD']),
            'different purpose' => $transfer->update(['purpose_code' => 'ALTERNATE_SYNTHETIC_PURPOSE']),
            'different reference' => $transfer->update(['client_reference' => 'ANOTHER-REFERENCE']),
            'different wallet' => $transfer->user->providerAccounts()->update(['external_account_id' => 'another-wallet']),
            'different audit' => $transfer->fxQuote->update(['quote_ref' => '123']),
            'missing reserve' => LedgerEntry::query()->whereKey($grant->reserve_ledger_entry_id)->update(['status' => 'released']),
            'insufficient reserve' => Balance::query()->sole()->update(['reserved_balance' => '1']),
            'changed funding' => LedgerEntry::query()->whereKey($grant->funding_ledger_entry_id)->update(['amount' => '1']),
            'expired' => $grant->forceFill(['expires_at' => now()->subSecond()])->save(),
            'revoked' => $grant->forceFill(['revoked_at' => now()])->save(),
            'consumed elsewhere' => $grant->forceFill(['consumed_operation_key' => 'nium-another-operation'])->save(),
            'wrong configured grant' => config()->set('services.nium.payout_pilot.authorization_id', 'missing'),
        };
        $this->denied(fn () => NiumSgdLocalPayoutGate::assertSubmissionAllowed($transfer->fresh()));
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->assertNull($transfer->fresh()->provider_operation_key);
    }

    public function test_single_pilot_and_funding_credit_cannot_be_authorized_again(): void
    {
        [, $transfer, $checker, $grant] = $this->stage();
        $balance = Balance::query()->sole()->toArray();
        try {
            app(NiumPayoutPilotPolicy::class)->authorize($transfer, $checker,
                LedgerEntry::query()->findOrFail($grant->funding_ledger_entry_id), '2', $grant->verification);
            $this->fail('The durable pilot grant must never be replaceable.');
        } catch (QueryException) {
        }
        $this->assertSame($balance, Balance::query()->sole()->toArray());
        $this->assertDatabaseCount('nium_payout_pilot_authorizations', 1);
        $this->assertSame(1, LedgerEntry::query()->where('source_type', 'nium_payout_pilot')->count());
    }

    public function test_unknown_outcome_consumes_authorization_and_preserves_both_holds(): void
    {
        [$provider, $transfer, $checker, $grant] = $this->stage();
        $requested = $this->submitSgd($transfer);
        $this->fakeSgdProvider('timeout');
        $unknown = app(TransferApprovalService::class)->approve($requested, $checker, manager: app(ProviderTransferManager::class));
        $this->assertSame('submission_unknown', $unknown->status);
        $this->assertSame($unknown->provider_operation_key, $grant->fresh()->consumed_operation_key);
        app(TransferApprovalService::class)->approve($unknown, $checker, manager: app(ProviderTransferManager::class));
        try {
            app(ProviderTransferManager::class)->submitTransfer($provider, $unknown);
            $this->fail('Unknown outcomes cannot dispatch again.');
        } catch (RuntimeException) {
        }
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
        $this->assertSame('7.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'release')->count());
    }

    public function test_actual_fees_consume_only_the_owned_reserve_once_and_breach_is_visible_after_deactivation(): void
    {
        [$provider, $transfer, $checker, $grant] = $this->stage();
        $pending = app(TransferApprovalService::class)->approve($this->submitSgd($transfer),
            $checker, manager: app(ProviderTransferManager::class));
        config()->set('services.nium.payout_pilot.enabled', false);
        $this->travel(11)->minutes();
        $this->fakeSgdProvider('completed', '0.75');
        $manager = app(ProviderTransferManager::class);
        $manager->syncTransferStatus($provider, $pending);
        $manager->syncTransferStatus($provider, $pending->fresh());
        $balance = Balance::query()->sole();
        $this->assertSame('80.00000000', $balance->available_balance);
        $this->assertSame('81.25000000', $balance->ledger_balance);
        $this->assertSame('1.25000000', $balance->reserved_balance);
        $this->assertSame('1.25000000', $grant->fresh()->reserve_remaining);
        $this->assertSame('-0.75000000', LedgerEntry::query()->where('entry_type', 'adjustment')->sole()->amount);
        $this->assertSame(['authorization_id' => $grant->id, 'reserved_amount_used' => '0.75000000', 'uncovered_amount' => '0.00000000'],
            LedgerEntry::query()->where('entry_type', 'adjustment')->sole()->raw_data['pilot_reserve_allocation']);
        $this->assertSame('5.75000000', $pending->fresh()->raw_data['nium_actual_debit']['observed_total_debit']);
        $this->assertFalse($pending->fresh()->raw_data['nium_actual_debit']['pilot_charge_ceiling_breached']);
        $this->assertNull(app(NiumSgdLocalPayoutAmounts::class)->forTransfer($pending->fresh())['transfer_fee']);
        // A later distinct authenticated fee exceeds the attested bound: record the
        // actual liability and expose the breach instead of hiding or rejecting it.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($pending) {
            if (str_contains($request->url(), '/transactions')) {
                $principal = ['authCode' => $pending->external_transfer_id, 'transactionType' => 'Remittance_Debit_External',
                    'clientHashId' => 'synthetic-client', 'customerHashId' => 'synthetic-customer', 'walletHashId' => 'synthetic-wallet',
                    'authCurrencyCode' => 'USD', 'debit' => true, 'status' => 'Approved', 'settlementStatus' => 'Settled',
                    'authAmount' => '5', 'settlementAuthAmount' => '5'];
                $rows = [$principal];
                foreach (['synthetic-fee' => '0.75', 'synthetic-late-fee' => '3'] as $id => $amount) {
                    $rows[] = array_replace($principal, ['authCode' => $id, 'transactionType' => 'Fee_Debit',
                        'authAmount' => $amount, 'settlementAuthAmount' => $amount,
                        'labels' => ['originalAuthorizationCode' => $pending->external_transfer_id]]);
                }

                return Http::response(['content' => $rows, 'totalPages' => 1, 'totalElements' => 3]);
            }

            return Http::response([['status' => 'PAID']]);
        });
        $manager->syncTransferStatus($provider, $pending->fresh());
        $manager->syncTransferStatus($provider, $pending->fresh());
        $this->assertSame('78.25000000', Balance::query()->sole()->available_balance);
        $this->assertSame('78.25000000', Balance::query()->sole()->ledger_balance);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame(2, LedgerEntry::query()->where('entry_type', 'adjustment')->count());
        $this->assertTrue($pending->fresh()->raw_data['nium_actual_debit']['pilot_charge_ceiling_breached']);
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
        $this->travelBack();
    }
}
