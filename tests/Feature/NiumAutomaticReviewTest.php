<?php

namespace Tests\Feature;

use App\Contracts\NiumRemittancePricing;
use App\Models\ApiRequestLog;
use App\Models\Balance;
use App\Models\Transfer;
use App\Services\Nium\NiumPayoutFinancialControls;
use App\Services\Nium\NiumPayoutPilotPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesSgdLocalExecutionFixtures;
use Tests\Concerns\RefreshesCommittedPayoutDatabase;
use Tests\TestCase;

/** HTTP workflow regressions with disposable SQLite and synthetic provider/pricing only. */
class NiumAutomaticReviewTest extends TestCase
{
    use CreatesSgdLocalExecutionFixtures, RefreshesCommittedPayoutDatabase;

    private function intent(?Transfer $transfer = null): array
    {
        return ['transfer_id' => $transfer?->id, 'provider_id' => 10, 'beneficiary_id' => 9,
            'source_amount' => '5', 'purpose_code' => 'SYNTHETIC_PURPOSE', 'reference_text' => $transfer?->reference_text,
            'client_reference' => $transfer?->client_reference ?? 'AUTOMATIC-REVIEW', 'preparation_id' => Str::uuid()->toString()];
    }

    private function locksSent(): int
    {
        return Http::recorded(fn ($request) => str_contains($request->url(), 'lockExchangeRate'))->count();
    }

    public function test_automatic_review_submit_and_admin_approval_dispatch_once_even_with_retries(): void
    {
        [, $fixture, $checker] = $this->sgdExecutionFixture();
        $this->withToken($this->sgdToken($fixture->user));
        $input = [...$this->intent(), 'reference_text' => 'CUSTOMER-REFERENCE'];
        $review = $this->postJson('/api/user/users/12/sgd-local-payouts/review', $input)->assertOk()
            ->assertJsonPath('status', 'draft')->assertJsonPath('reference_text', 'CUSTOMER-REFERENCE')
            ->assertJsonPath('payout_workflow.approval_request_allowed', true)
            ->assertJsonPath('payout_workflow.customer_authorization', null);
        $id = $review->json('id');
        $quote = $review->json('payout_workflow.customer_instruction.fx_quote_id');
        $this->postJson('/api/user/users/12/sgd-local-payouts/review', $input)->assertOk()
            ->assertJsonPath('id', $id)->assertJsonPath('payout_workflow.customer_instruction.fx_quote_id', $quote);
        $this->assertSame(2, $this->locksSent()); // Fixture plus exactly one automatically prepared hold.
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame(0, ApiRequestLog::where('operation', 'transfer_money')->count());
        $submit = ['submission_token' => $review->json('payout_workflow.submission_token'),
            'accept_payment_terms' => true, 'purpose_confirmed' => true];
        $url = "/api/user/users/12/transfers/$id/sgd-local-submit";
        $this->postJson($url, $submit)->assertOk()->assertJsonPath('status', 'approval_required');
        $this->postJson($url, $submit)->assertOk();
        $this->postJson('/api/user/users/12/sgd-local-payouts/review', $input)->assertOk()->assertJsonPath('status', 'approval_required');
        $this->assertSame(0, ApiRequestLog::where('operation', 'transfer_money')->count());
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->postJson("/api/admin/transfers/$id/approve")->assertForbidden();
        $this->withToken($this->sgdToken($checker))->postJson("/api/admin/transfers/$id/approve")->assertOk();
        $this->postJson("/api/admin/transfers/$id/approve")->assertOk();
        $this->assertSame(1, ApiRequestLog::where('operation', 'transfer_money')->count());
        $this->assertDatabaseCount('transfer_approvals', 1);
    }

