<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Nium\DynamicMultiCurrency\CorridorIdentity;
use App\Domain\Nium\DynamicMultiCurrency\EvidenceState;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FinalizedUsdPayoutPreparation;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FinancialTermsValidator;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutAmountMode;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutQuoteContext;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutQuoteRequest;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutRateQuote;
use App\Domain\Nium\DynamicMultiCurrency\Options\BeneficiarySnapshot;
use App\Domain\Nium\DynamicMultiCurrency\Options\CustomerAccountScope;
use App\Domain\Nium\DynamicMultiCurrency\Registry\RevocationStatus;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeError;

/** Synthetic business/quote preparation only; no claim about actual Nium fee charging. */
final class NiumFinalizedUsdPayoutPreparationTest extends TestCase
{
    private \Closure $runtimeGuard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtimeGuard = static function (string $class): void {
            if ((str_starts_with($class, 'App\\') && ! str_starts_with($class, 'App\\Domain\\Nium\\DynamicMultiCurrency\\'))
                || str_starts_with($class, 'Illuminate\\') || str_starts_with($class, 'GuzzleHttp\\')) {
                throw new LogicException('Preparation must not load application, HTTP or database runtime.');
            }
        };
        spl_autoload_register($this->runtimeGuard, true, true);
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->runtimeGuard);
        parent::tearDown();
    }

    private function at(string $time = '00:10:00'): DateTimeImmutable
    {
        return new DateTimeImmutable('2030-01-01T'.$time.'Z');
    }

    private function context(array $changes = [], bool $registered = true): PayoutQuoteContext
    {
        $c = new CorridorIdentity(...array_replace([
            'provider' => 'nium', 'environment' => 'sandbox', 'client' => 'synthetic-client',
            'legalEntity' => 'synthetic-entity', 'customer' => 'synthetic-customer', 'account' => 'synthetic-account',
            'fundingWallet' => 'synthetic-wallet', 'sourceCurrency' => 'USD', 'destinationCountry' => 'HK',
            'destinationCurrency' => 'HKD', 'payoutMethod' => 'LOCAL', 'customerType' => 'CORPORATE',
            'beneficiaryType' => 'INDIVIDUAL', 'contractVersion' => 'synthetic-contract', 'profileVersion' => 'synthetic-profile',
        ], $changes));
        $scope = new CustomerAccountScope('synthetic-user', $c->provider, $c->environment, $c->client,
            $c->legalEntity, $c->customer, $c->account, $c->customerType, $c->profileVersion,
            [$c->sourceCurrency => $c->fundingWallet], EvidenceState::Verified,
            $this->at('00:00:00'), $this->at('02:00:00'), RevocationStatus::NotRevoked);
        $beneficiary = new BeneficiarySnapshot($scope, $c->destinationCountry, $c->destinationCurrency,
            $c->payoutMethod, $c->beneficiaryType, EvidenceState::Verified, $registered,
            $this->at('00:00:00'), $this->at('02:00:00'), RevocationStatus::NotRevoked);

        return new PayoutQuoteContext($c, $beneficiary, 'synthetic-provider', 'synthetic-provider-account',
            'synthetic-beneficiary', 'synthetic-registration', str_repeat('a', 64));
    }

    private function preparation(PayoutAmountMode $mode = PayoutAmountMode::Source, string $amount = '1000',
        ?PayoutQuoteContext $context = null): FinalizedUsdPayoutPreparation
    {
        return FinalizedUsdPayoutPreparation::prepare($context ?? $this->context(), $mode, $amount,
            'synthetic-user', $this->at());
    }

    private function quote(FinalizedUsdPayoutPreparation $p, string $source = '980', string $destination = '7644',
        array $changes = [], ?PayoutQuoteRequest $request = null): PayoutRateQuote
    {
        $r = $request ?? $p->quoteRequest;
        $body = json_encode(array_replace([
            'id' => 'synthetic-quote', 'quoteType' => 'payout', 'quoteIntent' => 'EXECUTABLE',
            'customerHashId' => $r->customer, 'sourceCurrencyCode' => $r->sourceCurrency,
            'destinationCurrencyCode' => $r->destinationCurrency, 'conversionSchedule' => 'immediate',
            'executionType' => 'at_conversion_time', 'lockPeriod' => $r->lockPeriod,
            'sourceAmount' => $source, 'destinationAmount' => $destination, 'netExchangeRate' => '7.8',
            'expiryTime' => '2030-01-01T01:00:00Z',
        ], $changes), JSON_THROW_ON_ERROR);
        // Use actual JSON number tokens without creating PHP floats, including
        // the values above 2^53 in the precision test.
        $body = str_replace(['"sourceAmount":'.json_encode($source, JSON_THROW_ON_ERROR),
            '"destinationAmount":'.json_encode($destination, JSON_THROW_ON_ERROR)],
            ['"sourceAmount":'.$source, '"destinationAmount":'.$destination], $body);

        return PayoutRateQuote::fromJson($body, $r, $p->context);
    }

    public function test_send_amount_includes_fee_and_only_net_principal_is_proposed_to_quote(): void
    {
        $p = $this->preparation(amount: '1000.00');
        $this->assertSame('1000', $p->plannedCustomerDebit->amount->value);
        $this->assertSame('USD', $p->plannedCustomerDebit->currency);
        $this->assertSame('980', $p->proposedConversionPrincipal->amount->value);
        $this->assertStringContainsString('"sourceAmount":980', $p->quoteRequest->json());
        $this->assertStringNotContainsString('"destinationAmount"', $p->quoteRequest->json());
        $this->assertStringNotContainsString('markup', $p->quoteRequest->json());
        $this->assertNull($p->quote);
        $quoted = $p->withQuote($this->quote($p), 'synthetic-user', $this->at());
        $s = $quoted->snapshot('synthetic-user', $this->at());
        $this->assertSame(['currency' => 'USD', 'amount' => '1000'], $s['entered_amount']);
        $this->assertSame('1000', $s['planned_customer_wallet_debit']['amount']);
        $this->assertSame(['currency' => 'USD', 'amount' => '20'], $s['included_fee']);
        $this->assertSame(['currency' => 'HKD', 'amount' => '7644'], $s['quoted_receive_amount']);
        $this->assertSame('7.8', $s['quoted_rate']);
        $this->assertSame('synthetic-quote', $s['quote_id']);
        // No second application of either 0.13% or 1.5% to the provider output.
        $this->assertSame('0.13', $s['expected_fx_margin_percent']);
    }

    public function test_receive_mode_uses_destination_quote_then_adds_fee_to_quoted_usd_principal(): void
    {
        $p = $this->preparation(PayoutAmountMode::Destination, '7644');
        $this->assertNull($p->plannedCustomerDebit);
        $this->assertNull($p->proposedConversionPrincipal);
        $this->assertStringContainsString('"destinationAmount":7644', $p->quoteRequest->json());
        $this->assertStringNotContainsString('"sourceAmount"', $p->quoteRequest->json());
        $s = $p->withQuote($this->quote($p), 'synthetic-user', $this->at())->snapshot('synthetic-user', $this->at());
        $this->assertSame(['currency' => 'HKD', 'amount' => '7644'], $s['entered_amount']);
        $this->assertSame('1000', $s['planned_customer_wallet_debit']['amount']);
        $this->assertSame('980', $s['proposed_conversion_principal']['amount']);
        $this->assertSame('7644', $s['quoted_receive_amount']['amount']);
        $this->assertSame('QUOTE_REFERENCE_ONLY', $s['receive_amount_status']);
    }

    public function test_exact_decimal_boundary_and_large_values_do_not_round_through_float(): void
    {
        foreach (['20.01' => '0.01', '9007199254740993.01' => '9007199254740973.01'] as $debit => $principal) {
            $p = $this->preparation(amount: $debit);
            $this->assertSame($principal, $p->quoteRequest->amount->value);
            $s = $p->withQuote($this->quote($p, $principal, '0.01'), 'synthetic-user', $this->at())
                ->snapshot('synthetic-user', $this->at());
            $this->assertSame($debit, $s['planned_customer_wallet_debit']['amount']);
        }
    }

    public static function margins(): array
    {
        return [
            ['HK', 'HKD', 'LOCAL', '0.13'],
            ['GB', 'GBP', 'LOCAL', '0.13'],
            ['DK', 'EUR', 'LOCAL', '0.13'],
            ['DE', 'EUR', 'LOCAL', '0.13'],
            ['JP', 'JPY', 'LOCAL', '0.28'],
            ['KR', 'KRW', 'LOCAL', '0.44'],
            ['TH', 'THB', 'LOCAL', '0.28'],
            ['CH', 'EUR', 'LOCAL', '0.13'],
            ['FR', 'EUR', 'LOCAL', '0.13'],
            ['CH', 'GBP', 'LOCAL', '0.13'],
            ['AU', 'AUD', 'LOCAL', '1.5'],
        ];
    }

    #[DataProvider('margins')]
    public function test_margin_metadata_uses_observed_default_plan_without_granting_eligibility(
        string $country, string $currency, string $method, string $margin,
    ): void {
        $p = $this->preparation(context: $this->context(['destinationCountry' => $country,
            'destinationCurrency' => $currency, 'payoutMethod' => $method]));
        $s = $p->withQuote($this->quote($p), 'synthetic-user', $this->at())->snapshot('synthetic-user', $this->at());
        $this->assertSame($margin, $s['expected_fx_margin_percent']);
        $this->assertSame('production_v2_active_default_fee_plan_observation', $s['margin_basis']);
        $this->assertSame('980', $s['proposed_conversion_principal']['amount']);
        $this->assertSame('1000', $s['planned_customer_wallet_debit']['amount']);
        $this->assertSame('7644', $s['quoted_receive_amount']['amount']);
        $this->assertSame('7.8', $s['quoted_rate']);
        $this->assertSame('UNVERIFIED', $s['corridor_eligibility']);
        $this->assertSame('UNVERIFIED', $s['provider_margin_applicability']);
        $this->assertSame('DENIED', $s['admission']);
    }

    public static function invalidAmounts(): array
    {
        return array_map(static fn (string $value): array => [$value],
            ['0', '19.99', '20', '20.00', '-1', '1e3', '1000.001', ' 1000', 'NaN', str_repeat('9', 65)]);
    }

    #[DataProvider('invalidAmounts')]
    public function test_send_amount_must_be_exact_usd_greater_than_twenty(string $amount): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->preparation(amount: $amount);
    }

    public static function blockedContexts(): array
    {
        return [
            [['sourceCurrency' => 'EUR']],
            [['destinationCountry' => 'HK', 'destinationCurrency' => 'USD', 'payoutMethod' => 'SWIFT']],
            [['destinationCountry' => 'SG', 'destinationCurrency' => 'SGD', 'payoutMethod' => 'LOCAL']],
            [['provider' => 'other']],
        ];
    }

    #[DataProvider('blockedContexts')]
    public function test_non_usd_funding_legacy_hk_and_existing_sgd_hold_remain_excluded(array $changes): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->preparation(context: $this->context($changes));
    }

    public function test_beneficiary_must_be_registered_before_preparation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->preparation(context: $this->context(registered: false));
    }

    public static function mismatches(): array
    {
        return array_map(static fn (string $value): array => [$value],
            ['amount', 'mode', 'currency', 'customer', 'lock', 'corridor', 'wallet', 'receiving-account', 'expired', 'actor']);
    }

    #[DataProvider('mismatches')]
    public function test_quote_rebinding_and_expiry_are_rejected(string $case): void
    {
        $p = $this->preparation();
        $other = match ($case) {
            'amount' => $this->preparation(amount: '1001'),
            'mode' => $this->preparation(PayoutAmountMode::Destination, '7644'),
            'currency' => $this->preparation(context: $this->context(['destinationCurrency' => 'GBP'])),
            'customer' => $this->preparation(context: $this->context(['customer' => 'synthetic-other'])),
            'corridor' => $this->preparation(context: $this->context(['destinationCountry' => 'GB'])),
            'wallet' => $this->preparation(context: $this->context(['fundingWallet' => 'synthetic-other'])),
            'receiving-account' => $this->preparation(context: new PayoutQuoteContext($p->context->corridor,
                $p->context->beneficiary, 'synthetic-provider', 'synthetic-provider-account',
                'synthetic-beneficiary', 'synthetic-registration', str_repeat('b', 64))),
            default => $p,
        };
        $request = $case === 'lock' ? new PayoutQuoteRequest('synthetic-customer', 'USD', 'HKD',
            PayoutAmountMode::Source, '980', '1_hour') : null;
        $q = $this->quote($p, request: $request);
        $this->expectException(InvalidArgumentException::class);
        $other->withQuote($q, $case === 'actor' ? 'another-user' : 'synthetic-user',
            $case === 'expired' ? $this->at('01:00:00') : $this->at());
    }

    public function test_rendering_a_previously_accepted_quote_rechecks_expiry(): void
    {
        $p = $this->preparation();
        $quoted = $p->withQuote($this->quote($p), 'synthetic-user', $this->at());
        $this->expectException(InvalidArgumentException::class);
        $quoted->snapshot('synthetic-user', $this->at('01:00:00'));
    }

    public function test_receive_quote_with_unrepresentable_source_is_not_rounded_to_fit_fee_split(): void
    {
        $p = $this->preparation(PayoutAmountMode::Destination, '7644');
        $q = $this->quote($p, '980.001');
        $this->expectException(InvalidArgumentException::class);
        $p->withQuote($q, 'synthetic-user', $this->at());
    }

    public function test_send_quote_with_unrepresentable_destination_is_not_rounded_into_a_receive_amount(): void
    {
        $p = $this->preparation();
        $q = $this->quote($p, destination: '7644.001');
        $this->expectException(InvalidArgumentException::class);
        $p->withQuote($q, 'synthetic-user', $this->at());
    }

    public function test_wrong_provider_echo_is_rejected_by_existing_quote_parser(): void
    {
        $p = $this->preparation();
        $this->expectException(InvalidArgumentException::class);
        $this->quote($p, changes: ['customerHashId' => 'synthetic-other']);
    }

    public function test_quote_does_not_turn_business_rule_preparation_into_financial_authority(): void
    {
        $p = $this->preparation();
        $quoted = $p->withQuote($this->quote($p), 'synthetic-user', $this->at());
        $s = $quoted->snapshot('synthetic-user', $this->at());
        $this->assertSame('BLOCKED', $s['provider_fee_mapping']);
        $this->assertSame('NOT_VERIFIED', $s['financial_verification']);
        $this->assertSame('UNVERIFIED', $s['provider_wallet_debit']);
        foreach (['confirmation', 'reservation', 'admission', 'dispatch'] as $key) {
            $this->assertSame('DENIED', $s[$key]);
        }
        // The actual existing financial boundary cannot accept this planning type.
        $this->expectException(TypeError::class);
        (new FinancialTermsValidator)->evaluate($quoted, $this->at(), null);
    }
}
