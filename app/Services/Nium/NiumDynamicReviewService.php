<?php

declare(strict_types=1);

namespace App\Services\Nium;

use App\Domain\Nium\DynamicMultiCurrency\{CorridorEvidence, CorridorIdentity, EvidenceKind, EvidenceState};
use App\Domain\Nium\DynamicMultiCurrency\Financial\{Decimal, FeeAllocation, FeePayer, FinalizedUsdPayoutPreparation, FinancialTerms, FxRequirement, Money, PayoutAmountMode, PayoutConfirmation, PayoutContract, PayoutInstruction, PayoutQuoteContext, PayoutRateQuote, PricingEvidence, PricingStage};
use App\Domain\Nium\DynamicMultiCurrency\Options\{BeneficiarySnapshot, CustomerAccountScope};
use App\Domain\Nium\DynamicMultiCurrency\Registry\{EvidenceSource, RevocationStatus};
use App\Models\{Balance, Beneficiary, NiumDynamicIntent, NiumDynamicPayoutQuote, User, UserProviderAccount};
use App\Services\Nium\DynamicConfirmation\{DynamicPayoutEligibility, DynamicPayoutEnvironment, DynamicPayoutFinancialAuthorization, DynamicPayoutInstructionValidation, DynamicPayoutWalletSnapshot, DynamicRegisteredAccountEvidence};
use DateTimeImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\{Cache, Crypt};
use Illuminate\Support\Str;
use RuntimeException;

/** Frozen customer consent plus independently refreshed authenticated provider observations. */
final class NiumDynamicReviewService
{
    public const CUSTOMER_CONTRACT = DynamicPayoutFinancialAuthorization::VERSION;
    public const PROVIDER_REFUSALS = [
        'dynamic_customer_corridor_response_contract_unavailable', 'dynamic_customer_corridor_not_permitted',
        'dynamic_customer_corridor_ambiguous', 'dynamic_customer_corridors_unavailable',
        'dynamic_customer_corridor_legal_entity_unverified', 'dynamic_customer_corridor_lookup_policy_unverified',
        'dynamic_customer_corridor_authorization_unverified', 'dynamic_customer_corridors_endpoint_unverified',
        'dynamic_payout_schema_unavailable', 'dynamic_payout_schema_contract_unavailable',
        'dynamic_payout_schema_endpoint_unverified', 'dynamic_payout_schema_destination_amount_unverified',
        'dynamic_payout_source_amount_contract_unverified', 'dynamic_payout_destination_amount_contract_unverified',
        'dynamic_payout_amount_precision_unsupported', 'dynamic_eligibility_scope_changed',
        'dynamic_payout_amount_modes_unverified',
        'dynamic_payout_schema_conditional_unsupported', 'dynamic_instruction_validation_endpoint_unverified',
    ];

    public function __construct(private readonly NiumDynamicPayoutQuoteService $quotes,
        private readonly DynamicRegisteredAccountEvidence $registration,
        private readonly DynamicPayoutWalletSnapshot $wallets,
        private readonly DynamicPayoutInstructionValidation $instructions,
        private readonly DynamicPayoutEligibility $eligibility,
        private readonly DynamicPayoutFinancialAuthorization $financial) {}

