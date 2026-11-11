<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Nium\DynamicMultiCurrency\CorridorEvidence;
use App\Domain\Nium\DynamicMultiCurrency\CorridorIdentity;
use App\Domain\Nium\DynamicMultiCurrency\EvidenceKind;
use App\Domain\Nium\DynamicMultiCurrency\EvidenceState;
use App\Domain\Nium\DynamicMultiCurrency\Financial\Decimal;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FeeAllocation;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FeePayer;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FinalizedUsdPayoutPreparation;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FinancialTerms;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FxRequirement;
use App\Domain\Nium\DynamicMultiCurrency\Financial\Money;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutAmountMode;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutConfirmation;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutContract;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutQuoteContext;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutRateQuote;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PricingEvidence;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PricingStage;
use App\Domain\Nium\DynamicMultiCurrency\Options\BeneficiarySnapshot;
use App\Domain\Nium\DynamicMultiCurrency\Options\CustomerAccountScope;
use App\Domain\Nium\DynamicMultiCurrency\Registry\EvidenceSource;
use App\Domain\Nium\DynamicMultiCurrency\Registry\RevocationStatus;
use App\Models\LedgerEntry;
use App\Models\NiumDynamicIntent;
use App\Models\NiumDynamicPayoutOutbox;
use App\Models\NiumDynamicReservation;
use App\Models\Transfer;
use App\Services\Nium\DynamicConfirmation\DynamicReceivingAccountBinding;
use App\Services\Nium\DynamicConfirmation\DynamicReservationLedger;
use App\Services\Nium\DynamicConfirmation\DynamicTransferConfirmationService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesDynamicConfirmationFixtures;
use Tests\Support\DynamicConfirmationDatabaseTestCase;

trait CreatesFinalizedUsdConfirmationFixtures
{
    protected function at(string $time = '00:10:00'): DateTimeImmutable
    {
        return new DateTimeImmutable('2030-01-01T'.$time.'Z');
    }

    protected function usdContext(array $changes = []): PayoutQuoteContext
    {
        $c = new CorridorIdentity(...array_replace([
            'provider' => 'nium', 'environment' => 'sandbox', 'client' => 'fixture-client',
            'legalEntity' => 'fixture-entity', 'customer' => 'fixture-customer', 'account' => 'fixture-wallet',
            'fundingWallet' => 'fixture-wallet', 'sourceCurrency' => 'USD', 'destinationCountry' => 'GB',
            'destinationCurrency' => 'GBP', 'payoutMethod' => 'LOCAL', 'customerType' => 'INDIVIDUAL',
            'beneficiaryType' => 'INDIVIDUAL', 'contractVersion' => 'fixture-contract-v1', 'profileVersion' => 'fixture-profile-v1',
        ], $changes));
        $scope = new CustomerAccountScope((string) $this->owner->id, $c->provider, $c->environment,
            $c->client, $c->legalEntity, $c->customer, $c->account, $c->customerType, $c->profileVersion,
            ['USD' => $c->fundingWallet], EvidenceState::Verified, $this->at('00:00:00'),
            $this->at('02:00:00'), RevocationStatus::NotRevoked);
        $beneficiary = new BeneficiarySnapshot($scope, $c->destinationCountry, $c->destinationCurrency,
            $c->payoutMethod, $c->beneficiaryType, EvidenceState::Verified, true, $this->at('00:00:00'),
            $this->at('02:00:00'), RevocationStatus::NotRevoked);

        return new PayoutQuoteContext($c, $beneficiary, (string) $this->account->provider_id,
            (string) $this->account->id, (string) $this->beneficiary->id, 'fixture-registered-payee',
            DynamicReceivingAccountBinding::fingerprint($this->beneficiary));
    }

