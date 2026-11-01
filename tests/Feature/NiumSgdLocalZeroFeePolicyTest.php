<?php

namespace Tests\Feature;

use App\Contracts\NiumRemittancePricing;
use App\Models\ApiRequestLog;
use App\Models\Balance;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Nium\NiumConfiguredRemittancePricing;
use App\Services\Nium\NiumPayoutFinancialControls;
use App\Services\Nium\NiumSgdLocalZeroFeePolicy;
use App\Services\Wallet\TransferApprovalService;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesSgdLocalExecutionFixtures;
use Tests\Concerns\RefreshesCommittedPayoutDatabase;
use Tests\TestCase;

/** Synthetic business records and transport only; never NIUM verification or live fee evidence. */
class NiumSgdLocalZeroFeePolicyTest extends TestCase
{
    use CreatesSgdLocalExecutionFixtures, RefreshesCommittedPayoutDatabase;

    private function activate(Transfer $transfer): void
    {
        config()->set('services.nium.sgd_local_zero_fee', [
            'enabled' => true, 'version' => 'SYNTHETIC-BUSINESS-TERMS-V1',
            'confirmation_reference' => 'SYNTHETIC-OWNER-CONFIRMATION', 'confirmed_by' => 'Synthetic business owner',
            'confirmed_at' => now()->subHour()->toISOString(), 'effective_from' => now()->subMinutes(30)->toISOString(),
            'expires_at' => now()->addHour()->toISOString(),
            'provider_context' => app(NiumPayoutFinancialControls::class)->intent($transfer)['provider_context'],
            'terms' => NiumSgdLocalZeroFeePolicy::TERMS,
        ]);
    }

    private function assertBlocked(Transfer $transfer): void
    {
        try {
            app(NiumPayoutFinancialControls::class)->assertFor($transfer->fresh());
            $this->fail('An invalid or inapplicable business policy must not grant financial admission.');
        } catch (RuntimeException $exception) {
            $this->assertSame(NiumPayoutFinancialControls::BLOCK_REASON, $exception->getMessage());
        }
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('transfer_approvals', 0);
    }

    private function reviewed(string $amount = '7.25'): array
    {
        [, $transfer, $checker] = $this->sgdExecutionFixture(false, ['amount' => $amount]);
        $transfer->update(['reference_text' => 'Synthetic zero-fee instruction']);
        $this->activate($transfer);
        $this->withToken($this->sgdToken($transfer->user));
        $url = "/api/user/users/{$transfer->user_id}/transfers";
        $input = $transfer->only(['provider_id', 'beneficiary_id', 'source_amount', 'purpose_code', 'reference_text', 'client_reference']);
        $review = $this->postJson($url.'/review', $input)->assertOk()
            ->assertJsonPath('terms.fee_amount', '0')->assertJsonPath('terms.total_wallet_debit', $amount);
        $payload = [...$input, 'transfer_type' => 'payout', 'source_currency' => 'USD', 'target_currency' => 'SGD',
            'review_token' => $review->json('review_token'), 'accept_payment_terms' => true, 'purpose_confirmed' => true];

        return [$transfer->fresh(), $checker, $url, $payload];
    }