    /** Options permit quoting only; Review must repeat fresh evidence before consent. */
    public function availability(User $user, Beneficiary $beneficiary, Balance $wallet): array
    {
        $discovery = $this->quotes->discovery($user, $beneficiary, $wallet);
        $binding = $discovery['binding'];
        $account = UserProviderAccount::query()->where('user_id', $user->id)->findOrFail($binding['provider_account_id']);
        $registered = $this->registration->inspect($beneficiary, $account, $binding);
        $reasons = [...$registered['reason_codes'], ...($discovery['provider_listing']['status'] === 'LISTED'
            ? [] : $discovery['provider_listing']['reason_codes'])];
        $options = null;
        if ($reasons === []) {
            try {
                if (! is_string($registered['legal_entity']) || preg_match('/^[A-Z]{2}$/D', $registered['legal_entity']) !== 1) {
                    throw new RuntimeException('dynamic_quote_registration_evidence_unavailable');
                }
                $options = $this->eligibility->options($user, $beneficiary, $binding, $registered['legal_entity']);
                $environment = DynamicPayoutEnvironment::configured();
                $environment->assertInstructionValidationEndpoint('/api/v1/client/'.urlencode($binding['client'])
                    .'/customer/'.urlencode($binding['customer']).'/wallet/'.urlencode($binding['wallet']).'/remittance/validate',
                    $binding['client'], $binding['customer'], $binding['wallet']);
                foreach (['wallet', 'remittance', 'transactions'] as $operation) {
                    $environment->assertContract($operation);
                }
            } catch (RuntimeException $exception) {
                // The controller sanitizes these reason codes before projection.
                $reasons[] = $exception->getMessage();
            }
        }
        $financial = $this->financial->unavailableReasons($beneficiary);
        $reasons = [...$reasons, ...$financial];
        // This acquisition path requires FX; it does not replace same-currency payments.
        if ($beneficiary->currency === 'USD') {
            $reasons[] = 'dynamic_same_currency_quote_contract_unverified';
        }
        $modes = $options['supported_amount_modes'] ?? [];
        if ($options !== null && $modes === []) $reasons[] = 'dynamic_payout_amount_modes_unverified';

        return ['provider_listing' => $discovery['provider_listing'],
            'customer_entitlement' => $options === null ? 'UNAVAILABLE' : 'VERIFIED',
            'financial_authorization' => $financial === [] ? 'CUSTOMER_PRICING_AUTHORIZED' : 'UNAVAILABLE',
            'supported_amount_modes' => $reasons === [] ? $modes : [],
            'currency_scales' => $reasons === [] ? $options['currency_scales'] : [],
            'executable_amount_modes' => [], 'requires_instruction_validation' => true,
            'reason_codes' => array_values(array_unique($reasons))];
    }

    public function prepare(User $user, Beneficiary $beneficiary, Balance $wallet,
        NiumDynamicPayoutQuote $quote, PayoutInstruction $instruction): array
    {
        $this->quotes->assertCurrent($quote, $user, $beneficiary, $wallet);
        $operation = (string) Str::uuid();
        $evidence = $this->observe($user, $beneficiary, $wallet, $quote, $instruction, $operation);
        $wallet->refresh();
        $user = $user->fresh();
        $beneficiary = $beneficiary->fresh();
        $this->quotes->assertCurrent($quote, $user, $beneficiary, $wallet);
        $projected = $this->quotes->project($quote);
        $record = [
            'version' => 'nium-dynamic-review-v4', 'owner' => (string) $user->id,
            'provider_id' => (string) $quote->provider_id, 'provider_account_id' => $quote->provider_account_id,
            'balance_id' => $wallet->id, 'beneficiary_id' => $beneficiary->id,
            'dynamic_quote_id' => $quote->id, 'provider_quote_id' => $quote->quote_id,
            'binding' => $quote->binding, 'request_sha256' => hash('sha256', $quote->request_json),
            'response_sha256' => hash('sha256', $quote->response_json),
            'profile_version' => self::profileVersion($user), 'prepared_at' => now()->toISOString(),
            'operation_key' => $operation,
            'amount_mode' => $projected['amount_mode'], 'entered_amount' => $projected['entered_amount'],
            'principal' => $projected['quoted_principal'], 'included_fee' => $projected['planned_included_fee'],
            'total_usd_debit' => $projected['planned_total_usd_debit'],
            'destination_amount' => $projected['quoted_destination_amount'],
            'customer_pricing' => $projected['customer_pricing'],
            'origin_service_fee' => $projected['origin_service_fee'], 'payout_rail' => $projected['payout_rail'],
            'quote_expires_at' => $projected['quote_expires_at'], 'nium_quoted_fx_rate' => $projected['nium_quoted_fx_rate'],
            'instruction' => ['purpose_code' => $instruction->purposeCode, 'source_of_funds' => $instruction->sourceOfFunds, 'comment' => $instruction->comment],
            'evidence' => $evidence, 'reason_codes' => [...$evidence['registered_receiving_account']['reason_codes'],
                ...$this->financial->unavailableReasons($beneficiary)],
        ];
        $review = $record['reason_codes'] === [] ? $this->assemble($record, $quote) : null;
        $record['confirmation_snapshot'] = $review?->snapshot();
        $json = json_encode($record, JSON_THROW_ON_ERROR);
        $fingerprint = $review?->fingerprint() ?? hash('sha256', $json);
        // Retain sealed terms briefly beyond their validity for a precise expiry
        // refusal. load() always enforces quote expiry; retention grants no authority.
        if (! Cache::store('database')->add(self::key($fingerprint), Crypt::encryptString($json), $quote->expires_at->addDay())
            && $this->load($fingerprint) !== $record) {
            throw new RuntimeException('dynamic_review_storage_conflict');
        }
        $this->storeObservations($fingerprint, $evidence, $quote);
        $public = array_intersect_key($record, array_flip(['provider_account_id', 'balance_id', 'beneficiary_id',
            'dynamic_quote_id', 'amount_mode', 'entered_amount', 'principal', 'included_fee', 'total_usd_debit',
            'destination_amount', 'quote_expires_at', 'instruction', 'reason_codes', 'customer_pricing', 'origin_service_fee', 'payout_rail',
            'provider_quote_id', 'nium_quoted_fx_rate']));
        $expires = [$quote->expires_at->toDateTimeImmutable()];
        foreach (['execution_eligibility', 'instruction_validation', 'wallet_availability'] as $name) {
            if (isset($evidence[$name]['expires_at'])) $expires[] = new DateTimeImmutable($evidence[$name]['expires_at']);
        }
        usort($expires, static fn ($a, $b) => $a <=> $b);
        return ['status' => $review === null ? 'UNAVAILABLE' : 'READY', 'review_id' => $fingerprint,
            'review_expires_at' => $expires[0]->format('Y-m-d\TH:i:s.uP'),
            ...($review === null ? [] : ['accepted_fingerprint' => $fingerprint]), ...$public];
    }

