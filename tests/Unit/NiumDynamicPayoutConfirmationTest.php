<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Nium\DynamicMultiCurrency\CorridorEvidence;
use App\Domain\Nium\DynamicMultiCurrency\CorridorIdentity;
use App\Domain\Nium\DynamicMultiCurrency\EvidenceKind;
use App\Domain\Nium\DynamicMultiCurrency\EvidenceState;
use App\Domain\Nium\DynamicMultiCurrency\Financial\Decimal;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FeeAllocation;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FeePayer;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FinancialTerms;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FxRequirement;
use App\Domain\Nium\DynamicMultiCurrency\Financial\Money;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutAmountMode;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutConfirmation;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutContract;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutQuoteContext;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutQuoteRequest;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutRateQuote;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PricingEvidence;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PricingStage;
use App\Domain\Nium\DynamicMultiCurrency\Options\BeneficiarySnapshot;
use App\Domain\Nium\DynamicMultiCurrency\Options\CustomerAccountScope;
use App\Domain\Nium\DynamicMultiCurrency\Registry\EvidenceSource;
use App\Domain\Nium\DynamicMultiCurrency\Registry\RevocationStatus;
use DateTimeImmutable;
use Error;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Entirely synthetic supplied assertions. No runtime, database or HTTP acquisition. */
final class NiumDynamicPayoutConfirmationTest extends TestCase
{
    private function time(string $time = '00:10:00'): DateTimeImmutable
    {
        return new DateTimeImmutable('2030-01-01T'.$time.'Z');
    }

    private function money(string $currency, string $amount): Money
    {
        return new Money($currency, new Decimal($amount));
    }

