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
use App\Services\Nium\NiumPayoutFxLockService;
use App\Services\Nium\NiumService;
use App\Services\Nium\NiumSgdLocalRemittancePayload;
use App\Services\Nium\NiumSgdLocalZeroFeePolicy;
use App\Services\Wallet\TransferApprovalService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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

    private function reviewed(string $amount = '7.25', int $quoteMinutes = 5): array
    {
        [, $transfer, $checker] = $this->sgdExecutionFixture(false, ['amount' => $amount, 'quote_minutes' => $quoteMinutes]);
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
        return [['5'], ['7.25'], ['12.34000001']];
    }

    #[DataProvider('principals')]
    public function test_standard_customer_checker_dispatch_and_automatic_sync_use_the_business_terms_once(string $amount): void
    {
        config()->set('wallet.transfer_controls.require_admin_approval', false);
        [$transfer, $checker, $url, $payload] = $this->reviewed($amount, quoteMinutes: 15);
        $this->postJson($url, $payload)->assertCreated()->assertJsonPath('status', 'approval_required');
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('id', $transfer->id);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('transfer_approvals', 0);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/remittance'));
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
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/remittance') && $request->data() === [
            'beneficiary' => ['id' => 'synthetic-external-beneficiary'],
            'payout' => ['sourceAmount' => NiumSgdLocalRemittancePayload::wireAmount($amount),
                'sourceCurrency' => 'USD', 'quoteId' => $current->fxQuote->quote_ref],
            'purposeCode' => 'SYNTHETIC_PURPOSE', 'sourceOfFunds' => 'Corporate Account',
        ]);
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/remittance')));
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
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/remittance'));
    }

    public function test_fifteen_minute_quote_can_be_submitted_and_approved_after_the_old_five_minute_window(): void
    {
        [$transfer, $checker, $url, $payload] = $this->reviewed('5', quoteMinutes: 15);
        $quoteId = $transfer->fx_quote_id;
        $this->travel(7)->minutes();
        Balance::query()->update(['as_of' => now()]);
        $this->postJson($url, $payload)->assertCreated()->assertJsonPath('status', 'approval_required');
        $this->assertDatabaseCount('ledger_entries', 0);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/remittance'));
        $this->travel(7)->minutes();
        Balance::query()->update(['as_of' => now()]);
        $this->withToken($this->sgdToken($checker))->postJson("/api/admin/transfers/{$transfer->id}/approve")
            ->assertOk()->assertJsonPath('transfer.status', 'pending');
        $current = $transfer->fresh();
        $this->assertSame($quoteId, $current->fx_quote_id);
        $this->assertSame('5', LedgerEntry::query()->where('entry_type', 'hold')->sole()->raw_data['financial_authorization']['total_wallet_debit']);
        $this->assertCount(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/quotes')));
        $this->assertCount(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/remittance')));
    }

    public static function fifteenMinuteExpiryStages(): array
    {
        return [['customer'], ['checker']];
    }

    #[DataProvider('fifteenMinuteExpiryStages')]
    public function test_expired_fifteen_minute_quote_requires_new_customer_review_never_automatic_refresh(string $stage): void
    {
        [$transfer, $checker, $url, $payload] = $this->reviewed('5', quoteMinutes: 15);
        if ($stage === 'checker') {
            $this->postJson($url, $payload)->assertCreated();
        }
        $this->travelTo($transfer->fxQuote->expires_at);
        Balance::query()->update(['as_of' => now()]);
        if ($stage === 'customer') {
            $this->postJson($url, $payload)->assertUnprocessable();
        } else {
            $this->withToken($this->sgdToken($checker))->postJson("/api/admin/transfers/{$transfer->id}/approve")
                ->assertUnprocessable();
        }
        $this->assertDatabaseCount('fx_quotes', 1);
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/remittance'));
    }

    public static function unapprovedLockCharges(): array
    {
        return [['markupRate', '0.01'], ['clientMarkupRate', '0.01'], ['exchangeRate', '1.29'],
            ['feeAmount', '0.01'], ['totalWalletDebit', '5.01']];
    }

    #[DataProvider('unapprovedLockCharges')]
    public function test_enabled_production_adapter_cannot_override_conflicting_fifteen_minute_quote_terms(string $field, string $value): void
    {
        [, $transfer] = $this->sgdExecutionFixture(false);
        $this->activate($transfer);
        $policy = config('services.nium.sgd_local_zero_fee');
        $requestId = Str::uuid()->toString();
        $raw = $transfer->raw_data;
        $raw['sgd_local_workflow']['state'] = 'locking';
        $raw['sgd_local_workflow']['lock_request_id'] = $requestId;
        $transfer->update(['raw_data' => $raw]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://nium.test/*/quotes' => Http::response([
            'id' => 'synthetic-fifteen-minute-quote', 'quoteType' => 'payout', 'quoteIntent' => 'EXECUTABLE',
            'sourceAmount' => '5', 'netExchangeRate' => '1.275939120',
            'expiryTime' => now()->addMinutes(15)->toISOString(), $field => $value,
        ])]);
        try {
            app(NiumPayoutFxLockService::class)->createSgdPreparationLock($transfer, $requestId);
            $this->fail('A business policy must never override contradictory quote charges or rates.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Submission is blocked', $exception->getMessage());
        }
        $this->assertSame($policy, config('services.nium.sgd_local_zero_fee'));
        $this->assertDatabaseCount('fx_quotes', 1); // Only the original fixture; conflicting quote is not usable.
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('transfer_approvals', 0);
        Http::assertSent(fn ($request) => ($request['lockPeriod'] ?? null) === '15_mins');
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/remittance'));
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
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/remittance'));
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
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/remittance'));
    }

    public static function quoteExpiryStages(): array
    {
        return [['customer'], ['checker'], ['dispatch']];
    }

    #[DataProvider('quoteExpiryStages')]
    public function test_payout_quote_expiry_cannot_be_overridden_by_active_zero_fee_terms(string $stage): void
    {
        [$transfer, $checker, $url, $payload] = $this->reviewed('5');
        if ($stage !== 'customer') {
            $this->postJson($url, $payload)->assertCreated();
        }
        if ($stage === 'dispatch') {
            app(TransferApprovalService::class)->approve($transfer->fresh(), $checker);
        }
        $calls = count(Http::recorded());
        $this->travelTo($transfer->fxQuote->expires_at);
        if ($stage === 'customer') {
            $this->postJson($url, $payload)->assertUnprocessable();
        } elseif ($stage === 'checker') {
            $this->withToken($this->sgdToken($checker))
                ->postJson("/api/admin/transfers/{$transfer->id}/approve")->assertUnprocessable();
        } else {
            try {
                app(ProviderTransferManager::class)->submitTransfer($transfer->provider, $transfer->fresh());
                $this->fail('Expired payout quote reached dispatch.');
            } catch (RuntimeException|ValidationException) {
            }
        }
        $this->assertSame($calls, count(Http::recorded()));
        $this->assertDatabaseCount('fx_quotes', 1);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertNull($transfer->fresh()->provider_operation_key);
        $this->assertSame('0', app(NiumPayoutFinancialControls::class)->assertFor($transfer->fresh())['fee_amount']);
    }

    public static function lastMomentChanges(): array
    {
        return [['fx_expiry'], ['policy_expiry'], ['confirmation_changed'], ['checker_revoked']];
    }

    #[DataProvider('lastMomentChanges')]
    public function test_final_send_guard_revalidates_quote_and_exact_business_authorization(string $change): void
    {
        [$transfer, $checker, $url, $payload] = $this->reviewed('5');
        $this->postJson($url, $payload)->assertCreated();
        $this->fakeSgdProvider();
        $guardReached = false;
        // This event runs after authentication and request construction, before our send guard.
        Event::listen(RequestSending::class, function (RequestSending $event) use ($transfer, $checker, $change, &$guardReached): void {
            if (! str_ends_with($event->request->url(), '/remittance')) {
                return;
            }
            $guardReached = true;
            $current = $transfer->fresh();
            $this->assertSame('submitting', $current->status);
            $this->assertNotNull($current->provider_operation_key);
            $this->assertNotNull($current->fxQuote->raw_data['consumed_at']);
            $this->assertDatabaseCount('ledger_entries', 1);
            match ($change) {
                'fx_expiry' => $this->travelTo($current->fxQuote->expires_at),
                'policy_expiry' => config()->set('services.nium.sgd_local_zero_fee.expires_at', now()->toISOString()),
                'confirmation_changed' => config()->set('services.nium.sgd_local_zero_fee.confirmation_reference', 'DIFFERENT-SYNTHETIC-CONFIRMATION'),
                'checker_revoked' => $checker->update(['status' => 'disabled']),
            };
        });
        $this->withToken($this->sgdToken($checker))->postJson("/api/admin/transfers/{$transfer->id}/approve")
            ->assertOk()->assertJsonPath('transfer.status', 'failed');
        $this->assertTrue($guardReached);
        $current = $transfer->fresh();
        $this->assertSame('payout_authorization_expired_before_dispatch', $current->failure_code);
        $this->assertNotNull($current->provider_operation_key);
        $this->assertArrayNotHasKey('dispatch_started_at', $current->raw_data['nium_execution']);
        $this->assertSame($current->provider_operation_key, $current->fxQuote->raw_data['consumed_operation_key']);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame('85.00000000', Balance::query()->sole()->ledger_balance);
        $this->assertDatabaseCount('ledger_entries', 2); // Owned hold and known-unsent release only.
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/remittance'));
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/quotes'));
        try {
            app(ProviderTransferManager::class)->submitTransfer($current->provider, $current);
            $this->fail('Known-unsent terminal operation must not dispatch again.');
        } catch (RuntimeException|ValidationException) {
        }
        Http::assertNothingSent();
    }

    public function test_review_and_saved_draft_share_exact_backend_terms_without_final_credit(): void
    {
        [$transfer, , $url] = $this->reviewed('5');
        $input = $transfer->only(['provider_id', 'beneficiary_id', 'source_amount', 'purpose_code', 'reference_text', 'client_reference']);
        $review = $this->postJson($url.'/review', $input)->assertOk();
        $saved = $this->getJson($url."/{$transfer->id}")->assertOk();
        $this->assertSame($review->json('payout_amounts'), $saved->json('payout_amounts'));
        $review->assertJsonPath('payout_amounts.total_wallet_debit', '5')
            ->assertJsonPath('payout_amounts.transfer_fee', ['amount' => '0', 'currency' => 'USD'])
            ->assertJsonPath('payout_amounts.estimated_conversion_amount', '6.3796956')
            ->assertJsonPath('payout_amounts.beneficiary_credit', null)
            ->assertJsonPath('payout_amounts.conversion_amount', null);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/quotes') && $request->data() === [
            'sourceCurrencyCode' => 'USD', 'destinationCurrencyCode' => 'SGD', 'sourceAmount' => 5,
            'customerHashId' => 'synthetic-customer', 'quoteType' => 'payout', 'quoteIntent' => 'EXECUTABLE',
            'conversionSchedule' => 'immediate', 'executionType' => 'at_conversion_time', 'lockPeriod' => '15_mins',
        ]);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'lockExchangeRate'));
    }

    public function test_expired_legacy_draft_can_explicitly_restart_but_cannot_reuse_its_audit_or_consent(): void
    {
        [$transfer, , $url, $oldPayload] = $this->reviewed('5');
        $quote = $transfer->fxQuote;
        $quote->update(['quote_ref' => '112233', 'expires_at' => now()->subMinute(), 'raw_data' => [
            'provider_fx_type' => 'payout_fx_lock', 'audit_id' => '112233',
            'payout_fx_lock_contract' => ['version' => 2, 'audit_id' => '112233'],
        ]]);
        $this->postJson($url, $oldPayload)->assertUnprocessable();
        $this->assertDatabaseCount('ledger_entries', 0);
        $input = $transfer->only(['provider_id', 'beneficiary_id', 'source_amount', 'purpose_code', 'reference_text', 'client_reference']);
        $this->fakeSgdProvider();
        $review = $this->postJson($url.'/review', [...$input, 'transfer_id' => $transfer->id])->assertOk()
            ->assertJsonPath('id', $transfer->id)->assertJsonPath('terms.fee_amount', '0')
            ->assertJsonPath('terms.total_wallet_debit', '5');
        $current = $transfer->fresh();
        $this->assertNotSame($quote->id, $current->fx_quote_id);
        $this->assertSame('modern_quote', $current->fxQuote->raw_data['provider_fx_type']);
        $this->assertSame($quote->id, $current->raw_data['sgd_local_workflow']['review_history'][0]['fx_quote_id']);
        $this->postJson($url, $oldPayload)->assertUnprocessable();
        $this->postJson($url, [...$oldPayload, 'review_token' => $review->json('review_token')])->assertCreated()
            ->assertJsonPath('status', 'approval_required');
        $this->assertSame('SYNTHETIC-OWNER-CONFIRMATION',
            $current->fresh()->raw_data['sgd_local_workflow']['customer_authorization']['instruction']['financial_terms']['business_confirmation']['confirmation_reference']);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->assertCount(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/quotes')));
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/remittance'));
    }

    public function test_competing_wire_claim_cannot_turn_a_guard_failure_into_release_of_a_dispatched_hold(): void
    {
        [$transfer, $checker, $url, $payload] = $this->reviewed('5');
        $this->postJson($url, $payload)->assertCreated();
        $this->fakeSgdProvider();
        $competingClaim = false;
        Event::listen(RequestSending::class, function (RequestSending $event) use ($transfer, &$competingClaim): void {
            if ($competingClaim || ! str_ends_with($event->request->url(), '/remittance')) {
                return;
            }
            $competingClaim = true;
            $current = $transfer->fresh();
            $approved = $current->raw_data['sgd_local_approval'];
            $nium = app(NiumService::class);
            // A second caller uses the same durable authority while the original is preparing HTTP.
            $nium->post($nium->path(config('services.nium.transfer_endpoint'), [
                'client' => $approved['provider_context']['client'], 'customer' => $approved['provider_context']['customer'],
                'wallet' => $approved['provider_context']['wallet'],
            ]), NiumSgdLocalRemittancePayload::fromSnapshot($approved), $current->user,
                $current->id, 'transfer_money', $current->provider_operation_key);
        });
        $this->withToken($this->sgdToken($checker))->postJson("/api/admin/transfers/{$transfer->id}/approve")
            ->assertOk()->assertJsonPath('transfer.status', 'submission_unknown');
        $current = $transfer->fresh();
        $this->assertTrue($competingClaim);
        $this->assertNotNull($current->raw_data['nium_execution']['dispatch_started_at']);
        $this->assertSame('5.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertCount(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/remittance')));
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
    }

    public function test_numeric_json_quote_rate_reaches_review_and_saved_payment_without_binary_rounding(): void
    {
        [$transfer, , $url, $payload] = $this->reviewed('5');
        $this->fakeSgdProvider(numericQuote: true);
        $input = $transfer->only(['provider_id', 'beneficiary_id', 'source_amount', 'purpose_code', 'reference_text']);
        $input['client_reference'] = 'SYNTHETIC-NUMERIC-QUOTE';
        $review = $this->postJson($url.'/review', $input)->assertOk()
            ->assertJsonPath('payout_amounts.fx_rate', '1.276738080')
            ->assertJsonPath('payout_amounts.estimated_conversion_amount', '6.3836904')
            ->assertJsonPath('payout_amounts.total_wallet_debit', '5')
            ->assertJsonPath('payout_amounts.beneficiary_credit', null);
        $this->getJson($url.'/'.$review->json('id'))->assertOk()
            ->assertJsonPath('payout_amounts.fx_rate', '1.276738080')
            ->assertJsonPath('payout_amounts.estimated_conversion_amount', '6.3836904');
        $this->postJson($url, [...$payload, 'client_reference' => $input['client_reference'],
            'review_token' => $review->json('review_token')])->assertCreated()
            ->assertJsonPath('status', 'approval_required');
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/remittance'));
    }

    public static function finalWalletLockExpiries(): array
    {
        return [['quote'], ['policy']];
    }

    #[DataProvider('finalWalletLockExpiries')]
    public function test_final_wallet_lock_wait_cannot_outlive_the_quote_or_zero_fee_policy(string $kind): void
    {
        [$transfer, $checker, $url, $payload] = $this->reviewed('5');
        $this->postJson($url, $payload)->assertCreated();
        $this->fakeSgdProvider();
        $atWire = false;
        $waitSimulated = false;
        $expiry = $transfer->fxQuote->expires_at;
        Event::listen(RequestSending::class, function (RequestSending $event) use (&$atWire): void {
            if (str_ends_with($event->request->url(), '/remittance')) {
                $atWire = true;
            }
        });
        DB::listen(function (QueryExecuted $query) use (&$atWire, &$waitSimulated, $expiry, $kind): void {
            if ($atWire && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"balances"')) {
                $atWire = false;
                $waitSimulated = true;
                if ($kind === 'quote') {
                    $this->travelTo($expiry);
                } else {
                    config()->set('services.nium.sgd_local_zero_fee.expires_at', now()->toISOString());
                }
            }
        });
        $this->withToken($this->sgdToken($checker))->postJson("/api/admin/transfers/{$transfer->id}/approve")
            ->assertOk()->assertJsonPath('transfer.status', 'failed');
        $this->assertTrue($waitSimulated);
        $current = $transfer->fresh();
        $this->assertArrayNotHasKey('dispatch_started_at', $current->raw_data['nium_execution']);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'release')->count());
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->travelBack();
    }
}