    private function observe(User $user, Beneficiary $beneficiary, Balance $wallet, NiumDynamicPayoutQuote $quote,
        PayoutInstruction $instruction, string $operation, ?string $exceptIntent = null): array
    {
        $account = UserProviderAccount::query()->where('user_id', $user->id)->findOrFail($quote->provider_account_id);
        $registered = $this->registration->inspect($beneficiary, $account, $quote->binding);
        $amounts = $this->quotes->project($quote);
        $execution = null;
        if ($registered['reason_codes'] === []) {
            if (! is_string($registered['legal_entity']) || preg_match('/^[A-Z]{2}$/D', $registered['legal_entity']) !== 1) {
                throw new RuntimeException('dynamic_quote_registration_evidence_unavailable');
            }
            $execution = $this->eligibility->observe($user, $beneficiary, $quote, $registered['legal_entity'], $amounts);
        }
        return ['registered_receiving_account' => $registered,
            'provider_capability_sha256' => $execution['customer_corridors_sha256'] ?? null,
            'execution_eligibility' => $execution,
            'instruction_validation' => $this->instructions->validate($user, $beneficiary, $quote, $instruction, $amounts, $operation),
            'wallet_availability' => $this->wallets->refresh($user, $wallet, $exceptIntent)];
    }

    /** Reconstruct trusted server records through the existing validators; never PHP-unserialize objects. */
    public function assemble(array $record, NiumDynamicPayoutQuote $stored): PayoutConfirmation
    {
        $beneficiary = Beneficiary::query()->findOrFail($record['beneficiary_id']);
        $this->financial->assertAvailable($beneficiary);
        $projected = $this->quotes->project($stored);
        if (($record['version'] ?? null) !== 'nium-dynamic-review-v4'
            || ($record['customer_pricing'] ?? null) !== $projected['customer_pricing']
            || ($record['origin_service_fee'] ?? null) !== $projected['origin_service_fee']
            || ($record['payout_rail'] ?? null) !== $projected['payout_rail']) {
            throw new RuntimeException('dynamic_financial_terms_changed');
        }
        foreach (['principal' => 'quoted_principal', 'included_fee' => 'planned_included_fee',
            'total_usd_debit' => 'planned_total_usd_debit', 'destination_amount' => 'quoted_destination_amount',
            'amount_mode' => 'amount_mode', 'entered_amount' => 'entered_amount', 'nium_quoted_fx_rate' => 'nium_quoted_fx_rate',
            'quote_expires_at' => 'quote_expires_at', 'provider_quote_id' => 'provider_quote_id'] as $field => $quoted) {
            if (($record[$field] ?? null) !== $projected[$quoted]) throw new RuntimeException('dynamic_financial_terms_changed');
        }
        $from = new DateTimeImmutable($record['prepared_at']);
        $until = new DateTimeImmutable($record['quote_expires_at']);
        $e = $record['evidence'];
        $b = $record['binding'];
        // Recheck retained schema bytes during locked reconstruction too. A cached
        // observation made before a contract correction cannot bypass its guards.
        $schemaBody = $e['execution_eligibility']['schema_observation']['schema_json'] ?? null;
        if (! is_string($schemaBody)) throw new RuntimeException('dynamic_payout_schema_contract_unavailable');
        $schema = DynamicPayoutEligibility::schemaObservation($schemaBody, [
            'base_url' => $b['base_url'], 'client' => $b['client'], 'customer' => $b['customer'],
            'currency' => $b['destination_currency'],
            'path' => '/api/v1/client/'.urlencode($b['client']).'/customer/'.urlencode($b['customer'])
                .'/currency/'.$b['destination_currency'].'/payout/schema',
            'query' => ['destinationCountry' => $b['destination_country'], 'payoutMethod' => $b['payout_method']],
        ], PayoutAmountMode::from($record['amount_mode']));
        if (($e['execution_eligibility']['payout_schema_sha256'] ?? null) !== hash('sha256', $schemaBody)
            || ($e['execution_eligibility']['currency_scales'] ?? null) !== $schema['currency_scales']
            || ($e['execution_eligibility']['supported_amount_modes'] ?? null) !== $schema['supported_amount_modes']) {
            throw new RuntimeException('dynamic_payout_schema_contract_unavailable');
        }
        $selected = $e['execution_eligibility']['selection'];
        if ($selected['payout_method'] !== $b['payout_method']
            || $selected['destination_country'] !== $b['destination_country']
            || $selected['destination_currency'] !== $b['destination_currency']
            || ! in_array($record['amount_mode'], $e['execution_eligibility']['supported_amount_modes'], true)) {
            throw new RuntimeException('dynamic_review_binding_changed');
        }
        $user = User::query()->findOrFail($record['owner']);
        $type = $user->kycProfile->applicant_type === 'business' ? 'CORPORATE' : 'INDIVIDUAL';
        DynamicPayoutEnvironment::configured()->assertBinding($b['base_url']);
        $c = new CorridorIdentity('nium', DynamicPayoutEnvironment::resolve($b['base_url'])->identity,
            $b['client'], $selected['legal_entity'], $b['customer'], $b['wallet'], $b['wallet'], 'USD',
            $selected['destination_country'], $selected['destination_currency'], $selected['payout_method'], $type,
            $beneficiary->beneficiary_type === 'business' ? 'CORPORATE' : 'INDIVIDUAL', self::CUSTOMER_CONTRACT, $record['profile_version']);
        $scope = new CustomerAccountScope($record['owner'], 'nium', $c->environment, $c->client, $c->legalEntity,
            $c->customer, $c->account, $type, $c->profileVersion, ['USD' => $c->fundingWallet], EvidenceState::Verified,
            $from, $until, RevocationStatus::NotRevoked);
        $recipient = new BeneficiarySnapshot($scope, $c->destinationCountry, $c->destinationCurrency, $c->payoutMethod,
            $c->beneficiaryType, EvidenceState::Verified, true, $from, $until, RevocationStatus::NotRevoked);
        $context = new PayoutQuoteContext($c, $recipient, $record['provider_id'], (string) $record['provider_account_id'],
            (string) $record['beneficiary_id'], $beneficiary->external_beneficiary_id, $b['current_receiving_record_sha256'], $b['base_url']);
        $request = json_decode($stored->request_json, true, 64, JSON_THROW_ON_ERROR);
        $preparation = FinalizedUsdPayoutPreparation::prepare($context, PayoutAmountMode::from($record['amount_mode']),
            $record['entered_amount'], $record['owner'], $from, $request['lockPeriod']);
        $quote = PayoutRateQuote::fromJson($stored->response_json, $preparation->quoteRequest, $context);
        $plan = $preparation->withQuote($quote, $record['owner'], $from)->snapshot($record['owner'], $from);
        $money = static fn (array $v): Money => new Money($v['currency'], new Decimal($v['amount']));
        $provenance = 'nium-quote-sha256:'.$quote->responseSha256;
        // This contract attests Origin customer pricing. It makes no assertion
        // that provider fees/debits equal that pricing, or that settlement is guaranteed.
        $terms = new FinancialTerms($c, $money($record['principal']), FxRequirement::Required, $quote->rate,
            $quote->id, $provenance, new Decimal($plan['expected_fx_margin_percent']), $money($record['destination_amount']),
            $money($record['included_fee']), FeePayer::Sender, FeeAllocation::SenderAdditional,
            new Money('USD', new Decimal($record['total_usd_debit'])), $money($record['destination_amount']),
            self::CUSTOMER_CONTRACT, self::CUSTOMER_CONTRACT, 'accepted-signed-local-'.$c->destinationCountry.'-'.$c->destinationCurrency);
        $rateScale = strlen(explode('.', $quote->rate->value)[1] ?? '');
        $contract = new PayoutContract($c, self::CUSTOMER_CONTRACT, EvidenceState::Verified, EvidenceSource::LocalAttestation,
            RevocationStatus::NotRevoked, $terms->pricingVersion, $terms->feePlanId, [$c->payoutMethod],
            $e['execution_eligibility']['currency_scales'], $rateScale, 'EXACT', 'origin-customer-principal-plus-usd20-v1',
            'nium-payout-quote-exact-instruction-oas-v1', 'USD', FeePayer::Sender, FeeAllocation::SenderAdditional, $from, $until);
        $pricing = [];
        foreach ([PricingStage::Effective, PricingStage::ProviderQuote] as $stage) {
            $pricing[] = new PricingEvidence($stage === PricingStage::ProviderQuote ? $quote->id : self::CUSTOMER_CONTRACT,
                $stage, EvidenceState::Verified, $stage === PricingStage::ProviderQuote ? EvidenceSource::ProviderResponse : EvidenceSource::LocalAttestation,
                $stage === PricingStage::ProviderQuote ? $provenance : self::CUSTOMER_CONTRACT, $terms, $from, $from, $until, RevocationStatus::NotRevoked);
        }
        $references = [EvidenceKind::ProviderCapability->value => $e['provider_capability_sha256'],
            EvidenceKind::AccountEntitlement->value => $e['execution_eligibility']['customer_corridors_sha256'],
            EvidenceKind::OperationalReadiness->value => $e['instruction_validation']['response_sha256'],
            EvidenceKind::FinancialReadiness->value => self::CUSTOMER_CONTRACT,
            EvidenceKind::LocalActivation->value => 'existing-dynamic-feature-gates-v1',
            EvidenceKind::OverrideClearance->value => 'origin-locked-kyc-aml-rfi-account-controls-v1'];
        $eligibility = [];
        foreach (EvidenceKind::required() as $kind) {
            $eligibility[] = new CorridorEvidence($c, $kind, EvidenceState::Verified, $references[$kind->value], $from, $until, false);
        }
        return PayoutConfirmation::prepare($record['owner'], $context, $quote, $terms, $contract, $pricing, $eligibility,
            [$record['amount_mode'] => 'nium-instruction-sha256:'.$e['instruction_validation']['request_sha256']], $from, $preparation,
            new PayoutInstruction(...array_values($record['instruction'])), $record['operation_key'], $e['instruction_validation']['request_sha256']);
    }

