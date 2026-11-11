<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Nium\DynamicMultiCurrency\CorridorIdentity;
use App\Domain\Nium\DynamicMultiCurrency\EvidenceState;
use App\Domain\Nium\DynamicMultiCurrency\Financial\Decimal;
use App\Domain\Nium\DynamicMultiCurrency\Financial\DocumentaryFeeSchedule;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FeeAllocation;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FeePayer;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FinancialDecision;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FinancialTerms;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FinancialTermsValidator;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FxRequirement;
use App\Domain\Nium\DynamicMultiCurrency\Financial\Money;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutContract;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PricingEvidence;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PricingStage;
use App\Domain\Nium\DynamicMultiCurrency\Registry\EvidenceSource;
use App\Domain\Nium\DynamicMultiCurrency\Registry\RevocationStatus;
use Closure;
use DateTimeImmutable;
use Error;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeError;

/** All amounts, quotes, accounts and contracts below are synthetic offline fixtures. */
final class NiumDynamicFinancialTermsTest extends TestCase
{
    private Closure $autoloadGuard;

    private array $runtimeLoadAttempts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->autoloadGuard = function (string $class): void {
            if ((str_starts_with($class, 'App\\') && ! str_starts_with($class, 'App\\Domain\\Nium\\DynamicMultiCurrency\\'))
                || str_starts_with($class, 'Illuminate\\') || str_starts_with($class, 'GuzzleHttp\\')) {
                $this->runtimeLoadAttempts[] = $class;
                throw new LogicException('Financial model tests must not load HTTP, database, wallet or provider runtime.');
            }
        };
        spl_autoload_register($this->autoloadGuard, true, true);
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->autoloadGuard);
        try {
            $this->assertSame([], $this->runtimeLoadAttempts);
        } finally {
            parent::tearDown();
        }
    }

    private function time(string $value = '2026-11-09T00:30:00Z'): DateTimeImmutable
    {
        return new DateTimeImmutable($value);
    }

    private function corridor(array $changes = []): CorridorIdentity
    {
        return new CorridorIdentity(...array_replace([
            'provider' => 'nium', 'environment' => 'sandbox', 'client' => 'synthetic-client',
            'legalEntity' => 'synthetic-entity', 'customer' => 'synthetic-customer', 'account' => 'synthetic-account',
            'fundingWallet' => 'synthetic-wallet', 'sourceCurrency' => 'EUR', 'destinationCountry' => 'DE',
            'destinationCurrency' => 'EUR', 'payoutMethod' => 'LOCAL', 'customerType' => 'CORPORATE',
            'beneficiaryType' => 'INDIVIDUAL', 'contractVersion' => 'synthetic-contract-v1', 'profileVersion' => 'synthetic-profile-v1',
        ], $changes));
    }

    private function money(string $currency, string $amount): Money
    {
        return new Money($currency, new Decimal($amount));
    }

    private function sameCurrency(array $changes = []): FinancialTerms
    {
        return new FinancialTerms(...array_replace([
            'corridor' => $this->corridor(), 'principal' => $this->money('EUR', '100'), 'fxRequirement' => FxRequirement::None,
            'fxRate' => null, 'quoteId' => null, 'quoteProvenance' => null, 'fxMarginPercent' => null, 'conversionAmount' => null,
            'transactionFee' => $this->money('EUR', '2'), 'feePayer' => FeePayer::Sender, 'feeAllocation' => FeeAllocation::SenderAdditional,
            'totalWalletDebit' => $this->money('EUR', '102'), 'estimatedRecipient' => $this->money('EUR', '100'),
            'pricingSource' => 'synthetic-effective-price-document', 'pricingVersion' => 'synthetic-pricing-v1', 'feePlanId' => 'synthetic-fee-plan',
        ], $changes));
    }

    private function fx(array $changes = []): FinancialTerms
    {
        // Illustrative test numbers only; no production rate or margin is asserted.
        return $this->sameCurrency(array_replace([
            'corridor' => $this->corridor(['destinationCurrency' => 'GBP', 'destinationCountry' => 'GB']),
            'fxRequirement' => FxRequirement::Required, 'fxRate' => new Decimal('0.8'),
            'quoteId' => 'synthetic-quote-1', 'quoteProvenance' => 'synthetic-provider-quote-document',
            'fxMarginPercent' => new Decimal('0.25'), 'conversionAmount' => $this->money('GBP', '80'),
            'estimatedRecipient' => $this->money('GBP', '80'),
        ], $changes));
    }

    private function contract(FinancialTerms $terms, array $changes = []): PayoutContract
    {
        return new PayoutContract(...array_replace([
            'corridor' => $terms->corridor, 'reference' => 'synthetic-applicability-document', 'state' => EvidenceState::Verified,
            'source' => EvidenceSource::Synthetic, 'revocation' => RevocationStatus::NotRevoked,
            'pricingVersion' => $terms->pricingVersion, 'feePlanId' => $terms->feePlanId, 'supportedMethods' => ['LOCAL'],
            'currencyScales' => ['EUR' => 2, 'GBP' => 2], 'rateScale' => 6, 'roundingRule' => 'EXACT',
            'calculationReference' => 'synthetic-declared-principal-fee-equation',
            'fxCalculationReference' => 'synthetic-verified-provider-output-contract',
            'feeCurrency' => $terms->feeAllocation === FeeAllocation::RecipientDeducted ? $terms->corridor->destinationCurrency : $terms->corridor->sourceCurrency,
            'feePayer' => $terms->feePayer, 'feeAllocation' => $terms->feeAllocation,
            'validFrom' => $this->time('2026-11-09T00:00:00Z'), 'expiresAt' => $this->time('2026-11-09T01:00:00Z'),
        ], $changes));
    }

    private function evidence(FinancialTerms $terms, PricingStage $stage, array $changes = []): PricingEvidence
    {
        return new PricingEvidence(...array_replace([
            'id' => $stage === PricingStage::ProviderQuote ? ($terms->quoteId ?? 'synthetic-payout-quote') : 'synthetic-evidence-'.$stage->value,
            'stage' => $stage, 'state' => EvidenceState::Verified, 'source' => EvidenceSource::Synthetic,
            'provenance' => $stage === PricingStage::ProviderQuote ? ($terms->quoteProvenance ?? 'synthetic-payout-quote-document') : 'synthetic-document-'.$stage->value,
            'terms' => $terms, 'issuedAt' => $this->time('2026-11-09T00:00:00Z'),
            'validFrom' => $this->time('2026-11-09T00:00:00Z'), 'expiresAt' => $this->time('2026-11-09T01:00:00Z'),
            'revocation' => RevocationStatus::NotRevoked,
        ], $changes));
    }

    private function complete(FinancialTerms $terms): array
    {
        $records = [$this->evidence($terms, PricingStage::Effective), $this->evidence($terms, PricingStage::CustomerConfirmed)];
        if ($terms->corridor->sourceCurrency !== $terms->corridor->destinationCurrency) {
            $records[] = $this->evidence($terms, PricingStage::ProviderQuote);
        }

        return $records;
    }

    private function check(FinancialTerms $terms, array $contractChanges = []): FinancialDecision
    {
        return (new FinancialTermsValidator)->evaluate($terms, $this->time(), $this->contract($terms, $contractChanges), ...$this->complete($terms));
    }

    private function assertBlocked(FinancialDecision $decision, string $reason): void
    {
        $this->assertSame('DENIED', $decision->preparation);
        $this->assertContains($reason, $decision->reasons);
        $this->assertSame('DENIED', $decision->dispatch);
        $this->assertContains('phase_three_dispatch_disabled', $decision->dispatchReasons);
    }

    public function test_effective_and_confirmed_same_currency_terms_need_no_implicit_fx(): void
    {
        $terms = $this->sameCurrency();
        $decision = $this->check($terms);
        $this->assertSame('VERIFIED', $decision->status);
        $this->assertSame('ALLOWED', $decision->preparation);
        $this->assertSame([], $decision->reasons);
        $this->assertSame('DENIED', $decision->dispatch);
        $this->assertSame(['phase_three_dispatch_disabled', 'synthetic_evidence'], $decision->dispatchReasons);
        $this->assertNull($terms->fxRate);
    }

    public function test_documentary_schedule_and_contracted_assertions_cannot_establish_effective_pricing(): void
    {
        $this->assertSame(PricingStage::Contracted, DocumentaryFeeSchedule::STAGE);
        $this->assertCount(9, DocumentaryFeeSchedule::LOCAL_REFERENCES);
        $this->assertSame(['LOCAL', 'WIRES'], array_column(DocumentaryFeeSchedule::NON_CONTRACTED_REFERENCES, 'method'));
        $this->assertSame(['0.13', '0.13', '0.13', '0.13', '0.13', '0.28', '0.44', '0.28', '0.30'],
            array_column(DocumentaryFeeSchedule::LOCAL_REFERENCES, 'margin_percent'));
        $terms = $this->sameCurrency();
        $decision = (new FinancialTermsValidator)->evaluate($terms, $this->time(), null, $this->evidence($terms, PricingStage::Contracted));
        $this->assertSame('UNKNOWN', $decision->status);
        $this->assertBlocked($decision, 'effective_pricing_missing');
        $this->assertContains('customer_financial_terms_missing', $decision->reasons);
        $this->assertContains('financial_contract_missing', $decision->reasons);
    }

    public function test_signed_summary_does_not_fill_missing_actual_fee_debit_or_effective_evidence(): void
    {
        $reference = DocumentaryFeeSchedule::signedReference();
        // Synthetic candidate only. Even a fixture assertion at CONTRACTED cannot
        // turn the signed USD 20 reference into the actual transaction fee/debit.
        $terms = $this->fx(['transactionFee' => null, 'totalWalletDebit' => null]);
        $contracted = $this->evidence($terms, PricingStage::from($reference['stage']),
            ['provenance' => $reference['source']]);

        $decision = (new FinancialTermsValidator)->evaluate($terms, $this->time(), null, $contracted);

        foreach (['transaction_fee_unknown', 'total_wallet_debit_unknown', 'effective_pricing_missing',
            'provider_quote_missing', 'customer_financial_terms_missing', 'financial_contract_missing'] as $reason) {
            $this->assertBlocked($decision, $reason);
        }
        $this->assertNull($terms->transactionFee);
        $this->assertNull($terms->totalWalletDebit);
    }

    public static function missingStages(): array
    {
        return [[PricingStage::Effective, 'effective_pricing_missing'], [PricingStage::CustomerConfirmed, 'customer_financial_terms_missing'],
            [PricingStage::ProviderQuote, 'provider_quote_missing']];
    }

    #[DataProvider('missingStages')]
    public function test_each_required_financial_stage_must_be_explicit(PricingStage $missing, string $reason): void
    {
        $terms = $this->fx();
        $records = array_values(array_filter($this->complete($terms), static fn (PricingEvidence $record): bool => $record->stage !== $missing));
        $decision = (new FinancialTermsValidator)->evaluate($terms, $this->time(), $this->contract($terms), ...$records);
        $this->assertSame('UNKNOWN', $decision->status);
        $this->assertBlocked($decision, $reason);
    }

    public function test_unknown_contract_and_unknown_pricing_do_not_become_verified(): void
    {
        $terms = $this->sameCurrency();
        $unknownContract = $this->check($terms, ['state' => EvidenceState::Unknown]);
        $this->assertSame('UNKNOWN', $unknownContract->status);
        $this->assertBlocked($unknownContract, 'financial_contract_unknown');
        $unknownPrice = (new FinancialTermsValidator)->evaluate($terms, $this->time(), $this->contract($terms),
            $this->evidence($terms, PricingStage::Effective, ['state' => EvidenceState::Unknown]),
            $this->evidence($terms, PricingStage::CustomerConfirmed));
        $this->assertSame('UNKNOWN', $unknownPrice->status);
        $this->assertBlocked($unknownPrice, 'pricing_evidence_unknown');
    }

    public static function invalidEvidence(): array
    {
        return [
            [['expiresAt' => '2026-11-09T00:30:00Z'], 'pricing_evidence_expired'],
            [['validFrom' => '2026-11-09T00:31:00Z'], 'pricing_evidence_future_dated'],
            [['issuedAt' => '2026-11-09T00:31:00Z'], 'pricing_evidence_future_dated'],
            [['expiresAt' => '2026-11-09T00:00:00Z'], 'pricing_evidence_invalid_window'],
            [['revocation' => RevocationStatus::Revoked], 'pricing_evidence_revoked'],
            [['revocation' => RevocationStatus::Unknown], 'pricing_revocation_unknown'],
            [['state' => EvidenceState::Hold], 'pricing_evidence_hold'],
            [['state' => EvidenceState::Denied], 'pricing_evidence_denied'],
        ];
    }

    #[DataProvider('invalidEvidence')]
    public function test_expired_future_revoked_unknown_and_blocked_quote_evidence_fails_closed(array $changes, string $reason): void
    {
        foreach (['expiresAt', 'validFrom', 'issuedAt'] as $field) {
            if (isset($changes[$field])) {
                $changes[$field] = $this->time($changes[$field]);
            }
        }
        $terms = $this->fx();
        $records = array_values(array_filter($this->complete($terms), static fn (PricingEvidence $record): bool => $record->stage !== PricingStage::ProviderQuote));
        $records[] = $this->evidence($terms, PricingStage::ProviderQuote, $changes);
        $decision = (new FinancialTermsValidator)->evaluate($terms, $this->time(), $this->contract($terms), ...$records);
        $this->assertBlocked($decision, $reason);
    }

    public function test_expired_or_revoked_contract_cannot_fall_back_to_documentary_prices(): void
    {
        $terms = $this->sameCurrency();
        $this->assertBlocked($this->check($terms, ['expiresAt' => $this->time()]), 'pricing_evidence_expired');
        $this->assertBlocked($this->check($terms, ['revocation' => RevocationStatus::Revoked]), 'pricing_evidence_revoked');
    }

    public function test_conflicting_assertions_and_duplicate_identifiers_have_no_precedence_rule(): void
    {
        $terms = $this->sameCurrency();
        $base = $this->complete($terms);
        $extra = $this->evidence($terms, PricingStage::Effective, ['id' => 'synthetic-newer-effective', 'issuedAt' => $this->time('2026-11-09T00:20:00Z')]);
        $a = (new FinancialTermsValidator)->evaluate($terms, $this->time(), $this->contract($terms), ...[...$base, $extra]);
        $b = (new FinancialTermsValidator)->evaluate($terms, $this->time(), $this->contract($terms), ...array_reverse([...$base, $extra]));
        $this->assertBlocked($a, 'pricing_evidence_conflicting');
        $this->assertEquals($a, $b);
        $differentTerms = $this->sameCurrency(['transactionFee' => $this->money('EUR', '3'), 'totalWalletDebit' => $this->money('EUR', '103')]);
        $contradiction = $this->evidence($differentTerms, PricingStage::Effective, ['id' => 'synthetic-conflicting-price']);
        $conflict = (new FinancialTermsValidator)->evaluate($terms, $this->time(), $this->contract($terms), ...[...$base, $contradiction]);
        $this->assertBlocked($conflict, 'pricing_evidence_conflicting');
        $this->assertContains('financial_terms_changed', $conflict->reasons);
        $duplicate = $this->evidence($terms, PricingStage::CustomerConfirmed, ['id' => $base[0]->id]);
        $this->assertBlocked((new FinancialTermsValidator)->evaluate($terms, $this->time(), $this->contract($terms), $base[0], $duplicate), 'pricing_evidence_duplicate_id');
    }

    public function test_cross_currency_uses_bound_authoritative_quote_output_and_never_applies_a_margin(): void
    {
        $decision = $this->check($this->fx());
        $this->assertSame('VERIFIED', $decision->status);
        $this->assertSame('DENIED', $decision->dispatch);
        $this->assertSame([], $decision->reasons);
        $missingCalculation = $this->check($this->fx(), ['fxCalculationReference' => null]);
        $this->assertSame('UNKNOWN', $missingCalculation->status);
        $this->assertBlocked($missingCalculation, 'fx_calculation_contract_unknown');
    }

    public static function missingFxFields(): array
    {
        return [['fxRate'], ['quoteId'], ['quoteProvenance'], ['fxMarginPercent'], ['conversionAmount']];
    }

    #[DataProvider('missingFxFields')]
    public function test_no_fx_component_is_inferred(string $field): void
    {
        $decision = $this->check($this->fx([$field => null]));
        $this->assertSame('UNKNOWN', $decision->status);
        $this->assertBlocked($decision, 'fx_terms_unknown');
    }

    public function test_fx_requirement_must_match_the_currency_pair_without_inserting_rate_one(): void
    {
        $this->assertBlocked($this->check($this->fx(['fxRequirement' => FxRequirement::None])), 'fx_requirement_mismatch');
        $this->assertBlocked($this->check($this->sameCurrency(['fxRequirement' => FxRequirement::Required])), 'fx_requirement_mismatch');
        $this->assertBlocked($this->check($this->sameCurrency(['fxRate' => new Decimal('1')])), 'fx_requirement_mismatch');
        $this->assertBlocked($this->check($this->fx(['fxRequirement' => FxRequirement::Unknown])), 'fx_requirement_unknown');
    }

    public function test_currency_and_rate_precision_are_explicit_and_values_are_never_rounded(): void
    {
        $this->assertBlocked($this->check($this->sameCurrency(['principal' => $this->money('EUR', '100.001')])), 'financial_precision_invalid');
        $this->assertBlocked($this->check($this->fx(['fxRate' => new Decimal('0.8123456')])), 'financial_precision_invalid');
        $this->assertBlocked($this->check($this->fx(), ['currencyScales' => ['EUR' => 2]]), 'currency_precision_unknown');
        $this->assertBlocked($this->check($this->fx(), ['rateScale' => null]), 'currency_precision_unknown');
        $this->assertBlocked($this->check($this->sameCurrency(), ['roundingRule' => 'HALF_UP']), 'rounding_contract_unknown');

        $jpy = $this->fx(['corridor' => $this->corridor(['destinationCountry' => 'JP', 'destinationCurrency' => 'JPY']),
            'conversionAmount' => $this->money('JPY', '80.01'), 'estimatedRecipient' => $this->money('JPY', '80.01')]);
        $this->assertBlocked($this->check($jpy, ['currencyScales' => ['EUR' => 2, 'JPY' => 0]]), 'financial_precision_invalid');
    }

    public function test_missing_fees_debit_and_recipient_amounts_are_unknown_not_zero(): void
    {
        foreach (['transactionFee' => 'transaction_fee_unknown', 'totalWalletDebit' => 'total_wallet_debit_unknown',
            'estimatedRecipient' => 'estimated_recipient_unknown'] as $field => $reason) {
            $decision = $this->check($this->sameCurrency([$field => null]));
            $this->assertSame('UNKNOWN', $decision->status);
            $this->assertBlocked($decision, $reason);
        }
        $explicitZero = $this->sameCurrency(['transactionFee' => $this->money('EUR', '0'), 'totalWalletDebit' => $this->money('EUR', '100')]);
        $this->assertSame('VERIFIED', $this->check($explicitZero)->status, 'Zero requires explicit effective and confirmed evidence.');
    }

    public function test_fee_currency_and_wallet_recipient_currencies_cannot_be_substituted(): void
    {
        $this->assertBlocked($this->check($this->sameCurrency(['transactionFee' => $this->money('USD', '2')])), 'fee_currency_mismatch');
        $this->assertBlocked($this->check($this->sameCurrency(['totalWalletDebit' => $this->money('USD', '102')])), 'financial_currency_mismatch');
        $this->assertBlocked($this->check($this->fx(['estimatedRecipient' => $this->money('EUR', '80')])), 'financial_currency_mismatch');
    }

    public function test_unknown_shared_or_contradictory_fee_allocation_fails_closed(): void
    {
        foreach ([FeeAllocation::Unknown, FeeAllocation::Shared] as $allocation) {
            $decision = $this->check($this->sameCurrency(['feeAllocation' => $allocation]));
            $this->assertSame('UNKNOWN', $decision->status);
            $this->assertBlocked($decision, 'fee_allocation_unknown');
        }
        $this->assertBlocked($this->check($this->sameCurrency(['feePayer' => FeePayer::Unknown])), 'fee_allocation_unknown');
        $this->assertBlocked($this->check($this->sameCurrency(['feePayer' => FeePayer::Recipient])), 'fee_allocation_mismatch');
    }

    public function test_declared_debit_and_recipient_equations_detect_inconsistency(): void
    {
        $this->assertBlocked($this->check($this->sameCurrency(['totalWalletDebit' => $this->money('EUR', '103')])), 'total_wallet_debit_inconsistent');
        $this->assertBlocked($this->check($this->sameCurrency(['estimatedRecipient' => $this->money('EUR', '99')])), 'estimated_recipient_inconsistent');
        $this->assertBlocked($this->check($this->fx(['estimatedRecipient' => $this->money('GBP', '79')])), 'estimated_recipient_inconsistent');
        $unknown = $this->check($this->sameCurrency(['totalWalletDebit' => $this->money('EUR', '103')]), ['calculationReference' => null]);
        $this->assertSame('UNKNOWN', $unknown->status);
        $this->assertBlocked($unknown, 'debit_calculation_contract_unknown');
    }

    public function test_explicit_recipient_deduction_is_distinct_from_sender_additional_fees(): void
    {
        $same = $this->sameCurrency(['feePayer' => FeePayer::Recipient, 'feeAllocation' => FeeAllocation::RecipientDeducted,
            'totalWalletDebit' => $this->money('EUR', '100'), 'estimatedRecipient' => $this->money('EUR', '98')]);
        $fx = $this->fx(['feePayer' => FeePayer::Recipient, 'feeAllocation' => FeeAllocation::RecipientDeducted,
            'transactionFee' => $this->money('GBP', '2'), 'totalWalletDebit' => $this->money('EUR', '100'),
            'estimatedRecipient' => $this->money('GBP', '78')]);
        $this->assertSame('VERIFIED', $this->check($same)->status);
        $this->assertSame('VERIFIED', $this->check($fx)->status);
        $this->assertBlocked($this->check($fx, ['feePayer' => FeePayer::Sender, 'feeAllocation' => FeeAllocation::SenderAdditional]), 'fee_allocation_mismatch');
    }

    public static function bindingChanges(): array
    {
        return [['pricingVersion', 'synthetic-pricing-v2'], ['feePlanId', 'synthetic-other-plan'],
            ['quoteId', 'synthetic-other-quote'], ['quoteProvenance', 'synthetic-other-provenance'], ['pricingSource', 'synthetic-other-source']];
    }

    #[DataProvider('bindingChanges')]
    public function test_quote_to_candidate_metadata_binding_is_exact(string $field, string $value): void
    {
        $original = $this->fx();
        $changed = new FinancialTerms(...array_replace(get_object_vars($original), [$field => $value]));
        $decision = (new FinancialTermsValidator)->evaluate($changed, $this->time(), $this->contract($original), ...$this->complete($original));
        $this->assertBlocked($decision, 'financial_terms_changed');
    }

    public function test_quote_to_candidate_binds_amounts_rate_margin_and_contract_scope(): void
    {
        $original = $this->fx();
        foreach (['principal' => $this->money('EUR', '101'), 'transactionFee' => $this->money('EUR', '3'),
            'totalWalletDebit' => $this->money('EUR', '103'), 'estimatedRecipient' => $this->money('GBP', '79'),
            'fxRate' => new Decimal('0.81'), 'fxMarginPercent' => new Decimal('0.26')] as $field => $value) {
            $changed = new FinancialTerms(...array_replace(get_object_vars($original), [$field => $value]));
            $decision = (new FinancialTermsValidator)->evaluate($changed, $this->time(), $this->contract($original), ...$this->complete($original));
            $this->assertBlocked($decision, 'financial_terms_changed');
        }
        foreach (['environment' => 'production', 'customer' => 'synthetic-other-customer', 'fundingWallet' => 'synthetic-other-wallet',
            'contractVersion' => 'synthetic-contract-v2', 'profileVersion' => 'synthetic-profile-v2'] as $field => $value) {
            $scope = new CorridorIdentity(...array_replace(get_object_vars($original->corridor), [$field => $value]));
            $changed = new FinancialTerms(...array_replace(get_object_vars($original), ['corridor' => $scope]));
            $this->assertBlocked((new FinancialTermsValidator)->evaluate($changed, $this->time(), $this->contract($original), ...$this->complete($original)), 'financial_scope_mismatch');
        }
    }

    public function test_quote_reference_cannot_be_relabelled_and_contract_versions_cannot_be_substituted(): void
    {
        $terms = $this->fx();
        $records = [$this->evidence($terms, PricingStage::Effective), $this->evidence($terms, PricingStage::CustomerConfirmed),
            $this->evidence($terms, PricingStage::ProviderQuote, ['id' => 'synthetic-unrelated-quote'])];
        $this->assertBlocked((new FinancialTermsValidator)->evaluate($terms, $this->time(), $this->contract($terms), ...$records), 'provider_quote_binding_mismatch');
        $this->assertBlocked($this->check($terms, ['pricingVersion' => 'synthetic-pricing-v2']), 'financial_contract_version_mismatch');
        $this->assertBlocked($this->check($terms, ['feePlanId' => 'synthetic-other-plan']), 'financial_contract_version_mismatch');
    }

    public function test_unsupported_rails_have_no_documentary_default_or_swift_wires_alias(): void
    {
        foreach (['CASH', 'WIRES', 'SWIFT'] as $method) {
            $terms = $this->sameCurrency(['corridor' => $this->corridor(['payoutMethod' => $method])]);
            $this->assertBlocked($this->check($terms), 'payout_method_unsupported');
        }
    }

    public function test_provider_evidence_cannot_become_customer_consent_or_hide_synthetic_provenance(): void
    {
        $terms = $this->sameCurrency();
        $records = [$this->evidence($terms, PricingStage::Effective),
            $this->evidence($terms, PricingStage::CustomerConfirmed, ['source' => EvidenceSource::ProviderResponse])];
        $decision = (new FinancialTermsValidator)->evaluate($terms, $this->time(), $this->contract($terms), ...$records);
        $this->assertBlocked($decision, 'customer_confirmation_source_invalid');
        $this->assertContains('pricing_evidence_origin_mixed', $decision->reasons);
    }

    public function test_sgd_local_remains_held_and_even_claimed_real_production_terms_cannot_dispatch(): void
    {
        $sgd = $this->fx(['corridor' => $this->corridor(['destinationCountry' => 'SG', 'destinationCurrency' => 'SGD']),
            'conversionAmount' => $this->money('SGD', '80'), 'estimatedRecipient' => $this->money('SGD', '80')]);
        $this->assertBlocked($this->check($sgd, ['currencyScales' => ['EUR' => 2, 'SGD' => 2]]), 'sgd_local_hold');
        // These are still synthetic fixtures; only their asserted source labels differ.
        $terms = $this->sameCurrency(['corridor' => $this->corridor(['environment' => 'production'])]);
        $records = [$this->evidence($terms, PricingStage::Effective, ['source' => EvidenceSource::LocalAttestation]),
            $this->evidence($terms, PricingStage::CustomerConfirmed, ['source' => EvidenceSource::LocalAttestation])];
        $decision = (new FinancialTermsValidator)->evaluate($terms, $this->time(), $this->contract($terms, ['source' => EvidenceSource::LocalAttestation]), ...$records);
        $this->assertSame('VERIFIED', $decision->status);
        $this->assertSame('DENIED', $decision->dispatch);
        $this->assertSame(['phase_three_dispatch_disabled'], $decision->dispatchReasons);
    }

    public function test_decimal_addition_exceeds_machine_integer_precision_without_floats(): void
    {
        $this->assertSame('100000000000000000000', (new Decimal('99999999999999999999.99'))->plus(new Decimal('0.01'))->value);
        $this->assertSame('0.3', (new Decimal('0.1'))->plus(new Decimal('0.2'))->value);
        $this->assertSame('0.000000000000000003', (new Decimal('0.000000000000000001'))->plus(new Decimal('0.000000000000000002'))->value);
        $this->assertTrue((new Decimal('1.000'))->fitsScale(0));
        $this->assertFalse((new Decimal('1.001'))->fitsScale(2));
    }

    public static function invalidDecimals(): array
    {
        return [[''], ['1e3'], ['-1'], ['01'], [' 1'], ['1 '], ['.1'], ['1.'], ['NaN'], ['INF']];
    }

    #[DataProvider('invalidDecimals')]
    public function test_ambiguous_decimal_strings_are_rejected(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Decimal($value);
    }

    public function test_float_money_input_is_rejected(): void
    {
        $this->expectException(TypeError::class);
        new Decimal(0.1);
    }

    public function test_frozen_financial_terms_cannot_be_mutated(): void
    {
        $terms = $this->sameCurrency();
        $this->expectException(Error::class);
        $terms->pricingVersion = 'replacement-version';
    }

    public function test_validation_is_repeatable_and_has_no_runtime_or_input_side_effects(): void
    {
        $terms = $this->fx();
        $contract = $this->contract($terms);
        $records = $this->complete($terms);
        $validator = new FinancialTermsValidator;
        $now = $this->time();
        $expected = $validator->evaluate($terms, $now, $contract, ...$records);
        $before = serialize([$terms, $contract, $records, $validator, $now]);
        $includedBefore = get_included_files();
        $actual = $validator->evaluate($terms, $now, $contract, ...$records);
        $includedAfter = get_included_files();
        $this->assertEquals($expected, $actual);
        $this->assertSame($before, serialize([$terms, $contract, $records, $validator, $now]));
        $this->assertSame($includedBefore, $includedAfter);
        $this->assertSame([], $this->runtimeLoadAttempts);

        $expired = $validator->evaluate($terms, $this->time('2026-11-09T01:00:00Z'), $contract, ...$records);
        $this->assertBlocked($expired, 'pricing_evidence_expired');
        $this->assertEquals($expected, $validator->evaluate($terms, $now, $contract, ...$records));
    }
}