    private function fixture(PayoutAmountMode $mode = PayoutAmountMode::Source, array $changes = []): array
    {
        $c = new CorridorIdentity(...array_replace([
            'provider' => 'nium', 'environment' => 'sandbox', 'client' => 'synthetic-client',
            'legalEntity' => 'synthetic-entity', 'customer' => 'synthetic-customer', 'account' => 'synthetic-account',
            'fundingWallet' => 'synthetic-wallet', 'sourceCurrency' => 'EUR', 'destinationCountry' => 'GB',
            'destinationCurrency' => 'GBP', 'payoutMethod' => 'LOCAL', 'customerType' => 'CORPORATE',
            'beneficiaryType' => 'INDIVIDUAL', 'contractVersion' => 'synthetic-contract-v1', 'profileVersion' => 'synthetic-profile-v1',
        ], $changes['corridor'] ?? []));
        $scope = new CustomerAccountScope('synthetic-user', $c->provider, $c->environment, $c->client,
            $c->legalEntity, $c->customer, $c->account, $c->customerType, $c->profileVersion,
            [$c->sourceCurrency => $c->fundingWallet], EvidenceState::Verified, $this->time('00:00:00'),
            $this->time('01:00:00'), RevocationStatus::NotRevoked);
        $beneficiary = new BeneficiarySnapshot(...array_replace([
            'scope' => $scope, 'destinationCountry' => $c->destinationCountry, 'destinationCurrency' => $c->destinationCurrency,
            'payoutMethod' => $c->payoutMethod, 'beneficiaryType' => $c->beneficiaryType, 'state' => EvidenceState::Verified,
            'registered' => true, 'observedAt' => $this->time('00:00:00'), 'expiresAt' => $this->time('01:00:00'),
            'revocation' => RevocationStatus::NotRevoked,
        ], $changes['beneficiary'] ?? []));
        $context = new PayoutQuoteContext(...array_replace([
            'corridor' => $c, 'beneficiary' => $beneficiary, 'providerId' => 'synthetic-provider',
            'providerAccountId' => 'synthetic-provider-account', 'beneficiaryId' => 'synthetic-beneficiary',
            'externalBeneficiaryId' => 'synthetic-registration', 'receivingAccountFingerprint' => str_repeat('a', 64),
        ], $changes['context'] ?? []));
        $source = $changes['source'] ?? '100';
        $destination = $changes['destination'] ?? '80';
        $request = new PayoutQuoteRequest($c->customer, $c->sourceCurrency, $c->destinationCurrency,
            $mode, $mode === PayoutAmountMode::Source ? $source : $destination, '1_hour');
        $body = json_encode(array_replace([
            'id' => 'synthetic-quote-1', 'quoteType' => 'payout', 'quoteIntent' => 'EXECUTABLE',
            'customerHashId' => $c->customer, 'sourceCurrencyCode' => $c->sourceCurrency,
            'destinationCurrencyCode' => $c->destinationCurrency, 'conversionSchedule' => 'immediate',
            'executionType' => 'at_conversion_time', 'lockPeriod' => '1_hour',
            'sourceAmount' => $source, 'destinationAmount' => $destination, 'netExchangeRate' => '0.8',
            'expiryTime' => '2030-01-01T01:00:00Z',
        ], $changes['response'] ?? []), JSON_THROW_ON_ERROR);
        $quote = PayoutRateQuote::fromJson($body, $request, $context);
        $terms = new FinancialTerms(...array_replace([
            'corridor' => $c, 'principal' => $this->money($c->sourceCurrency, $source), 'fxRequirement' => FxRequirement::Required,
            'fxRate' => new Decimal('0.8'), 'quoteId' => $quote->id, 'quoteProvenance' => 'synthetic-provider-evidence',
            'fxMarginPercent' => new Decimal('0'), 'conversionAmount' => $this->money($c->destinationCurrency, $destination),
            'transactionFee' => $this->money($c->sourceCurrency, '2'), 'feePayer' => FeePayer::Sender,
            'feeAllocation' => FeeAllocation::SenderAdditional,
            'totalWalletDebit' => $this->money($c->sourceCurrency, (new Decimal($source))->plus(new Decimal('2'))->value),
            'estimatedRecipient' => $this->money($c->destinationCurrency, $destination),
            'pricingSource' => 'synthetic-pricing', 'pricingVersion' => 'synthetic-v1', 'feePlanId' => 'synthetic-fee-plan',
        ], $changes['terms'] ?? []));
        $contract = new PayoutContract(...array_replace([
            'corridor' => $c, 'reference' => 'synthetic-payout-contract', 'state' => EvidenceState::Verified,
            'source' => EvidenceSource::Synthetic, 'revocation' => RevocationStatus::NotRevoked,
            'pricingVersion' => $terms->pricingVersion, 'feePlanId' => $terms->feePlanId, 'supportedMethods' => ['LOCAL'],
            'currencyScales' => ['EUR' => 2, 'GBP' => 2, 'JPY' => 0], 'rateScale' => 10, 'roundingRule' => 'EXACT',
            'calculationReference' => 'synthetic-all-fees-debit-contract',
            'fxCalculationReference' => 'synthetic-authoritative-amounts-contract',
            'feeCurrency' => $c->sourceCurrency, 'feePayer' => FeePayer::Sender, 'feeAllocation' => FeeAllocation::SenderAdditional,
            'validFrom' => $this->time('00:00:00'), 'expiresAt' => $this->time('01:00:00'),
        ], $changes['contract'] ?? []));
        $pricing = [];
        foreach ([PricingStage::Effective, PricingStage::ProviderQuote] as $stage) {
            $pricing[] = new PricingEvidence($stage === PricingStage::ProviderQuote ? $quote->id : 'synthetic-effective',
                $stage, EvidenceState::Verified, EvidenceSource::Synthetic,
                $stage === PricingStage::ProviderQuote ? $terms->quoteProvenance : 'synthetic-effective-evidence',
                $terms, $this->time('00:00:00'), $this->time('00:00:00'), $this->time('01:00:00'), RevocationStatus::NotRevoked);
        }
        $eligibility = [];
        foreach (EvidenceKind::required() as $kind) {
            $eligibility[] = new CorridorEvidence($c, $kind, EvidenceState::Verified, 'synthetic-'.$kind->value,
                $this->time('00:00:00'), $this->time('01:00:00'), true);
        }

        return ['authenticatedUserId' => 'synthetic-user', 'context' => $context, 'quote' => $quote,
            'terms' => $terms, 'contract' => $contract, 'pricingEvidence' => $pricing, 'eligibilityEvidence' => $eligibility,
            'amountModeReferences' => [$mode->value => 'synthetic-approved-mode-semantics'], 'asOf' => $this->time()];
    }

