<?php

declare(strict_types=1);

namespace App\Services\Nium;

use App\Services\Wallet\Concurrency\OwnerFirst;
use App\Domain\Nium\DynamicMultiCurrency\Financial\Decimal;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FinalizedUsdPayoutPreparation;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutAmountMode;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutRateQuote;
use App\Models\ApiRequestLog;
use App\Models\Balance;
use App\Models\Beneficiary;
use App\Models\NiumDynamicIntent;
use App\Models\NiumDynamicPayoutQuote;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\DynamicConfirmation\DynamicReceivingAccountBinding;
use App\Services\Nium\DynamicConfirmation\DynamicReservationLedger;
use App\Services\Nium\DynamicConfirmation\ExactProviderJson;
use App\Services\Nium\DynamicConfirmation\OriginDynamicPayoutEvidenceSource;
use Brick\Math\BigDecimal;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Actual provider rate acquisition, never fee/debit authority or a consent producer. */
final class NiumDynamicPayoutQuoteService
{
    public function __construct(private readonly NiumService $nium,
        private readonly NiumSupportedCorridorService $corridors,
        private readonly NiumBeneficiaryPayoutMethodResolver $registration,
        private readonly NiumProviderAccountStateService $accounts) {}

    /** Successful catalogue lookup permits reference quoting only, not execution. No configured override. */
    public function availability(User $user, Beneficiary $beneficiary, Balance $wallet): array
    {
        $this->outsideTransaction();
        $this->quotePath();
        $binding = $this->binding($user, $beneficiary, $wallet);
        $this->corridors->assertSupported($beneficiary, 'LOCAL', [], allowConfigured: false);

        return $binding;
    }

    public function create(User $user, Beneficiary $beneficiary, Balance $wallet,
        PayoutAmountMode $mode, string $enteredAmount): NiumDynamicPayoutQuote
    {
        $binding = $this->availability($user, $beneficiary, $wallet);
        $request = FinalizedUsdPayoutPreparation::quoteRequestForAmount(
            $binding['customer'], $beneficiary->currency, $mode, $enteredAmount);
        $binding['amount_mode'] = $mode->value;
        $binding['entered_amount'] = (new Decimal($enteredAmount))->value;
        $json = $request->json();
        // Existing authenticated transport accepts arrays. Verify its serialization exactly,
        // including again on the outgoing bytes; no float ever prices or persists money.
        $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (ExactProviderJson::decode(json_encode($payload, JSON_THROW_ON_ERROR)) !== ExactProviderJson::decode($json)) {
            throw new RuntimeException('dynamic_quote_request_precision_unavailable');
        }
        $path = $this->quotePath();
        $expectedUrl = $binding['base_url'].$path;
        $response = $this->nium->post(path: $path, payload: $payload, user: $user,
            operation: 'dynamic_payout_reference_quote', externalReference: (string) $beneficiary->id,
            beforeDispatch: function (Request $outgoing) use ($expectedUrl, $json): void {
                $this->outsideTransaction();
                if (config('nium_dynamic_quotes.quote_contract_enabled', false) !== true
                    || ! (bool) config('services.nium.payout_fx_enabled', false)
                    || $outgoing->method() !== 'POST'
                    || $outgoing->url() !== $expectedUrl
                    || ExactProviderJson::decode($outgoing->body()) !== ExactProviderJson::decode($json)) {
                    throw new RuntimeException('dynamic_quote_transport_binding_changed');
                }
            });
        if (! $response->successful()) {
            throw new RuntimeException('dynamic_provider_quote_unavailable');
        }
        $quote = PayoutRateQuote::referenceFromJson($response->body(), $request);
        $this->checkAmounts($quote);

        // No provider I/O inside locks. Refuse changed local identity/registration;
        // check current conservative funds again after HTTP.
        return OwnerFirst::users([$user->id], function () use ($user, $beneficiary, $wallet, $binding, $request, $response, $quote): NiumDynamicPayoutQuote {
            $owner = User::query()->lockForUpdate()->findOrFail($user->id);
            UserProviderAccount::query()->lockForUpdate()->findOrFail($binding['provider_account_id']);
            $recipient = Beneficiary::query()->lockForUpdate()->findOrFail($beneficiary->id);
            $balance = Balance::query()->lockForUpdate()->findOrFail($wallet->id);
            $current = $this->binding($owner, $recipient, $balance);
            $original = $binding;
            unset($original['amount_mode'], $original['entered_amount']);
            if ($current !== $original || $quote['expiresAt'] <= now()->toDateTimeImmutable()) {
                throw new RuntimeException('dynamic_quote_binding_changed');
            }
            $this->assertUnused((int) $recipient->provider_id, $binding, $quote['id']);
            $this->assertFunds($balance, FinalizedUsdPayoutPreparation::plannedDebitForPrincipal($quote['sourceAmount'])->amount->value);

            return NiumDynamicPayoutQuote::query()->forceCreate([
                'id' => (string) Str::uuid(), 'user_id' => $owner->id, 'provider_id' => $recipient->provider_id,
                'provider_account_id' => $binding['provider_account_id'], 'beneficiary_id' => $recipient->id,
                'balance_id' => $balance->id, 'quote_id' => $quote['id'], 'request_json' => $request->json(),
                'response_json' => $response->body(), 'binding' => $binding,
                'expires_at' => $quote['expiresAt'], 'created_at' => now(),
            ]);
        }, 3);
    }