    public function refreshExecution(NiumDynamicIntent $intent): void
    {
        $record = $this->load($intent->terms_fingerprint);
        if ($record === null || $record['confirmation_snapshot'] === null) throw new RuntimeException('dynamic_review_binding_changed');
        $user = User::query()->findOrFail($intent->user_id);
        $beneficiary = Beneficiary::query()->findOrFail($intent->beneficiary_id);
        $wallet = Balance::query()->findOrFail($intent->balance_id);
        $quote = NiumDynamicPayoutQuote::query()->findOrFail($record['dynamic_quote_id']);
        $this->quotes->assertCurrent($quote, $user, $beneficiary, $wallet, $intent);
        $evidence = $this->observe($user, $beneficiary, $wallet, $quote, new PayoutInstruction(...array_values($record['instruction'])), $record['operation_key'], $intent->id);
        $this->quotes->assertCurrent($quote, $user->fresh(), $beneficiary->fresh(), $wallet->fresh(), $intent);
        if (self::profileVersion($user->fresh()) !== $record['profile_version']
            || $evidence['registered_receiving_account'] !== $record['evidence']['registered_receiving_account']
            || $evidence['execution_eligibility']['selection'] !== $record['evidence']['execution_eligibility']['selection']
            || $evidence['execution_eligibility']['payout_schema_sha256'] !== $record['evidence']['execution_eligibility']['payout_schema_sha256']
            || $evidence['instruction_validation']['request_sha256'] !== $record['evidence']['instruction_validation']['request_sha256']) {
            throw new RuntimeException('dynamic_review_binding_changed');
        }
        $this->storeObservations($intent->terms_fingerprint, $evidence, $quote);
    }