    public static function modes(): array
    {
        return [[PayoutAmountMode::Source], [PayoutAmountMode::Destination]];
    }

    #[DataProvider('modes')]
    public function test_both_modes_use_exact_reference_amount_and_explicit_confirmation(PayoutAmountMode $mode): void
    {
        $f = $this->fixture($mode);
        $request = $f['quote']->request;
        $other = $mode === PayoutAmountMode::Source ? 'destinationAmount' : 'sourceAmount';
        $this->assertStringContainsString('"'.$mode->quoteField().'":'.$request->amount->value, $request->json());
        $this->assertStringNotContainsString('"'.$other.'"', $request->json());
        $review = PayoutConfirmation::prepare(...$f);
        $this->assertNull($review->confirmationBinding());
        $confirmed = $review->confirm('synthetic-user', $review->fingerprint(), $this->time());
        $this->assertSame($review->fingerprint(), $confirmed->fingerprint());
        $this->assertSame('synthetic-user', $confirmed->confirmationBinding()['actor']);
        $this->assertSame('DENIED', $confirmed->confirmationBinding()['dispatch']);
        $this->assertTrue($confirmed->synthetic);
        $this->assertSame('102', $confirmed->snapshot()['financial_terms']['wallet_debit']['amount']);
    }

    public function test_numeric_json_preserves_more_precision_than_a_float_can_represent(): void
    {
        $f = $this->fixture(changes: ['source' => '9007199254740993.01']);
        $request = $f['quote']->request;
        $body = str_replace('"sourceAmount":"9007199254740993.01"', '"sourceAmount":9007199254740993.01',
            json_encode(['id' => 'synthetic-quote-1', 'quoteType' => 'payout', 'quoteIntent' => 'EXECUTABLE',
                'customerHashId' => $request->customer, 'sourceCurrencyCode' => 'EUR', 'destinationCurrencyCode' => 'GBP',
                'conversionSchedule' => 'immediate', 'executionType' => 'at_conversion_time', 'lockPeriod' => '1_hour',
                'sourceAmount' => '9007199254740993.01', 'destinationAmount' => '80', 'netExchangeRate' => '0.8000000001',
                'expiryTime' => '2030-01-01T01:00:00Z'], JSON_THROW_ON_ERROR));
        $quote = PayoutRateQuote::fromJson($body, $request, $f['context']);
        $this->assertSame('9007199254740993.01', $quote->sourceAmount->value);
        $this->assertSame('0.8000000001', $quote->rate->value);
        $this->assertStringContainsString('"sourceAmount":9007199254740993.01', $request->json());
    }

    public static function invalidAmounts(): array
    {
        return array_map(static fn (string $n): array => [$n], ['0', '-1', '+1', '01', '1e2', '1.', '.5', ' 1', 'NaN', str_repeat('9', 65)]);
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amount_formats_are_rejected(string $amount): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PayoutQuoteRequest('synthetic-customer', 'EUR', 'GBP', PayoutAmountMode::Source, $amount);
    }

    public static function invalidFinancialEvidence(): array
    {
        return array_map(static fn (string $case): array => [$case], [
            'fee', 'debit', 'equation', 'quote-id', 'rate', 'provider-evidence', 'eligibility', 'mode-semantics',
            'currency-precision', 'expiry', 'beneficiary-currency', 'unregistered', 'wallet', 'beneficiary-id', 'client-consent',
        ]);
    }