    public function test_automatic_preparation_uses_the_bound_wallet_even_when_an_unrelated_snapshot_is_newer(): void
    {
        [, $transfer] = $this->sgdExecutionFixture();
        Balance::query()->create(['user_id' => 12, 'provider_id' => 10, 'external_account_id' => 'unrelated',
            'currency' => 'USD', 'available_balance' => '0', 'ledger_balance' => '0', 'reserved_balance' => '0',
            'as_of' => now()->addSecond()]);
        $this->withToken($this->sgdToken($transfer->user));
        $this->postJson('/api/user/users/12/sgd-local-payouts/review', $this->intent())->assertOk()
            ->assertJsonPath('payout_workflow.approval_request_allowed', true);
        $this->assertSame(2, $this->locksSent());
        $this->assertSame(0, ApiRequestLog::where('operation', 'transfer_money')->count());
    }

    public function test_expired_review_reprepares_same_payment_but_old_consent_cannot_submit(): void
    {
        [, $transfer] = $this->sgdExecutionFixture();
        $old = $this->reviewToken($transfer);
        $this->travel(11)->minutes();
        Balance::query()->update(['as_of' => now()]);
        $this->withToken($this->sgdToken($transfer->user));
        $url = "/api/user/users/12/transfers/{$transfer->id}/sgd-local-submit";
        $this->postJson($url, $old)->assertUnprocessable();
        $review = $this->postJson('/api/user/users/12/sgd-local-payouts/review', $this->intent($transfer))->assertOk()
            ->assertJsonPath('id', $transfer->id)->assertJsonPath('status', 'draft')
            ->assertJsonPath('payout_workflow.approval_request_allowed', true);
        $this->assertNotSame($transfer->fx_quote_id, $review->json('payout_workflow.customer_instruction.fx_quote_id'));
        $this->postJson($url, $old)->assertUnprocessable();
        $this->postJson($url, [...$old, 'submission_token' => $review->json('payout_workflow.submission_token')])
            ->assertOk()->assertJsonPath('status', 'approval_required');
        $this->assertSame(0, ApiRequestLog::where('operation', 'transfer_money')->count());
    }

    public function test_changed_fees_invalidate_consent_and_cannot_be_silently_accepted_on_refresh(): void
    {
        [, $transfer] = $this->sgdExecutionFixture();
        $old = $this->reviewToken($transfer);
        $this->withToken($this->sgdToken($transfer->user));
        $this->installSyntheticPricing($transfer, ['fee_amount' => '1', 'pricing_version' => 'NEW-FEES']);
        $this->postJson("/api/user/users/12/transfers/{$transfer->id}/sgd-local-submit", $old)->assertUnprocessable();
        $this->postJson('/api/user/users/12/sgd-local-payouts/review', $this->intent($transfer))->assertOk()
            ->assertJsonPath('payout_workflow.approval_request_allowed', false)
            ->assertJsonPath('payout_workflow.submission_token', null);
        $this->assertSame(1, $this->locksSent());
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame(0, ApiRequestLog::where('operation', 'transfer_money')->count());
        $this->installSyntheticPricing($transfer, ['pricing_version' => 'NEW-VALID-TERMS']);
        $review = $this->postJson('/api/user/users/12/sgd-local-payouts/review', $this->intent($transfer))->assertOk();
        $this->postJson("/api/user/users/12/transfers/{$transfer->id}/sgd-local-submit", $old)->assertUnprocessable();
        $this->postJson("/api/user/users/12/transfers/{$transfer->id}/sgd-local-submit",
            [...$old, 'submission_token' => $review->json('payout_workflow.submission_token')])->assertOk();
    }