    public function test_default_policy_is_disabled_and_does_not_promote_business_metadata_to_provider_evidence(): void
    {
        [, $transfer] = $this->sgdExecutionFixture(false);
        $this->assertFalse(config('services.nium.sgd_local_zero_fee.enabled'));
        $raw = $transfer->raw_data;
        $raw['business_confirmation'] = ['enabled' => true, 'fee_amount' => '0', 'total_wallet_debit' => '5'];
        $transfer->update(['raw_data' => $raw]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertBlocked($transfer);
        Http::assertNothingSent();
    }

    public function test_active_terms_are_exact_local_business_authorization_without_a_provider_quote_or_capture(): void
    {
        [, $transfer] = $this->sgdExecutionFixture(false, ['amount' => '12.34000001']);
        $this->activate($transfer);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $pricing = app(NiumRemittancePricing::class);
        $this->assertInstanceOf(NiumConfiguredRemittancePricing::class, $pricing);
        $this->assertSame('unavailable', $pricing->quote($transfer)['status']);
        $before = ApiRequestLog::query()->count();
        $terms = app(NiumPayoutFinancialControls::class)->assertFor($transfer);
        $this->assertSame('business_owner_confirmation', $terms['admission_basis']);
        $this->assertSame('SYNTHETIC-OWNER-CONFIRMATION', $terms['business_confirmation']['confirmation_reference']);
        $this->assertSame(NiumSgdLocalZeroFeePolicy::TERMS, $terms['business_confirmation']['terms']);
        $this->assertSame('0', $terms['fee_amount']);
        $this->assertSame('USD', $terms['fee_currency']);
        $this->assertSame('12.34000001', $terms['total_wallet_debit']);
        $this->assertSame('12.34000001', $terms['business_policy']['reservation_amount']);
        foreach (['provider_verified', 'charges_complete', 'configuration_reference'] as $field) {
            $this->assertArrayNotHasKey($field, $terms);
        }
        $this->assertSame($before, ApiRequestLog::query()->count());
        Http::assertNothingSent();
    }

    public static function invalidPolicies(): array
    {
        return [
            'disabled' => ['enabled', false], 'string switch' => ['enabled', 'true'], 'numeric switch' => ['enabled', 1],
            'missing version' => ['version', null], 'missing reference' => ['confirmation_reference', ''],
            'missing owner' => ['confirmed_by', null], 'relative date' => ['expires_at', 'tomorrow'],
            'invalid calendar date' => ['confirmed_at', '2026-02-30T00:00:00Z'],
            'future start' => ['effective_from', 'future'], 'future confirmation' => ['confirmed_at', 'future'],
            'confirmation after start' => ['confirmed_at', 'now'], 'expired' => ['expires_at', 'now'],
            'reversed period' => ['expires_at', 'past'],
            'different environment' => ['provider_context.base_url', 'https://other.test'],
            'different client' => ['provider_context.client', 'other-client'],
            'different customer' => ['provider_context.customer', 'other-customer'],
            'different wallet' => ['provider_context.wallet', 'other-wallet'],
            'missing scope' => ['provider_context.wallet', null],
            'changed NIUM fee' => ['terms.nium_fee_amount', '1'],
            'changed Origin fee' => ['terms.origin_fee_amount', '1'],
            'additional debit' => ['terms.additional_debit_amount', '0.01'],
            'different debit basis' => ['terms.total_wallet_debit_basis', 'principal_plus_fees'],
            'different corridor' => ['terms.target_currency', 'USD'],
        ];
    }

    #[DataProvider('invalidPolicies')]
    public function test_invalid_policy_never_admits_or_reserves_funds(string $field, mixed $value): void
    {
        [, $transfer] = $this->sgdExecutionFixture(false);
        $this->activate($transfer);
        $value = match ($value) {
            'now' => now()->toISOString(), 'future' => now()->addDay()->toISOString(),
            'past' => now()->subDay()->toISOString(), default => $value,
        };
        config()->set('services.nium.sgd_local_zero_fee.'.$field, $value);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertBlocked($transfer);
        Http::assertNothingSent();
    }

    public static function unsupportedIntents(): array
    {
        return ['source currency' => [['source_currency' => 'EUR'], []],
            'HK USD SWIFT' => [['target_currency' => 'USD'], ['currency' => 'USD', 'country_code' => 'HK', 'payout_method' => 'SWIFT']],
            'bank-funded' => [['source_bank_account_id' => 999], []],
            'destination-driven' => [['target_amount' => '10'], []],
            'wrong destination' => [[], ['country_code' => 'HK']],
            'wrong rail' => [[], ['payout_method' => 'SWIFT']],
            'corporate recipient' => [[], ['beneficiary_type' => 'business']]];
    }

    #[DataProvider('unsupportedIntents')]
    public function test_policy_is_limited_to_the_existing_owned_registered_sgd_local_intent(array $transferChanges, array $beneficiaryChanges): void
    {
        [, $transfer] = $this->sgdExecutionFixture(false);
        $this->activate($transfer);
        // Unsaved mutations avoid inventing accounts/foreign keys for unsupported requests.
        $transfer->fill($transferChanges);
        $transfer->beneficiary->fill($beneficiaryChanges);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        try {
            app(NiumPayoutFinancialControls::class)->assertFor($transfer);
            $this->fail('An unsupported corridor or funding intent cannot use the SGD policy.');
        } catch (RuntimeException $exception) {
            $this->assertSame(NiumPayoutFinancialControls::BLOCK_REASON, $exception->getMessage());
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public static function principals(): array
    {
        return [['7.25'], ['12.34000001']];
    }

    #[DataProvider('principals')]
    public function test_standard_customer_checker_dispatch_and_automatic_sync_use_the_business_terms_once(string $amount): void
    {
        config()->set('wallet.transfer_controls.require_admin_approval', false);
        [$transfer, $checker, $url, $payload] = $this->reviewed($amount);
        $this->postJson($url, $payload)->assertCreated()->assertJsonPath('status', 'approval_required');
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('id', $transfer->id);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('transfer_approvals', 0);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $receipt = $transfer->fresh()->raw_data['sgd_local_workflow']['customer_authorization'];
        $terms = $receipt['instruction']['financial_terms'];
        $this->assertSame(NiumSgdLocalZeroFeePolicy::BASIS, $terms['admission_basis']);
        // Even an Admin maker cannot act as the independent checker.
        $transfer->user->roles()->create(['role_code' => 'admin']);
        $this->withToken($this->sgdToken($transfer->user));
        $approveUrl = "/api/admin/transfers/{$transfer->id}/approve";
        $this->postJson($approveUrl)->assertUnprocessable();
        $this->withToken($this->sgdToken($checker))->postJson($approveUrl)->assertOk()->assertJsonPath('transfer.status', 'pending');
        $this->postJson($approveUrl)->assertOk();
        $current = $transfer->fresh();
        $hold = LedgerEntry::query()->where('entry_type', 'hold')->sole();
        $this->assertSame($terms, $hold->raw_data['financial_authorization']);
        $this->assertSame('-'.bcadd($amount, '0', 8), $hold->amount);
        $this->assertSame($current->provider_operation_key, $hold->raw_data['operation_key']);
        $this->assertSame($current->provider_operation_key, $current->fxQuote->raw_data['consumed_operation_key']);
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'));
        $this->assertDatabaseCount('transfer_approvals', 1);
        $this->withToken($this->sgdToken($transfer->user));
        $public = $this->getJson($url."/{$transfer->id}")->assertOk();
        $this->assertStringNotContainsString('SYNTHETIC-OWNER-CONFIRMATION', $public->getContent());
        // Expired/disabled admission cannot prevent reconciliation of an already dispatched operation.
        $this->travel(2)->hours();
        config()->set('services.nium.sgd_local_zero_fee.enabled', false);
        $this->fakeSgdProvider('completed');
        config()->set('services.nium.transfer_reconciliation_enabled', true);
        $this->artisan('nium:reconcile-transfers')->assertExitCode(0);
        $this->artisan('nium:reconcile-transfers')->assertExitCode(0);
        $this->assertSame('completed', $transfer->fresh()->status);
        $this->assertSame('principal_reconciled', $transfer->fresh()->raw_data['nium_actual_debit']['state']);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame(bcsub('85', $amount, 8), Balance::query()->sole()->ledger_balance);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame(0, LedgerEntry::query()->whereIn('entry_type', ['release', 'adjustment'])->count());
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_business_admission_never_retries_an_unknown_submission_or_releases_its_hold(): void
    {
        [$transfer, $checker, $url, $payload] = $this->reviewed();
        $this->postJson($url, $payload)->assertCreated();
        $this->fakeSgdProvider('timeout');
        // A synchronous timeout fake throws before Http::recorded() can record the attempt.
        $postAttempts = 0;
        Http::globalRequestMiddleware(function ($request) use (&$postAttempts) {
            if ($request->getMethod() === 'POST') {
                $postAttempts++;
            }

            return $request;
        });
        $approveUrl = "/api/admin/transfers/{$transfer->id}/approve";
        $this->withToken($this->sgdToken($checker))->postJson($approveUrl)
            ->assertOk()->assertJsonPath('transfer.status', 'submission_unknown');
        $this->assertSame(1, $postAttempts);
        $operation = $transfer->fresh()->provider_operation_key;
        $this->postJson($approveUrl)->assertOk();
        config()->set('services.nium.transfer_reconciliation_enabled', true);
        $this->artisan('nium:reconcile-transfers')->assertExitCode(1);
        $this->assertSame('submission_unknown', $transfer->fresh()->status);
        $this->assertSame($operation, $transfer->fresh()->provider_operation_key);
        $this->assertNull($transfer->fresh()->external_transfer_id);
        $this->assertSame('7.25000000', Balance::query()->sole()->reserved_balance);
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertSame($operation, LedgerEntry::query()->sole()->raw_data['operation_key']);
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
        $this->assertSame(1, $postAttempts);
    }

    public static function fundingOrFxFailures(): array
    {
        return [['funds'], ['fx']];
    }

    #[DataProvider('fundingOrFxFailures')]
    public function test_business_terms_cannot_override_insufficient_funding_or_an_expired_fx_lock(string $case): void
    {
        [$transfer, $checker, $url, $payload] = $this->reviewed();
        $this->postJson($url, $payload)->assertCreated();
        if ($case === 'funds') {
            Balance::query()->sole()->update(['available_balance' => '0', 'ledger_balance' => '0']);
        } else {
            $transfer->fxQuote->update(['expires_at' => now()]);
        }
        $this->withToken($this->sgdToken($checker))->postJson("/api/admin/transfers/{$transfer->id}/approve")->assertUnprocessable();
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertNull($transfer->fresh()->provider_operation_key);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public static function authorizationChanges(): array
    {
        $cases = [];
        foreach (['customer', 'checker', 'dispatch'] as $stage) {
            foreach (['reference', 'expiry', 'disabled'] as $change) {
                $cases[$stage.' / '.$change] = [$stage, $change];
            }
        }

        return $cases;
    }

    #[DataProvider('authorizationChanges')]
    public function test_policy_changes_or_expiry_invalidate_reviewed_terms_before_submission(string $stage, string $change): void
    {
        [$transfer, $checker, $url, $payload] = $this->reviewed();
        if ($stage !== 'customer') {
            $this->postJson($url, $payload)->assertCreated();
        }
        if ($stage === 'dispatch') {
            $transfer = app(TransferApprovalService::class)->approve($transfer->fresh(), $checker);
        }
        $field = match ($change) {
            'reference' => 'confirmation_reference', 'expiry' => 'expires_at', 'disabled' => 'enabled',
        };
        $value = match ($change) {
            'reference' => 'CHANGED-SYNTHETIC-CONFIRMATION', 'expiry' => now()->toISOString(), 'disabled' => false,
        };
        config()->set('services.nium.sgd_local_zero_fee.'.$field, $value);
        if ($stage === 'customer') {
            $this->postJson($url, $payload)->assertUnprocessable();
        } elseif ($stage === 'checker') {
            $this->withToken($this->sgdToken($checker))->postJson("/api/admin/transfers/{$transfer->id}/approve")->assertUnprocessable();
        } else {
            try {
                app(ProviderTransferManager::class)->submitTransfer($transfer->provider, $transfer->fresh());
                $this->fail('Stale business authorization cannot dispatch.');
            } catch (RuntimeException|ValidationException) {
            }
        }
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('transfer_approvals', $stage === 'dispatch' ? 1 : 0);
        $this->assertNull($transfer->fresh()->provider_operation_key);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }
}
