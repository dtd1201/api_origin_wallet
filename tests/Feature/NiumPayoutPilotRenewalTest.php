<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\Balance;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Nium\NiumPayoutPilotPolicy;
use App\Services\Nium\NiumSgdLocalPayoutWorkflow;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesRenewableNiumPilotFixtures;
use Tests\Concerns\CreatesSgdLocalExecutionFixtures;
use Tests\Concerns\RefreshesCommittedPayoutDatabase;
use Tests\TestCase;

class NiumPayoutPilotRenewalTest extends TestCase
{
    use CreatesRenewableNiumPilotFixtures, CreatesSgdLocalExecutionFixtures, RefreshesCommittedPayoutDatabase;

    private function confirmation(Transfer $transfer, User $checker): array
    {
        $preview = app(NiumPayoutPilotPolicy::class)->renewalPreview($transfer, $checker);
        $this->assertTrue($preview['renewal_allowed']);
        $this->assertSame('1.275939120', $preview['fx_rate']);
        $this->assertSame('unverified', $preview['provider_fee_status']);
        $this->assertNull($preview['total_wallet_debit']);

        return ['request_id' => Str::uuid()->toString(), 'confirmation_token' => $preview['confirmation_token'],
            'authorization_reference' => 'SYNTHETIC-NEW-RISK-ACCEPTANCE', 'accept_uncapped_charges' => true,
            'risk_acknowledgement' => NiumPayoutPilotPolicy::UNCAPPED_RISK];
    }

    private function renew(Transfer $transfer, User $checker, array $input): void
    {
        $this->withToken($this->sgdToken($checker))->postJson('/api/admin/transfers/3/pilot-authorization/renew', $input)
            ->assertOk()->assertJsonPath('transfer.status', 'draft')
            ->assertJsonPath('transfer.pilot_authorization_renewal.state', 'current_authorization')
            ->assertJsonPath('transfer.payout_amounts.transfer_fee', null)
            ->assertJsonPath('transfer.payout_amounts.total_wallet_debit', null);
    }

    public static function dispatchOutcomes(): array
    {
        return [['pending', 'pending'], ['timeout', 'submission_unknown']];
    }