    public function test_changed_terms_after_submit_reset_only_the_undispatched_review_without_another_hold(): void
    {
        [, $transfer, $checker] = $this->sgdExecutionFixture();
        $old = $this->reviewToken($transfer);
        $this->submitSgd($transfer);
        $this->installSyntheticPricing($transfer, ['pricing_version' => 'NEW-AFTER-SUBMIT']);
        $this->withToken($this->sgdToken($checker))->postJson("/api/admin/transfers/{$transfer->id}/approve")->assertUnprocessable();
        $this->withToken($this->sgdToken($transfer->user));
        $this->getJson("/api/user/users/12/transfers/{$transfer->id}")->assertOk()
            ->assertJsonPath('payout_workflow.can_refresh_review', true);
        $review = $this->postJson('/api/user/users/12/sgd-local-payouts/review', $this->intent($transfer))->assertOk()
            ->assertJsonPath('status', 'draft')->assertJsonPath('payout_workflow.customer_authorization', null)
            ->assertJsonPath('payout_workflow.customer_instruction.fx_quote_id', $transfer->fx_quote_id);
        $this->assertCount(1, $transfer->fresh()->raw_data['sgd_local_workflow']['review_history']);
        $this->postJson("/api/user/users/12/transfers/{$transfer->id}/sgd-local-submit", $old)->assertUnprocessable();
        $this->postJson("/api/user/users/12/transfers/{$transfer->id}/sgd-local-submit",
            [...$old, 'submission_token' => $review->json('payout_workflow.submission_token')])->assertOk();
        $this->withToken($this->sgdToken($checker))->postJson("/api/admin/transfers/{$transfer->id}/approve")->assertOk();
        $this->assertSame(1, $this->locksSent());
        $this->assertSame(1, ApiRequestLog::where('operation', 'transfer_money')->count());
    }

    public function test_missing_pricing_is_refreshed_automatically_outside_a_transaction_and_reused(): void
    {
        [, $transfer] = $this->sgdExecutionFixture();
        $pricing = new class(app(NiumRemittancePricing::class)) implements NiumRemittancePricing
        {
            public int $refreshes = 0;

            public function __construct(private NiumRemittancePricing $confirmed) {}

            public function refresh(Transfer $transfer): void
            {
                TestCase::assertSame(0, DB::transactionLevel());
                $this->refreshes++;
            }

            public function quote(Transfer $transfer): array
            {
                return $this->refreshes > 0 ? $this->confirmed->quote($transfer) : ['status' => 'unavailable'];
            }
        };
        $this->app->instance(NiumRemittancePricing::class, $pricing);
        // Financial controls may have been resolved while building the fixture.
        $this->app->forgetInstance(NiumPayoutFinancialControls::class);
        $this->withToken($this->sgdToken($transfer->user));
        $input = $this->intent($transfer);
        $this->postJson('/api/user/users/12/sgd-local-payouts/review', $input)->assertOk()
            ->assertJsonPath('payout_workflow.approval_request_allowed', true);
        $this->postJson('/api/user/users/12/sgd-local-payouts/review', $input)->assertOk();
        $this->assertSame(1, $pricing->refreshes);
        $this->assertSame(1, $this->locksSent());
        $this->assertSame(0, ApiRequestLog::where('operation', 'transfer_money')->count());
    }

    public function test_uncertain_preparation_is_never_repeated_or_dispatched(): void
    {
        [, $transfer] = $this->sgdExecutionFixture();
        $raw = $transfer->raw_data;
        $raw['sgd_local_workflow']['state'] = 'lock_unknown';
        $transfer->update(['fx_quote_id' => null, 'fx_rate' => null, 'raw_data' => $raw]);
        $this->withToken($this->sgdToken($transfer->user));
        $input = $this->intent($transfer);
        $this->postJson('/api/user/users/12/sgd-local-payouts/review', $input)->assertUnprocessable()
            ->assertJsonPath('transfer.payout_workflow.state', 'lock_unknown');
        $this->postJson('/api/user/users/12/sgd-local-payouts/review', $input)->assertUnprocessable();
        $this->assertSame(1, $this->locksSent());
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame(0, ApiRequestLog::where('operation', 'transfer_money')->count());
    }