    #[DataProvider('invalidFinancialEvidence')]
    public function test_missing_or_mismatched_contract_data_is_rejected(string $case): void
    {
        $changes = match ($case) {
            'fee' => ['terms' => ['transactionFee' => null]],
            'debit' => ['terms' => ['totalWalletDebit' => null]],
            'equation' => ['terms' => ['totalWalletDebit' => $this->money('EUR', '999')]],
            'quote-id' => ['terms' => ['quoteId' => 'synthetic-other-quote']],
            'rate' => ['terms' => ['fxRate' => new Decimal('0.7')]],
            'currency-precision' => ['source' => '100.001'],
            'expiry' => ['response' => ['expiryTime' => '2030-01-01T00:10:00Z']],
            'beneficiary-currency' => ['beneficiary' => ['destinationCurrency' => 'JPY']],
            'unregistered' => ['beneficiary' => ['registered' => false]],
            default => [],
        };
        $f = $this->fixture(changes: $changes);
        if ($case === 'provider-evidence') {
            $f['pricingEvidence'] = [$f['pricingEvidence'][0]];
        } elseif ($case === 'eligibility') {
            $f['eligibilityEvidence'] = [];
        } elseif ($case === 'mode-semantics') {
            $f['amountModeReferences'] = [];
        } elseif ($case === 'wallet') {
            $other = $this->fixture(changes: ['corridor' => ['fundingWallet' => 'synthetic-another-wallet']]);
            $f['context'] = $other['context'];
        } elseif ($case === 'beneficiary-id') {
            $context = $f['context'];
            $f['context'] = new PayoutQuoteContext($context->corridor, $context->beneficiary, $context->providerId,
                $context->providerAccountId, 'different-beneficiary',
                $context->externalBeneficiaryId, $context->receivingAccountFingerprint);
        } elseif ($case === 'client-consent') {
            $f['pricingEvidence'][] = new PricingEvidence('untrusted-consent', PricingStage::CustomerConfirmed,
                EvidenceState::Verified, EvidenceSource::Synthetic, 'untrusted-client', $f['terms'],
                $this->time(), $this->time(), $this->time('01:00:00'), RevocationStatus::NotRevoked);
        }
        $this->expectException(InvalidArgumentException::class);
        PayoutConfirmation::prepare(...$f);
    }

    public function test_jpy_precision_is_taken_from_the_contract_and_never_rounded(): void
    {
        $f = $this->fixture(PayoutAmountMode::Destination, ['corridor' => ['destinationCurrency' => 'JPY'], 'destination' => '80.1']);
        $this->expectException(InvalidArgumentException::class);
        PayoutConfirmation::prepare(...$f);
    }

    public function test_fingerprint_binds_amount_mode_and_receiving_identity(): void
    {
        $source = PayoutConfirmation::prepare(...$this->fixture());
        $destination = PayoutConfirmation::prepare(...$this->fixture(PayoutAmountMode::Destination));
        $this->assertNotSame($source->fingerprint(), $destination->fingerprint());
        $differentBeneficiary = PayoutConfirmation::prepare(...$this->fixture(changes: [
            'context' => ['beneficiaryId' => 'synthetic-another-beneficiary'],
        ]));
        $this->assertNotSame($source->fingerprint(), $differentBeneficiary->fingerprint());
        $differentAmount = PayoutConfirmation::prepare(...$this->fixture(changes: ['source' => '101']));
        $this->assertNotSame($source->fingerprint(), $differentAmount->fingerprint());
        $snapshot = $source->snapshot();
        $snapshot['financial_terms']['principal']['amount'] = '1';
        $this->assertSame('100', $source->snapshot()['financial_terms']['principal']['amount']);
        $this->expectException(Error::class);
        $source->terms->quoteId = 'changed';
    }

    public function test_provider_origin_is_part_of_the_immutable_quote_and_confirmation_context(): void
    {
        $gateway = $this->fixture(changes: ['corridor' => ['environment' => 'production'],
            'context' => ['providerOrigin' => 'https://gateway.nium.com']]);
        $spend = $this->fixture(changes: ['corridor' => ['environment' => 'production'],
            'context' => ['providerOrigin' => 'https://api.spend.nium.com']]);
        $this->assertSame('https://gateway.nium.com', $gateway['context']->snapshot()['provider_origin']);
        $this->assertNotSame($gateway['context']->fingerprint(), $spend['context']->fingerprint());
        $this->expectExceptionMessage('payout_quote_terms_binding_mismatch');
        PayoutConfirmation::prepare(...array_replace($gateway, ['quote' => $spend['quote']]));
    }

    public static function invalidConfirmations(): array
    {
        return [['actor'], ['fingerprint'], ['expiry']];
    }