    #[DataProvider('dispatchOutcomes')]
    public function test_renewal_preserves_history_then_one_admin_approval_dispatches_once(string $outcome, string $status): void
    {
        [, $transfer, $checker, $grant, $oldApproval] = $this->expiredTransferThreePilot();
        $original = $grant->getAttributes();
        $originalVerification = $grant->verification;
        $approvalBefore = $oldApproval->getAttributes();
        $reviewHistory = $transfer->raw_data['sgd_local_workflow']['review_history'];
        $calls = count(Http::recorded());
        $input = $this->confirmation($transfer, $checker);
        $this->renew($transfer, $checker, $input);
        $this->renew($transfer->fresh(), $checker, $input); // Double preparation click is idempotent.
        $currentGrant = $grant->fresh();
        $history = $currentGrant->verification['one_time_renewals'];
        $this->assertCount(1, $history);
        $this->assertSame($checker->id, $history[0]['verified_by']);
        $this->assertSame($grant->id, $history[0]['previous_revision']);
        $this->assertSame($transfer->fx_quote_id, $history[0]['binding']['snapshot']['fx_quote_id']);
        $this->assertSame(NiumPayoutPilotPolicy::UNCAPPED_RISK, $history[0]['verification']['risk_acknowledgement']);
        $verification = $currentGrant->verification;
        unset($verification['one_time_renewals']);
        $this->assertSame($originalVerification, $verification);
        $after = $currentGrant->getAttributes();
        unset($original['verification'], $original['updated_at'], $after['verification'], $after['updated_at']);
        $this->assertSame($original, $after);
        $this->assertSame($approvalBefore, $oldApproval->fresh()->getAttributes());
        $this->assertSame($reviewHistory, $transfer->fresh()->raw_data['sgd_local_workflow']['review_history']);
        $this->assertSame($calls, count(Http::recorded()), 'Risk preparation cannot acquire FX or submit NIUM requests.');
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('transfer_approvals', 1);
        $this->assertDatabaseCount('nium_payout_pilot_authorizations', 1);
        $url = '/api/admin/transfers/3/approve';
        $this->withToken($this->sgdToken($checker))->postJson($url)->assertUnprocessable();
        $this->assertDatabaseCount('transfer_approvals', 1);
        $terms = app(NiumPayoutPilotPolicy::class)->terms($transfer->fresh());
        $this->assertSame($history[0]['id'], $terms['authorization_revision']);
        $this->assertNull($terms['fee_amount']);
        $this->assertNull($terms['total_wallet_debit']);
        $this->withToken($this->sgdToken($transfer->user))->postJson('/api/user/users/12/transfers/3/sgd-local-request-approval',
            ['financial_terms_token' => NiumSgdLocalPayoutWorkflow::financialTermsToken($reviewHistory[0]['review']['snapshot']['financial_terms'])])
            ->assertUnprocessable();
        $this->withToken($this->sgdToken($transfer->user))->postJson('/api/user/users/12/transfers/3/sgd-local-request-approval',
            $this->reviewToken($transfer->fresh()))->assertOk();
        $this->fakeSgdProvider($outcome);
        $this->withToken($this->sgdToken($checker))->postJson($url)->assertOk()->assertJsonPath('transfer.status', $status);
        $this->postJson($url)->assertOk()->assertJsonPath('transfer.status', $status);
        $this->postJson('/api/admin/transfers/3/pilot-authorization/renew', $input)->assertUnprocessable();
        $current = $transfer->fresh();
        $this->assertSame($current->provider_operation_key, $grant->fresh()->consumed_operation_key);
        $this->assertSame($history, $grant->fresh()->verification['one_time_renewals']);
        $this->assertSame('5.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
        $this->assertDatabaseCount('transfer_approvals', 2);
        $this->assertSame($approvalBefore, $oldApproval->fresh()->getAttributes());
        $this->travelBack();
    }

    public function test_actual_uncapped_fees_reconcile_after_the_renewed_policy_and_fx_expire(): void
    {
        [$provider, $transfer, $checker, $grant] = $this->expiredTransferThreePilot();
        $this->renew($transfer, $checker, $this->confirmation($transfer, $checker));
        $this->submitSgd($transfer->fresh());
        $this->withToken($this->sgdToken($checker))->postJson('/api/admin/transfers/3/approve')->assertOk();
        $history = $grant->fresh()->verification['one_time_renewals'];
        $this->travel(11)->minutes();
        $this->fakeSgdProvider('completed', '1.25');
        $manager = app(ProviderTransferManager::class);
        $manager->syncTransferStatus($provider, $transfer->fresh());
        $completed = $transfer->fresh();
        $actual = $completed->raw_data['nium_actual_debit'];
        $this->assertSame('unexpected_charges_reconciled', $actual['state']);
        $this->assertSame('6.25000000', $actual['observed_total_debit']);
        $this->assertSame('-1.25000000', LedgerEntry::query()->where('entry_type', 'adjustment')->sole()->amount);
        $this->assertSame($history, $grant->fresh()->verification['one_time_renewals']);
        $count = LedgerEntry::count();
        $manager->syncTransferStatus($provider, $completed);
        $this->assertSame($count, LedgerEntry::count());
        $this->travelBack();
    }

    public function test_expired_fx_cannot_be_replaced_by_risk_renewal_or_admin_approval(): void
    {
        [, $transfer, $checker, $grant] = $this->expiredTransferThreePilot();
        $input = $this->confirmation($transfer, $checker);
        $this->travel(11)->minutes();
        $before = $transfer->fresh()->getAttributes();
        $grantBefore = $grant->getAttributes();
        $calls = count(Http::recorded());
        $this->withToken($this->sgdToken($checker))->postJson('/api/admin/transfers/3/pilot-authorization/renew', $input)->assertUnprocessable();
        $this->postJson('/api/admin/transfers/3/approve')->assertUnprocessable();
        $this->assertSame($before, $transfer->fresh()->getAttributes());
        $this->assertSame($grantBefore, $grant->fresh()->getAttributes());
        $this->assertSame($calls, count(Http::recorded()));
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->travelBack();
    }

    public function test_expired_pilot_blocks_maker_review_even_with_fresh_fx_until_new_risk_is_accepted(): void
    {
        [, $transfer] = $this->expiredTransferThreePilot();
        $this->withToken($this->sgdToken($transfer->user))->postJson('/api/user/users/12/transfers/3/sgd-local-request-approval',
            ['submission_token' => str_repeat('a', 64), 'accept_payment_terms' => true, 'purpose_confirmed' => true])->assertUnprocessable();
        $this->assertSame('draft', $transfer->fresh()->status);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->travelBack();
    }

    public static function blockedConfirmations(): array
    {
        return array_map(fn ($case) => [$case], ['declined_risk', 'stale_token', 'insufficient_balance', 'stale_balance',
            'revoked', 'consumed', 'disabled', 'changed_intent', 'other_transfer', 'existing_operation', 'ledger_entry',
            'consumed_fx', 'inactive_authorizer', 'missing_original_risk']);
    }

    #[DataProvider('blockedConfirmations')]
    public function test_risk_renewal_rejects_unsafe_or_unconfirmed_prerequisites(string $case): void
    {
        [, $transfer, $checker, $grant] = $this->expiredTransferThreePilot();
        $input = $this->confirmation($transfer, $checker);
        $url = '/api/admin/transfers/3/pilot-authorization/renew';
        match ($case) {
            'declined_risk' => $input['accept_uncapped_charges'] = false,
            'stale_token' => $input['confirmation_token'] = str_repeat('0', 64),
            'insufficient_balance' => Balance::query()->sole()->update(['available_balance' => '4.99']),
            'stale_balance' => Balance::query()->sole()->update(['as_of' => now()->subMinutes(6)]),
            'revoked' => $grant->forceFill(['revoked_at' => now()])->save(),
            'consumed' => $grant->forceFill(['consumed_operation_key' => 'synthetic-existing-operation', 'consumed_at' => now()])->save(),
            'disabled' => config()->set('services.nium.payout_pilot.enabled', false),
            'changed_intent' => $transfer->update(['purpose_code' => 'ALTERNATE_SYNTHETIC_PURPOSE']),
            'other_transfer' => $url = '/api/admin/transfers/1/pilot-authorization/renew',
            'existing_operation' => $transfer->update(['provider_operation_key' => 'synthetic-existing-operation']),
            'ledger_entry' => LedgerEntry::query()->create(['balance_id' => Balance::query()->sole()->id,
                'user_id' => 12, 'provider_id' => $transfer->provider_id, 'reference' => 'synthetic-existing-hold',
                'entry_type' => 'hold', 'status' => 'posted', 'currency' => 'USD', 'amount' => '-5',
                'balance_after' => '80', 'source_type' => 'transfer', 'source_id' => '3', 'posted_at' => now()]),
            'consumed_fx' => $transfer->fxQuote->update(['raw_data' => array_merge($transfer->fxQuote->raw_data,
                ['consumed_at' => now()->toISOString(), 'consumed_operation_key' => 'synthetic-other-operation'])]),
            'inactive_authorizer' => $checker->update(['status' => 'inactive']),
            'missing_original_risk' => $grant->forceFill(['verification' => ['authorization_reference' => 'synthetic-invalid']])->save(),
        };
        $before = $grant->fresh()->getAttributes();
        $calls = count(Http::recorded());
        $this->withToken($this->sgdToken($checker))->postJson($url, $input)->assertUnprocessable();
        $this->assertSame($before, $grant->fresh()->getAttributes());
        $this->assertSame($calls, count(Http::recorded()));
        $this->assertDatabaseCount('ledger_entries', $case === 'ledger_entry' ? 1 : 0);
        $this->travelBack();
    }

    public function test_old_preview_cannot_create_a_second_acceptance_and_insufficient_balance_cannot_dispatch(): void
    {
        [, $transfer, $checker, $grant] = $this->expiredTransferThreePilot();
        $input = $this->confirmation($transfer, $checker);
        $this->renew($transfer, $checker, $input);
        $input['request_id'] = Str::uuid()->toString();
        $this->withToken($this->sgdToken($checker))->postJson('/api/admin/transfers/3/pilot-authorization/renew', $input)->assertUnprocessable();
        $this->assertCount(1, $grant->fresh()->verification['one_time_renewals']);
        $this->submitSgd($transfer->fresh());
        Balance::query()->sole()->update(['available_balance' => '4.99']);
        $this->withToken($this->sgdToken($checker))->postJson('/api/admin/transfers/3/approve')->assertUnprocessable();
        $this->assertNull($transfer->fresh()->provider_operation_key);
        $this->assertNull($grant->fresh()->consumed_at);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('transfer_approvals', 1);
        $this->travelBack();
    }

    public static function invalidRenewalHistory(): array
    {
        return [['predecessor'], ['wallet'], ['fx_rate'], ['risk'], ['early_acceptance']];
    }

    #[DataProvider('invalidRenewalHistory')]
    public function test_invalid_renewal_history_cannot_authorize_approval(string $case): void
    {
        [, $transfer, $checker, $grant] = $this->expiredTransferThreePilot();
        $this->renew($transfer, $checker, $this->confirmation($transfer, $checker));
        $this->submitSgd($transfer->fresh());
        $verification = $grant->fresh()->verification;
        $receipt = &$verification['one_time_renewals'][0];
        match ($case) {
            'predecessor' => $receipt['previous_revision'] = 'forged-predecessor',
            'wallet' => $receipt['binding']['snapshot']['provider_context']['wallet'] = 'different-wallet',
            'fx_rate' => $receipt['binding']['snapshot']['rate'] = '1.999999999',
            'risk' => $receipt['verification']['risk_acknowledgement'] = 'zero_fees_verified',
            'early_acceptance' => $receipt['verification']['authorized_at'] = $grant->expires_at->copy()->subSecond()->toISOString(),
        };
        $grant->forceFill(['verification' => $verification])->save();
        $this->withToken($this->sgdToken($checker))->postJson('/api/admin/transfers/3/approve')->assertUnprocessable();
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertNull($grant->fresh()->consumed_at);
        $this->assertDatabaseCount('transfer_approvals', 1);
        $this->travelBack();
    }

    public function test_renewed_pilot_expiry_requires_another_explicit_risk_acceptance(): void
    {
        [, $transfer, $checker, $grant] = $this->expiredTransferThreePilot();
        $input = $this->confirmation($transfer, $checker);
        $this->renew($transfer, $checker, $input);
        $this->submitSgd($transfer->fresh());
        $first = $grant->fresh()->verification['one_time_renewals'][0];
        $this->travel(11)->minutes();
        try {
            app(NiumPayoutPilotPolicy::class)->terms($transfer->fresh());
            $this->fail('An expired pilot cannot authorize a payment.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('one-use pilot authorization', $exception->getMessage());
        }
        $this->withToken($this->sgdToken($checker))->postJson('/api/admin/transfers/3/approve')->assertUnprocessable();
        $workflow = app(NiumSgdLocalPayoutWorkflow::class);
        $draft = $workflow->restartExpiredReview($transfer->fresh());
        $fresh = $workflow->requestLock($draft, Str::uuid()->toString());
        Balance::query()->sole()->update(['as_of' => now()]);
        $this->postJson('/api/admin/transfers/3/pilot-authorization/renew', $input)->assertUnprocessable();
        $this->renew($fresh, $checker, $this->confirmation($fresh, $checker));
        $history = $grant->fresh()->verification['one_time_renewals'];
        $this->assertCount(2, $history);
        $this->assertSame($first, $history[0]);
        $this->assertSame($first['id'], $history[1]['previous_revision']);
        $this->assertNull($grant->fresh()->consumed_at);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('transfer_approvals', 1);
        $this->travelBack();
    }

    public function test_authentication_permissions_and_maker_checker_independence_are_required(): void
    {
        [, $transfer, $checker, $grant] = $this->expiredTransferThreePilot();
        $input = $this->confirmation($transfer, $checker);
        $url = '/api/admin/transfers/3/pilot-authorization/renew';
        $this->postJson($url, $input)->assertUnauthorized();
        $unauthorized = User::factory()->create(['status' => 'active']);
        $this->withToken($this->sgdToken($unauthorized))->postJson($url, $input)->assertForbidden();
        $transfer->user->roles()->create(['role_code' => 'admin']);
        $this->withToken($this->sgdToken($transfer->user))->postJson($url, $input)->assertUnprocessable();
        $this->assertArrayNotHasKey('one_time_renewals', $grant->fresh()->verification);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->travelBack();
    }
}