    public function test_preparation_preserves_exact_pilot_grant_and_cannot_generalize_it(): void
    {
        [, $transfer, $checker] = $this->sgdExecutionFixture(false);
        $grant = app(NiumPayoutPilotPolicy::class)->authorizeOneTime($transfer, $checker, 'SYNTHETIC-ONLY', true);
        config()->set('services.nium.payout_pilot', ['enabled' => true, 'authorization_id' => $grant->id]);
        $this->withToken($this->sgdToken($transfer->user));
        $this->postJson('/api/user/users/12/sgd-local-payouts/review', $this->intent($transfer))->assertOk()
            ->assertJsonPath('payout_workflow.customer_instruction.financial_terms.policy', 'one_time_uncapped');
        // Use unavailable synthetic pricing: another USD 5 instruction does not inherit the grant.
        $this->installSyntheticPricing($transfer, ['status' => 'unavailable']);
        $this->postJson('/api/user/users/12/sgd-local-payouts/review', $this->intent())->assertOk()
            ->assertJsonPath('payout_workflow.approval_request_allowed', false)
            ->assertJsonPath('payout_workflow.customer_instruction', null);
        $this->assertNull($grant->fresh()->consumed_operation_key);
        $this->assertSame(0, ApiRequestLog::where('operation', 'transfer_money')->count());
    }

    public function test_automatic_expiry_recovery_cannot_renew_the_one_time_pilot_grant(): void
    {
        [, $transfer, $checker] = $this->sgdExecutionFixture(false);
        $grant = app(NiumPayoutPilotPolicy::class)->authorizeOneTime($transfer, $checker, 'SYNTHETIC-ONLY', true);
        config()->set('services.nium.payout_pilot', ['enabled' => true, 'authorization_id' => $grant->id]);
        $original = $grant->fresh()->getAttributes();
        $this->travel(11)->minutes();
        Balance::query()->update(['as_of' => now()]);
        $this->installSyntheticPricing($transfer); // Even ordinary confirmed pricing cannot override an invalid pilot binding.
        $this->withToken($this->sgdToken($transfer->user));
        $this->postJson('/api/user/users/12/sgd-local-payouts/review', $this->intent($transfer))->assertOk()
            ->assertJsonPath('id', $transfer->id)->assertJsonPath('payout_workflow.approval_request_allowed', false)
            ->assertJsonPath('payout_workflow.customer_authorization', null);
        $this->assertSame($original, $grant->fresh()->getAttributes());
        $this->assertSame(2, $this->locksSent());
        $this->assertSame(0, ApiRequestLog::where('operation', 'transfer_money')->count());
    }

    public static function providerFailures(): array
    {
        return [['timeout'], ['500'], ['missing_reference']];
    }

    #[DataProvider('providerFailures')]
    public function test_admin_failure_or_ambiguous_response_cannot_dispatch_twice(string $outcome): void
    {
        [, $transfer, $checker] = $this->sgdExecutionFixture();
        $this->withToken($this->sgdToken($transfer->user));
        $review = $this->postJson('/api/user/users/12/sgd-local-payouts/review', $this->intent($transfer))->assertOk();
        $this->postJson("/api/user/users/12/transfers/{$transfer->id}/sgd-local-submit", [
            'submission_token' => $review->json('payout_workflow.submission_token'),
            'accept_payment_terms' => true, 'purpose_confirmed' => true,
        ])->assertOk();
        $this->fakeSgdProvider($outcome);
        $this->withToken($this->sgdToken($checker));
        $url = "/api/admin/transfers/{$transfer->id}/approve";
        $this->postJson($url);
        $this->postJson($url);
        $this->assertSame(1, ApiRequestLog::where('operation', 'transfer_money')->count());
        $this->assertContains($transfer->fresh()->status, ['submission_unknown', 'failed']);
        $this->assertDatabaseCount('transfer_approvals', 1);
    }

    public function test_preparation_rejects_changed_saved_details_and_other_customers(): void
    {
        [, $transfer, $checker] = $this->sgdExecutionFixture();
        $input = $this->intent($transfer);
        $this->withToken($this->sgdToken($transfer->user));
        foreach (['beneficiary_id' => 99, 'source_amount' => '4', 'purpose_code' => 'ALTERED', 'reference_text' => 'ALTERED'] as $field => $value) {
            $this->postJson('/api/user/users/12/sgd-local-payouts/review', [...$input, $field => $value])->assertUnprocessable();
        }
        $this->withToken($this->sgdToken($checker))->postJson('/api/user/users/12/sgd-local-payouts/review', $input)->assertForbidden();
        $this->assertSame(1, $this->locksSent());
        $this->assertDatabaseCount('ledger_entries', 0);
    }
}