    #[DataProvider('invalidConfirmations')]
    public function test_confirmation_rejects_wrong_actor_changed_terms_and_expiry(string $case): void
    {
        $review = PayoutConfirmation::prepare(...$this->fixture());
        $this->expectException(InvalidArgumentException::class);
        $review->confirm($case === 'actor' ? 'another-user' : 'synthetic-user',
            $case === 'fingerprint' ? str_repeat('0', 64) : $review->fingerprint(),
            $case === 'expiry' ? $this->time('01:00:00') : $this->time());
    }

    public function test_sgd_local_can_only_prepare_with_complete_synthetic_scoped_evidence(): void
    {
        $f = $this->fixture(changes: ['corridor' => ['destinationCountry' => 'SG', 'destinationCurrency' => 'SGD'],
            'contract' => ['currencyScales' => ['EUR' => 2, 'SGD' => 2]]]);
        $review = PayoutConfirmation::prepare(...$f);
        $this->assertTrue($review->synthetic);
        $this->assertSame('SGD', $review->context->corridor->destinationCurrency);
        $this->assertSame('DENIED', $review->confirm('synthetic-user', $review->fingerprint(), $this->time())->confirmationBinding()['dispatch']);
    }

    public function test_hk_swift_same_currency_is_not_admitted_by_new_quote_contract(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PayoutQuoteRequest('synthetic-customer', 'USD', 'USD', PayoutAmountMode::Source, '100');
    }

    public static function invalidProviderResponses(): array
    {
        return [
            [['quoteType' => 'balance_transfer']], [['executionType' => 'manual']],
            [['customerHashId' => 'different-customer']], [['sourceCurrencyCode' => 'USD']],
            [['id' => 123]], [['expiryTime' => '2030-02-31T00:00:00Z']],
            [['sourceAmount' => null]], [['destinationAmount' => null]],
            [['netExchangeRate' => '1e-3']], [['sourceAmount' => '101']],
        ];
    }

    #[DataProvider('invalidProviderResponses')]
    public function test_provider_response_must_supply_exact_bound_evidence(array $response): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->fixture(changes: ['response' => $response]);
    }

    public function test_duplicate_provider_quote_identifiers_are_rejected(): void
    {
        $f = $this->fixture();
        $this->expectException(InvalidArgumentException::class);
        PayoutRateQuote::fromJson('{"id":"one","id":"two"}', $f['quote']->request, $f['context']);
    }

    public function test_destination_input_cannot_be_reduced_by_recipient_deducted_fees(): void
    {
        $f = $this->fixture(PayoutAmountMode::Destination, ['terms' => [
            'transactionFee' => $this->money('GBP', '2'), 'feePayer' => FeePayer::Recipient,
            'feeAllocation' => FeeAllocation::RecipientDeducted, 'totalWalletDebit' => $this->money('EUR', '100'),
            'estimatedRecipient' => $this->money('GBP', '78'),
        ], 'contract' => ['feeCurrency' => 'GBP', 'feePayer' => FeePayer::Recipient,
            'feeAllocation' => FeeAllocation::RecipientDeducted]]);
        $this->expectException(InvalidArgumentException::class);
        PayoutConfirmation::prepare(...$f);
    }

    public function test_zero_decimal_currency_accepts_whole_amounts(): void
    {
        $f = $this->fixture(PayoutAmountMode::Destination, ['corridor' => ['destinationCurrency' => 'JPY']]);
        $review = PayoutConfirmation::prepare(...$f);
        $this->assertSame('80', $review->terms->estimatedRecipient->amount->value);
    }

    public function test_unsupported_payout_method_is_rejected(): void
    {
        $f = $this->fixture(changes: ['contract' => ['supportedMethods' => ['SWIFT']]]);
        $this->expectException(InvalidArgumentException::class);
        PayoutConfirmation::prepare(...$f);
    }

    public function test_hold_evidence_blocks_an_otherwise_complete_review(): void
    {
        $f = $this->fixture();
        $f['eligibilityEvidence'][] = new CorridorEvidence($f['context']->corridor, EvidenceKind::Override,
            EvidenceState::Hold, 'synthetic-hold', $this->time('00:00:00'), $this->time('01:00:00'), true);
        $this->expectException(InvalidArgumentException::class);
        PayoutConfirmation::prepare(...$f);
    }
}