    /** Revalidate stored provider references without ever turning them into a signed review. */
    public function assertCurrent(NiumDynamicPayoutQuote $stored, User $user, Beneficiary $beneficiary, Balance $wallet,
        ?NiumDynamicIntent $consumingIntent = null): void
    {
        $binding = $this->binding($user, $beneficiary, $wallet);
        $original = $stored->binding;
        unset($original['amount_mode'], $original['entered_amount']);
        ksort($original, SORT_STRING);
        if ($stored->user_id !== $user->id || $stored->beneficiary_id !== $beneficiary->id
            || $stored->balance_id !== $wallet->id || $binding !== $original) {
            throw new RuntimeException('dynamic_quote_binding_changed');
        }
        if ($stored->expires_at <= now()) {
            throw new RuntimeException('dynamic_quote_expired');
        }
        $quote = $this->parsed($stored);
        if ($quote['id'] !== $stored->quote_id || $quote['expiresAt'] != $stored->expires_at) {
            throw new RuntimeException('dynamic_quote_binding_changed');
        }
        if ($consumingIntent === null) {
            $this->assertUnused((int) $stored->provider_id, $stored->binding, $stored->quote_id);
            $this->assertFunds($wallet, FinalizedUsdPayoutPreparation::plannedDebitForPrincipal($quote['sourceAmount'])->amount->value);
        } else {
            $environment = $stored->binding['base_url'] === 'https://gateway.nium.com' ? 'production' : 'sandbox';
            $key = NiumDynamicIntent::quoteConsumptionKey((int) $stored->provider_id, $environment,
                $stored->binding['client'], $stored->binding['customer'], $stored->quote_id);
            if (! $consumingIntent->exists || $consumingIntent->user_id !== $user->id
                || $consumingIntent->quote_consumption_key !== $key || $consumingIntent->quote_id !== $stored->quote_id) {
                throw new RuntimeException('dynamic_quote_already_consumed');
            }
        }
    }

    private function assertUnused(int $providerId, array $binding, string $quoteId): void
    {
        $environment = match ($binding['base_url']) {
            'https://gatewaysandbox.nium.com' => 'sandbox',
            'https://gateway.nium.com' => 'production',
            default => throw new RuntimeException('dynamic_quote_environment_unverified'),
        };
        $key = NiumDynamicIntent::quoteConsumptionKey($providerId, $environment, $binding['client'],
            $binding['customer'], $quoteId);
        if (NiumDynamicIntent::query()->where('quote_consumption_key', $key)->exists()) {
            // Cancellation/release never makes a consumed quote available again.
            throw new RuntimeException('dynamic_quote_already_consumed');
        }
    }