    public function observations(string $fingerprint): array
    {
        $sealed = Cache::store('database')->get(self::key($fingerprint).':observations');
        if (! is_string($sealed)) throw new RuntimeException('dynamic_confirmation_evidence_expired');
        $value = $this->decode($sealed);
        if (($value['fingerprint'] ?? null) !== $fingerprint) throw new RuntimeException('dynamic_review_binding_changed');
        return $value['evidence'];
    }

    private function storeObservations(string $fingerprint, array $evidence, NiumDynamicPayoutQuote $quote): void
    {
        if (! Cache::store('database')->put(self::key($fingerprint).':observations',
            Crypt::encryptString(json_encode(['fingerprint' => $fingerprint, 'evidence' => $evidence], JSON_THROW_ON_ERROR)), $quote->expires_at)) {
            throw new RuntimeException('dynamic_review_storage_conflict');
        }
    }

    public function load(string $fingerprint): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) return null;
        $sealed = Cache::store('database')->get(self::key($fingerprint));
        if (! is_string($sealed)) return null;
        $record = $this->decode($sealed);
        $snapshot = $record['confirmation_snapshot'] ?? null;
        if (! hash_equals($fingerprint, hash('sha256', json_encode($snapshot ?? $record, JSON_THROW_ON_ERROR)))) {
            throw new RuntimeException('dynamic_review_binding_changed');
        }
        if (new DateTimeImmutable($record['quote_expires_at']) <= now()->toDateTimeImmutable()) throw new RuntimeException('dynamic_quote_expired');
        return $record;
    }

    private function decode(string $sealed): array
    {
        try { $value = json_decode(Crypt::decryptString($sealed), true, 64, JSON_THROW_ON_ERROR); }
        catch (DecryptException|\JsonException $exception) { throw new RuntimeException('dynamic_review_binding_changed', previous: $exception); }
        if (! is_array($value)) throw new RuntimeException('dynamic_review_binding_changed');
        return $value;
    }

    public static function profileVersion(User $user): string
    {
        return 'origin-profile:'.hash('sha256', json_encode($user->kycProfile?->getAttributes(), JSON_THROW_ON_ERROR));
    }

    private static function key(string $fingerprint): string { return 'nium:dynamic:review:v1:'.$fingerprint; }
}
