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
use App\Services\Nium\DynamicConfirmation\DynamicPayoutEnvironment;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutFinancialAuthorization;
use App\Services\Nium\DynamicConfirmation\DynamicReservationLedger;
use App\Services\Nium\DynamicConfirmation\ExactProviderJson;
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
        private readonly NiumProviderAccountStateService $accounts,
        private readonly DynamicPayoutFinancialAuthorization $financial) {}

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
        $this->assertQuoteDestination($beneficiary->currency);
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
        $path = $this->quotePath($binding['base_url']);
        $expectedUrl = $binding['base_url'].$path;
        $dispatched = false;
        $response = $this->nium->post(path: $path, payload: $payload, user: $user,
            operation: 'dynamic_payout_reference_quote', externalReference: (string) $beneficiary->id,
            beforeDispatch: function (Request $outgoing) use ($expectedUrl, $path, $binding, $json, &$dispatched): void {
                $this->outsideTransaction();
                if ($this->quotePath($binding['base_url']) !== $path || $dispatched
                    || $this->nium->clientId() !== $binding['client']
                    || $outgoing->method() !== 'POST'
                    || $outgoing->url() !== $expectedUrl
                    || ExactProviderJson::decode($outgoing->body()) !== ExactProviderJson::decode($json)) {
                    throw new RuntimeException('dynamic_quote_transport_binding_changed');
                }
                $dispatched = true;
            });
        if (! $dispatched) {
            throw new RuntimeException('dynamic_quote_transport_binding_changed');
        }
        if ($this->quotePath($binding['base_url']) !== $path || $this->nium->clientId() !== $binding['client']) {
            throw new RuntimeException('dynamic_quote_binding_changed');
        }
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
        $this->quotePath($stored->binding['base_url']);
        $binding = $this->binding($user, $beneficiary, $wallet);
        $original = $stored->binding;
        unset($original['amount_mode'], $original['entered_amount']);
        ksort($original, SORT_STRING);
        if ($stored->user_id !== $user->id || $stored->beneficiary_id !== $beneficiary->id
            || $stored->balance_id !== $wallet->id || $binding !== $original
            || (string) $stored->provider_id !== (string) $beneficiary->provider_id
            || (string) $stored->provider_account_id !== (string) $binding['provider_account_id']) {
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
            $environment = $this->quoteEnvironment($stored->binding['base_url']);
            $context = $consumingIntent->confirmed_terms['context'] ?? [];
            $corridor = $context['corridor'] ?? [];
            $identity = $corridor['environment'] ?? '';
            $environment->assertBinding($context['provider_origin'] ?? match ($identity) {
                'production' => 'https://gateway.nium.com',
                'sandbox' => 'https://gatewaysandbox.nium.com',
                default => throw new RuntimeException('dynamic_provider_scope_unverified'),
            }, $identity);
            $key = NiumDynamicIntent::quoteConsumptionKey((int) $stored->provider_id, $environment->identity,
                $stored->binding['client'], $stored->binding['customer'], $stored->quote_id);
            if (! $consumingIntent->exists || $consumingIntent->user_id !== $user->id
                || (string) $consumingIntent->provider_id !== (string) $stored->provider_id
                || (string) $consumingIntent->provider_account_id !== (string) $stored->provider_account_id
                || (string) $consumingIntent->balance_id !== (string) $wallet->id
                || (string) $consumingIntent->beneficiary_id !== (string) $beneficiary->id
                || $consumingIntent->customer !== $stored->binding['customer']
                || $consumingIntent->source_wallet !== $stored->binding['wallet']
                || ($corridor['provider'] ?? null) !== 'nium'
                || ($corridor['client'] ?? null) !== $stored->binding['client']
                || ($corridor['customer'] ?? null) !== $stored->binding['customer']
                || ($corridor['fundingWallet'] ?? null) !== $stored->binding['wallet']
                || $consumingIntent->quote_consumption_key !== $key || $consumingIntent->quote_id !== $stored->quote_id) {
                throw new RuntimeException('dynamic_quote_already_consumed');
            }
        }
    }

    private function assertUnused(int $providerId, array $binding, string $quoteId): void
    {
        $environment = $this->quoteEnvironment($binding['base_url'])->identity;
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
        $beneficiary = Beneficiary::query()->findOrFail($stored->beneficiary_id);
        if (($stored->binding['customer_pricing_sha256'] ?? null) !== $this->financial->fingerprint($beneficiary)) {
            throw new RuntimeException('dynamic_quote_binding_changed');
        }

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
            'customer_pricing' => $this->financial->pricing($beneficiary),
            'origin_service_fee' => ['currency' => 'USD', 'amount' => '0'],
            'financial_verification' => 'CUSTOMER_PRICING_AUTHORIZED_PROVIDER_SETTLEMENT_UNVERIFIED',
            'review' => 'REQUIRED', 'admission' => 'REVIEW_REQUIRED', 'dispatch' => 'DENIED',
            'pricing_status' => 'CONTRACTUAL_CUSTOMER_TERMS', 'reason_codes' => []];
    }

    private function parsed(NiumDynamicPayoutQuote $stored): array
    {
        $b = $stored->binding;
        $this->quotePath($b['base_url']);
        $this->assertQuoteDestination($b['destination_currency']);
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
            || $beneficiary->payout_method !== 'LOCAL' || $beneficiary->currency === 'USD'
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
        $base = $this->quoteEnvironment()->origin;
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

        $binding = ['version' => 'nium-dynamic-reference-quote-v2', 'owner' => (string) $user->id,
            'provider_account_id' => $account->id, 'customer' => $account->external_customer_id,
            'wallet' => $wallet->external_account_id, 'balance_id' => $wallet->id,
            'beneficiary_id' => $beneficiary->id, 'destination_currency' => $beneficiary->currency,
            'current_receiving_record_sha256' => DynamicReceivingAccountBinding::fingerprint($beneficiary),
            'registration_log_id' => $evidence->apiRequestLogId, 'base_url' => $base, 'client' => $this->nium->clientId(),
            'registration_request_sha256' => $exchange->request_body_sha256,
            'registration_response_sha256' => $exchange->response_body_sha256,
            'customer_pricing_sha256' => $this->financial->fingerprint($beneficiary),
            'customer_verified_at' => $account->customer_id_verified_at->toISOString(),
            'wallet_verified_at' => $account->wallet_id_verified_at->toISOString()];
        ksort($binding, SORT_STRING);

        return $binding;
    }

    private function quoteEnvironment(?string $origin = null): DynamicPayoutEnvironment
    {
        $environment = DynamicPayoutEnvironment::configured();
        if ($origin !== null) {
            $environment->assertBinding($origin);
        }

        return $environment;
    }

    private function quotePath(?string $origin = null): string
    {
        $environment = $this->quoteEnvironment($origin);
        if (config('nium_dynamic_quotes.quote_contract_enabled', false) !== true
            || ! (bool) config('services.nium.payout_fx_enabled', false)) {
            throw new RuntimeException('dynamic_provider_quote_disabled');
        }
        $client = $this->nium->clientId();
        $path = $this->nium->path((string) config('services.nium.quote_endpoint'), ['client' => $client]);
        $environment->assertQuoteReferenceEndpoint($path, $client);

        return $path;
    }

    private function assertQuoteDestination(string $currency): void
    {
        // Preserve reference catalogue work without enrolling SGD Standard or HK
        // same-currency payments in this Dynamic Quote acquisition path.
        if (in_array($currency, ['SGD', 'USD'], true)) {
            throw new RuntimeException('dynamic_quote_local_eligibility_changed');
        }
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