    public function project(NiumDynamicPayoutQuote $stored): array
    {
        $quote = $this->parsed($stored);

        return ['status' => 'QUOTE_REFERENCE_ONLY', 'dynamic_quote_id' => $stored->id,
            'provider_quote_id' => $stored->quote_id, 'beneficiary_id' => $stored->beneficiary_id,
            'amount_mode' => $stored->binding['amount_mode'], 'entered_amount' => $stored->binding['entered_amount'],
            'source_currency' => 'USD', 'destination_currency' => $stored->binding['destination_currency'],
            'payout_rail' => 'LOCAL', 'quote_expires_at' => $stored->expires_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'quoted_principal' => ['currency' => 'USD', 'amount' => $quote['sourceAmount']->value],
            'planned_included_fee' => ['currency' => 'USD', 'amount' => FinalizedUsdPayoutPreparation::FEE_USD],
            'planned_total_usd_debit' => FinalizedUsdPayoutPreparation::plannedDebitForPrincipal($quote['sourceAmount'])->amount->value,
            'quoted_destination_amount' => ['currency' => $stored->binding['destination_currency'], 'amount' => $quote['destinationAmount']->value],
            'nium_quoted_fx_rate' => $quote['netExchangeRate']->value,
            'financial_verification' => 'UNVERIFIED', 'review' => 'DENIED', 'admission' => 'DENIED', 'dispatch' => 'DENIED',
            'reason_codes' => OriginDynamicPayoutEvidenceSource::MISSING_DEPENDENCIES];
    }

    private function parsed(NiumDynamicPayoutQuote $stored): array
    {
        $b = $stored->binding;
        $request = FinalizedUsdPayoutPreparation::quoteRequestForAmount($b['customer'], $b['destination_currency'],
            PayoutAmountMode::from($b['amount_mode']), $b['entered_amount']);
        if ($stored->request_json !== $request->json()) {
            throw new RuntimeException('dynamic_quote_binding_changed');
        }
        $quote = PayoutRateQuote::referenceFromJson($stored->response_json, $request);
        $this->checkAmounts($quote);

        return $quote;
    }

    private function binding(User $user, Beneficiary $beneficiary, Balance $wallet): array
    {
        if (config('nium_dynamic_quotes.quote_contract_enabled', false) !== true
            || ! (bool) config('services.nium.payout_fx_enabled', false)) {
            throw new RuntimeException('dynamic_provider_quote_disabled');
        }
        $account = $this->accounts->assertEligible($user);
        if ($user->status !== 'active' || ! in_array($user->kyc_status, ['verified', 'approved'], true)
            || $user->kycProfile?->status !== 'approved'
            || ! in_array($user->kycProfile?->applicant_type, ['individual', 'business'], true)
            || $beneficiary->user_id !== $user->id || $wallet->user_id !== $user->id
            || $beneficiary->provider_id !== $account->provider_id || $wallet->provider_id !== $account->provider_id
            || $beneficiary->provider?->code !== 'nium' || $beneficiary->provider?->status !== 'active'
            || ! in_array($beneficiary->status, ['active', 'approved', 'verified'], true)
            || ! in_array($beneficiary->beneficiary_type, ['individual', 'business'], true)
            || $beneficiary->payout_method !== 'LOCAL' || in_array($beneficiary->currency, ['USD', 'SGD'], true)
            || $wallet->currency !== 'USD' || $wallet->external_account_id !== $account->external_account_id
            || ! in_array($account->compliance_status, ['clear', 'approved'], true)
            || ! in_array($account->rfi_status, ['clear', 'closed', 'not_required'], true)
            || ! in_array($account->odd_status, ['clear', 'not_required'], true)
            || $account->reconciliation_status !== 'reconciled'
            || $account->customer_id_verified_at->isFuture() || $account->wallet_id_verified_at->isFuture()) {
            throw new RuntimeException('dynamic_quote_local_eligibility_changed');
        }
        $evidence = $this->registration->successfulRequestEvidence($beneficiary);
        $log = $evidence === null ? null : ApiRequestLog::query()->find($evidence->apiRequestLogId);
        $exchange = $log?->niumExchangeEvidence;
        $base = rtrim((string) config('services.nium.base_url'), '/');
        $path = $this->nium->path((string) config('services.nium.beneficiary_endpoint'),
            ['client' => $this->nium->clientId(), 'customer' => $account->external_customer_id]);
        // Both persisted URLs redact client/customer path identifiers. Do not mistake
        // this event for a complete registered receiving-account/customer attestation.
        $loggedUrl = $base.preg_replace('~/(client|customer)/[^/]+~', '/$1/[REDACTED]', $path);
        if ($evidence?->payoutMethod !== 'LOCAL' || $log === null || $log->created_at->isFuture()
            || $log->created_at->lessThan($account->customer_id_verified_at)
            || $log->client_hash_id !== $this->nium->clientId()
            || $exchange === null || ! $exchange->response_received || $exchange->capture_version !== 1
            || $exchange->request_method !== 'POST' || $exchange->request_url !== $loggedUrl
            || ! is_string($exchange->raw_request_body) || ! is_string($exchange->raw_response_body)
            || hash('sha256', $exchange->raw_request_body) !== $exchange->request_body_sha256
            || hash('sha256', $exchange->raw_response_body) !== $exchange->response_body_sha256) {
            throw new RuntimeException('dynamic_quote_registration_evidence_unavailable');
        }
        $registeredRequest = ExactProviderJson::decode($exchange->raw_request_body);
        $registeredResponse = ExactProviderJson::decode($exchange->raw_response_body);
        if (($registeredRequest['payoutMethod'] ?? null) !== 'LOCAL'
            || ($registeredRequest['destinationCountry'] ?? null) !== $beneficiary->country_code
            || ($registeredRequest['destinationCurrency'] ?? null) !== $beneficiary->currency
            || ($registeredResponse['beneficiaryHashId'] ?? $registeredResponse['data']['beneficiaryHashId'] ?? null)
                !== $beneficiary->external_beneficiary_id) {
            throw new RuntimeException('dynamic_quote_registration_evidence_unavailable');
        }

        $binding = ['version' => 'nium-dynamic-reference-quote-v1', 'owner' => (string) $user->id,
            'provider_account_id' => $account->id, 'customer' => $account->external_customer_id,
            'wallet' => $wallet->external_account_id, 'balance_id' => $wallet->id,
            'beneficiary_id' => $beneficiary->id, 'destination_currency' => $beneficiary->currency,
            'current_receiving_record_sha256' => DynamicReceivingAccountBinding::fingerprint($beneficiary),
            'registration_log_id' => $evidence->apiRequestLogId, 'base_url' => $base, 'client' => $this->nium->clientId(),
            'registration_request_sha256' => $exchange->request_body_sha256,
            'registration_response_sha256' => $exchange->response_body_sha256,
            'customer_verified_at' => $account->customer_id_verified_at->toISOString(),
            'wallet_verified_at' => $account->wallet_id_verified_at->toISOString()];
        ksort($binding, SORT_STRING);

        return $binding;
    }