    protected function usdReview(PayoutAmountMode $mode = PayoutAmountMode::Source, array $changes = [],
        bool $bindPreparation = true): PayoutConfirmation
    {
        $context = $this->usdContext();
        $c = $context->corridor;
        $actor = (string) $this->owner->id;
        $preparation = FinalizedUsdPayoutPreparation::prepare($context, $mode,
            $mode === PayoutAmountMode::Source ? '1000' : '784', $actor, $this->at());
        $r = $preparation->quoteRequest;
        $body = json_encode(array_replace([
            'id' => 'fixture-finalized-quote', 'quoteType' => 'payout', 'quoteIntent' => 'EXECUTABLE',
            'customerHashId' => $r->customer, 'sourceCurrencyCode' => $r->sourceCurrency,
            'destinationCurrencyCode' => $r->destinationCurrency, 'conversionSchedule' => 'immediate',
            'executionType' => 'at_conversion_time', 'lockPeriod' => $r->lockPeriod,
            'sourceAmount' => '980', 'destinationAmount' => '784', 'netExchangeRate' => '0.8',
            'expiryTime' => '2030-01-01T01:00:00Z',
        ], $changes['response'] ?? []), JSON_THROW_ON_ERROR);
        $body = str_replace(['"sourceAmount":"980"', '"destinationAmount":"784"'],
            ['"sourceAmount":980', '"destinationAmount":784'], $body);
        $quote = PayoutRateQuote::fromJson($body, $r, $context);
        $preparation = $preparation->withQuote($quote, $actor, $this->at());
        $money = static fn (string $currency, string $amount): Money => new Money($currency, new Decimal($amount));
        // Reuse prepared values; these fixture classifications do not authenticate Nium.
        $terms = new FinancialTerms(...array_replace([
            'corridor' => $c, 'principal' => $preparation->proposedConversionPrincipal,
            'fxRequirement' => FxRequirement::Required, 'fxRate' => $quote->rate,
            'quoteId' => $quote->id, 'quoteProvenance' => 'fixture-provider-response',
            'fxMarginPercent' => $preparation->expectedFxMarginPercent,
            'conversionAmount' => new Money('GBP', $quote->destinationAmount),
            'transactionFee' => $money('USD', FinalizedUsdPayoutPreparation::FEE_USD),
            'feePayer' => FeePayer::Sender, 'feeAllocation' => FeeAllocation::SenderAdditional,
            'totalWalletDebit' => $preparation->plannedCustomerDebit,
            'estimatedRecipient' => new Money('GBP', $quote->destinationAmount),
            'pricingSource' => 'fixture-pricing', 'pricingVersion' => 'fixture-v1', 'feePlanId' => 'fixture-plan',
        ], $changes['terms'] ?? []));
        $contract = new PayoutContract(...array_replace([
            'corridor' => $c, 'reference' => 'fixture-contract', 'state' => EvidenceState::Verified,
            'source' => EvidenceSource::LocalAttestation, 'revocation' => RevocationStatus::NotRevoked,
            'pricingVersion' => $terms->pricingVersion, 'feePlanId' => $terms->feePlanId,
            'supportedMethods' => ['LOCAL'], 'currencyScales' => ['USD' => 2, 'GBP' => 2], 'rateScale' => 8,
            'roundingRule' => 'EXACT', 'calculationReference' => 'fixture-net-principal-plus-one-fee-only',
            'fxCalculationReference' => 'fixture-fx-amount-semantics', 'feeCurrency' => 'USD',
            'feePayer' => FeePayer::Sender, 'feeAllocation' => FeeAllocation::SenderAdditional,
            'validFrom' => $this->at('00:00:00'), 'expiresAt' => $this->at('02:00:00'),
        ], $changes['contract'] ?? []));
        $pricing = [];
        foreach ([PricingStage::Effective, PricingStage::ProviderQuote] as $stage) {
            $pricing[] = new PricingEvidence($stage === PricingStage::ProviderQuote ? $quote->id : 'fixture-effective',
                $stage, $changes['pricing_state'] ?? EvidenceState::Verified,
                $stage === PricingStage::ProviderQuote ? EvidenceSource::ProviderResponse : EvidenceSource::LocalAttestation,
                $stage === PricingStage::ProviderQuote ? $terms->quoteProvenance : 'fixture-effective-evidence',
                $terms, $this->at('00:00:00'), $this->at('00:00:00'), $this->at('02:00:00'), RevocationStatus::NotRevoked);
        }
        $eligibility = array_map(fn (EvidenceKind $kind): CorridorEvidence => new CorridorEvidence($c, $kind,
            EvidenceState::Verified, 'fixture-'.$kind->value, $this->at('00:00:00'), $this->at('02:00:00'), false),
            EvidenceKind::required());
        if (isset($changes['binding_corridor'])) {
            $preparation = FinalizedUsdPayoutPreparation::prepare($this->usdContext($changes['binding_corridor']),
                $mode, $mode === PayoutAmountMode::Source ? '1000' : '784', $actor, $this->at());
        }
        if (isset($changes['confirmation_quote_id'])) {
            $quote = PayoutRateQuote::fromJson(str_replace('"id":"fixture-finalized-quote"',
                '"id":"'.$changes['confirmation_quote_id'].'"', $body), $r, $context);
        }

        return PayoutConfirmation::prepare($actor, $context, $quote, $terms, $contract, $pricing, $eligibility,
            $changes['mode_references'] ?? [$mode->value => 'fixture-approved-net-principal-remittance-semantics'],
            $this->at(), $bindPreparation ? $preparation : null, $changes['instruction'] ?? null);
    }

}
