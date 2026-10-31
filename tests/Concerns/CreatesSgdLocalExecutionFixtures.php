<?php

namespace Tests\Concerns;

use App\Contracts\NiumRemittancePricing;
use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Models\Balance;
use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\NiumApiExchangeEvidence;
use App\Models\Transfer;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumPayoutFinancialControls;
use App\Services\Nium\NiumPayoutFxLockContract;
use App\Services\Nium\NiumSgdLocalPayoutAuthorization;
use App\Services\Nium\NiumSgdLocalPayoutWorkflow;
use Brick\Math\BigDecimal;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/** Synthetic injected pricing only; never production configuration or NIUM evidence. */
trait CreatesSgdLocalExecutionFixtures
{
    protected function fakeSgdProvider(string $outcome = 'pending', ?string $feeAmount = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request, $options) use ($outcome, $feeAmount) {
            if ($request->method() === 'POST') {
                $this->assertFalse($options['allow_redirects']);
                if ($outcome === 'timeout') {
                    throw new ConnectionException('Synthetic timeout; no network');
                }
                if (ctype_digit($outcome)) {
                    return Http::response([], (int) $outcome, ['Location' => 'https://synthetic.invalid/redirect']);
                }

                return Http::response($outcome === 'missing_reference' ? [] : ['systemReferenceNumber' => 'synthetic-sgd-reference']);
            }
            if (str_contains($request->url(), 'purposeCodes')) {
                return Http::response([['purposeCode' => 'SYNTHETIC_PURPOSE', 'description' => 'Synthetic only'],
                    ['purposeCode' => 'ALTERNATE_SYNTHETIC_PURPOSE', 'description' => 'Another synthetic purpose']]);
            }
            if (str_contains($request->url(), 'supportedCorridors')) {
                return Http::response(['content' => [['destinationCountry' => 'SG', 'destinationCurrency' => 'SGD',
                    'payoutMethod' => 'LOCAL', 'beneficiaryAccountType' => 'INDIVIDUAL', 'customerType' => 'CORPORATE', 'routingCodeType' => 'SWIFT']]]);
            }
            if (str_contains($request->url(), 'lockExchangeRate')) {
                return Http::response(['audit_id' => random_int(100000, 999999), 'fx_hold_id' => 'synthetic-hold',
                    'fx_rate' => '1.275939120', 'source_currency' => 'USD', 'destination_currency' => 'SGD',
                    'status' => 'ACTIVE', 'hold_expiry_at' => now()->addMinutes(10)->toISOString()]);
            }
            if (str_contains($request->url(), '/transactions')) {
                $transfer = Transfer::query()->whereNotNull('provider_operation_key')->latest('id')->firstOrFail();
                $rows = $outcome === 'completed' ? [[
                    'authCode' => $transfer->external_transfer_id, 'transactionType' => 'Remittance_Debit_External',
                    'clientHashId' => 'synthetic-client', 'customerHashId' => 'synthetic-customer', 'walletHashId' => 'synthetic-wallet',
                    'authCurrencyCode' => 'USD', 'debit' => true, 'status' => 'Approved', 'settlementStatus' => 'Settled',
                    'authAmount' => $transfer->source_amount, 'settlementAuthAmount' => $transfer->source_amount,
                ]] : [];
                if ($feeAmount !== null && $rows !== []) {
                    $rows[] = array_replace($rows[0], ['authCode' => 'synthetic-fee', 'transactionType' => 'Fee_Debit',
                        'authAmount' => $feeAmount, 'settlementAuthAmount' => $feeAmount,
                        'labels' => ['originalAuthorizationCode' => $transfer->external_transfer_id]]);
                }

                return Http::response(['content' => $rows, 'totalPages' => $rows === [] ? 0 : 1, 'totalElements' => count($rows)]);
            }
            if (str_contains($request->url(), 'synthetic-sgd-reference')) {
                return Http::response([['status' => $outcome === 'completed' ? 'PAID' : 'FAILED']]);
            }
            throw new RuntimeException('Unexpected synthetic provider request');
        });
    }

    protected function sgdExecutionFixture(bool $withPricing = true, array $scenario = []): array
    {
        ['provider_id' => $providerId, 'user_id' => $userId, 'account_id' => $accountId,
            'beneficiary_id' => $beneficiaryId, 'amount' => $amount, 'purpose' => $purpose] = array_replace(
                ['provider_id' => 10, 'user_id' => 12, 'account_id' => 6, 'beneficiary_id' => 9, 'amount' => '5', 'purpose' => 'SYNTHETIC_PURPOSE'], $scenario);
        if (! Schema::hasColumn('transfers', 'provider_status')) {
            Schema::table('transfers', function (Blueprint $table): void {
                $table->string('provider_status', 80)->nullable();
                $table->text('provider_status_detail')->nullable();
            });
        }
        config()->set('services.nium.base_url', 'https://nium.test');
        config()->set('services.nium.client_id', 'synthetic-client');
        config()->set('services.nium.auth', ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'synthetic-only']);
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'synthetic-only');
        config()->set('services.nium.beneficiary_endpoint', '/api/v2/client/{clientHashId}/customer/{customerHashId}/beneficiaries');
        config()->set('services.nium.payout_fx_enabled', true);
        config()->set('services.nium.supported_corridors', []);
        $this->fakeSgdProvider();
        $provider = IntegrationProvider::query()->forceCreate(['id' => $providerId, 'code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        $maker = User::factory()->create(['id' => $userId, 'status' => 'active', 'kyc_status' => 'verified']);
        $maker->kycProfile()->create(['status' => 'approved', 'applicant_type' => 'business',
            'registered_country_code' => 'HK', 'country_code' => 'HK', 'metadata' => ['nium_region' => 'HK'],
            'legal_name' => 'Synthetic Corporate', 'address_line1' => 'Synthetic Road', 'city' => 'Hong Kong', 'postal_code' => '999077']);
        UserProviderAccount::query()->forceCreate(['id' => $accountId, 'provider_id' => $providerId, 'user_id' => $userId,
            'external_customer_id' => 'synthetic-customer', 'external_account_id' => 'synthetic-wallet',
            'status' => 'active', 'provider_status' => 'clear', 'reconciliation_status' => 'reconciled',
            'customer_id_verified_at' => now(), 'wallet_id_verified_at' => now(), 'provider_ids_verified_at' => now()]);
        Beneficiary::query()->forceCreate(['id' => $beneficiaryId, 'user_id' => $userId, 'provider_id' => $providerId,
            'external_beneficiary_id' => 'synthetic-external-beneficiary', 'beneficiary_type' => 'personal',
            'full_name' => 'Synthetic Individual', 'country_code' => 'SG', 'currency' => 'SGD',
            'payout_method' => 'LOCAL', 'swift_bic' => 'DBSSSGSGXXX', 'status' => 'active']);
        $log = ApiRequestLog::query()->create(['provider_id' => $providerId, 'user_id' => $userId, 'operation' => 'beneficiary_create',
            'external_reference' => (string) $beneficiaryId, 'client_hash_id' => 'synthetic-client', 'request_method' => 'POST',
            'request_url' => 'https://nium.test/api/v2/client/[REDACTED]/customer/[REDACTED]/beneficiaries',
            'request_body' => ['payout_method' => 'LOCAL'], 'response_status' => 200, 'is_success' => true,
            'response_body' => ['beneficiary_id' => 'synthetic-external-beneficiary']]);
        $raw = json_encode(['beneficiaryHashId' => 'synthetic-external-beneficiary', 'status' => 'ACTIVE']);
        NiumApiExchangeEvidence::query()->create(['api_request_log_id' => $log->id, 'request_method' => 'POST',
            'request_url' => $log->request_url, 'raw_response_body' => $raw, 'response_body_sha256' => hash('sha256', $raw),
            'response_received' => true, 'capture_version' => 1]);
        Balance::query()->create(['provider_id' => $providerId, 'user_id' => $userId, 'external_account_id' => 'synthetic-wallet', 'currency' => 'USD', 'available_balance' => 85,
            'ledger_balance' => 85, 'reserved_balance' => 0, 'as_of' => now()]);
        $checker = User::factory()->create(['status' => 'active']);
        $checker->roles()->create(['role_code' => 'admin']);
        $transfer = app(NiumSgdLocalPayoutWorkflow::class)->prepare($maker, ['provider_id' => $providerId, 'beneficiary_id' => $beneficiaryId,
            'source_currency' => 'USD', 'target_currency' => 'SGD', 'source_amount' => $amount, 'purpose_code' => $purpose,
            'client_reference' => 'SYNTHETIC-EXECUTION', 'expected_fee_amount' => '0', 'expected_fee_currency' => 'USD']);
        $transfer = app(NiumSgdLocalPayoutWorkflow::class)->requestLock($transfer, Str::uuid()->toString());
        if ($withPricing) {
            $this->installSyntheticPricing($transfer);
        }

        return [$provider, $transfer->fresh(), $checker];
    }

    protected function installSyntheticPricing(Transfer $transfer, array $changes = []): void
    {
        $context = app(NiumPayoutFinancialControls::class)->intent($transfer)['provider_context'];
        $expires = now()->addDay()->toISOString();
        $this->app->instance(NiumRemittancePricing::class, new class($context, $expires, $changes) implements NiumRemittancePricing
        {
            public function __construct(private array $context, private string $expires, private array $changes) {}

            public function refresh(Transfer $transfer): void {}

            public function quote(Transfer $transfer): array
            {
                $intent = app(NiumPayoutFinancialControls::class)->intent($transfer);
                if (! NiumPayoutFxLockContract::sameBinding($intent['provider_context'], $this->context)) {
                    return ['status' => 'unavailable'];
                }
                $fee = $this->changes['fee_amount'] ?? '0';

                return array_replace_recursive(['status' => 'confirmed', 'intent' => $intent,
                    'pricing_version' => 'SYNTHETIC-PRICING-ONLY', 'expires_at' => $this->expires,
                    'charges_complete' => true, 'debit_basis' => 'principal_plus_source_wallet_fees',
                    'fee_amount' => '0', 'fee_currency' => 'USD',
                    'total_wallet_debit' => (string) BigDecimal::of($intent['source_amount'])->plus(is_string($fee) && is_numeric($fee) ? $fee : '0'),
                ], $this->changes);
            }
        });
    }

    protected function reviewToken(Transfer $transfer): array
    {
        $instruction = app(NiumSgdLocalPayoutAuthorization::class)->customerInstruction($transfer);

        return ['submission_token' => NiumSgdLocalPayoutWorkflow::financialTermsToken($instruction),
            'accept_payment_terms' => true, 'purpose_confirmed' => true, 'accept_uncapped_charges' => true];
    }

    protected function submitSgd(Transfer $transfer): Transfer
    {
        return app(NiumSgdLocalPayoutWorkflow::class)->submit($transfer, $transfer->user,
            $this->reviewToken($transfer)['submission_token'], true);
    }

    protected function sgdToken(User $user): string
    {
        $token = Str::random(64);
        ApiToken::query()->create(['user_id' => $user->id, 'name' => 'synthetic', 'token_hash' => hash('sha256', $token)]);

        return $token;
    }
}