    private function quotePath(): string
    {
        $base = rtrim((string) config('services.nium.base_url'), '/');
        if (! in_array($base, ['https://gatewaysandbox.nium.com', 'https://gateway.nium.com'], true)) {
            throw new RuntimeException('dynamic_quote_environment_unverified');
        }
        $path = $this->nium->path((string) config('services.nium.quote_endpoint'), ['client' => $this->nium->clientId()]);
        if ($path !== $this->nium->path('/api/v1/client/{clientHashId}/quotes', ['client' => $this->nium->clientId()])) {
            throw new RuntimeException('dynamic_quote_endpoint_unverified');
        }

        return $path;
    }

    private function checkAmounts(array $quote): void
    {
        if ($quote['expiresAt'] <= now()->toDateTimeImmutable()) {
            throw new RuntimeException('dynamic_quote_expired');
        }
        foreach (['sourceAmount', 'destinationAmount'] as $field) {
            if (! $quote[$field]->fitsScale(2) || strlen(explode('.', $quote[$field]->value)[0]) > 12) {
                throw new RuntimeException('dynamic_quote_amount_precision_unavailable');
            }
        }
        DynamicReservationLedger::storage(FinalizedUsdPayoutPreparation::plannedDebitForPrincipal($quote['sourceAmount'])->amount->value, true);
    }

    private function assertFunds(Balance $wallet, string $debit): void
    {
        $providerAvailable = $wallet->raw_data['provider_available_balance'] ?? null;
        if (! is_string($providerAvailable)) {
            throw new RuntimeException('dynamic_quote_wallet_balance_unavailable');
        }
        $ceiling = BigDecimal::of(DynamicReservationLedger::storage($providerAvailable))
            ->minus(DynamicReservationLedger::storage($wallet->reserved_balance));
        if ($ceiling->isLessThan($debit) || BigDecimal::of($wallet->available_balance)->isLessThan($debit)) {
            throw new RuntimeException('dynamic_insufficient_available_funds');
        }
    }

    private function outsideTransaction(): void
    {
        OwnerFirst::outside();
        if (DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
            throw new RuntimeException('dynamic_quote_requires_network_outside_transaction');
        }
    }
}
