<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Financial;

use App\Domain\Nium\DynamicMultiCurrency\CorridorIdentity;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * AUTO-02C.49 business-rule preparation only, not provider financial evidence.
 * No remittance payload, FinancialTerms, confirmation, reservation or dispatch.
 * Net principal is a quote/instruction input. Origin customer pricing is separate
 * from later provider fee and settlement observations; no provider debit equality is asserted.
 */
final readonly class FinalizedUsdPayoutPreparation
{
    public const VERSION = 'nium-usd-inclusive-fee-preparation-v4';

    // Arithmetic shared with the accepted USD-inclusive LOCAL customer contract.
    // Preparation alone still grants no financial or provider authority.
    public const FEE_USD = '20';

    public const DEFAULT_MARGIN_PERCENT = '1.5';

    private function __construct(
        public PayoutQuoteContext $context,
        public PayoutQuoteRequest $quoteRequest,
        public Decimal $expectedFxMarginPercent,
        public string $marginBasis,
        public ?Money $plannedCustomerDebit,
        public ?Money $proposedConversionPrincipal,
        public ?PayoutRateQuote $quote,
    ) {}

    /**
     * SOURCE_AMOUNT here is the customer's total USD spend; the existing Nium
     * PayoutQuoteRequest still means conversion principal. Do not change that contract.
     * DESTINATION_AMOUNT is the desired receive amount, not a locally computed FX value.
     * A request value is not permission to send HTTP or proof of corridor entitlement.
     */
    public static function prepare(
        PayoutQuoteContext $context,
        PayoutAmountMode $mode,
        string $enteredAmount,
        string $actor,
        DateTimeImmutable $asOf,
        string $lockPeriod = '5_mins',
    ): self {
        $context->assertUsable($actor, $asOf);
        $c = $context->corridor;
        if ($c->provider !== 'nium' || $c->sourceCurrency !== 'USD' || $c->destinationCurrency === 'USD') {
            throw new InvalidArgumentException('finalized_usd_payout_scope_invalid');
        }
        $request = self::quoteRequestForAmount($c->customer, $c->destinationCurrency, $mode, $enteredAmount, $lockPeriod);
        $debit = null;
        $principal = null;
        if ($mode === PayoutAmountMode::Source) {
            $debit = new Money('USD', self::amount($enteredAmount));
            $principal = new Money('USD', $request->amount);
        }
        [$margin, $basis] = self::margin($c);

        return new self($context, $request,
            new Decimal($margin), $basis, $debit, $principal, null);
    }

    /** Historical planning arithmetic; never eligibility, authorized pricing or fee-collection proof. */
    public static function quoteRequestForAmount(string $customer, string $destinationCurrency,
        PayoutAmountMode $mode, string $enteredAmount, string $lockPeriod = '5_mins'): PayoutQuoteRequest
    {
        $amount = self::amount($enteredAmount);
        if ($mode === PayoutAmountMode::Source) {
            if (BigDecimal::of($amount->value)->isLessThanOrEqualTo(self::FEE_USD)) {
                throw new InvalidArgumentException('finalized_usd_send_must_exceed_fee');
            }
            $amount = new Decimal((string) BigDecimal::of($amount->value)->minus(self::FEE_USD));
        }

        return new PayoutQuoteRequest($customer, 'USD', $destinationCurrency, $mode, $amount->value, $lockPeriod);
    }

    public static function plannedDebitForPrincipal(Decimal $principal): Money
    {
        self::amount($principal->value);

        return new Money('USD', self::amount($principal->plus(new Decimal(self::FEE_USD))->value));
    }

    /** Accepts only the existing parsed quote, preserving its exact amounts/rate. */
    public function withQuote(PayoutRateQuote $quote, string $actor, DateTimeImmutable $asOf): self
    {
        $this->context->assertUsable($actor, $asOf);
        if (! hash_equals($this->context->fingerprint(), $quote->contextFingerprint)
            || $this->quoteRequest->json() !== $quote->request->json()) {
            throw new InvalidArgumentException('finalized_usd_quote_binding_mismatch');
        }
        if ($quote->expiresAt <= $asOf) {
            throw new InvalidArgumentException('payout_quote_expired');
        }
        // The recorded remittance schema allows two decimals. Reject rather than
        // rounding quote amounts or deriving a different amount from its rate.
        $principal = new Money('USD', self::amount($quote->sourceAmount->value));
        self::amount($quote->destinationAmount->value);
        $debit = self::plannedDebitForPrincipal($principal->amount);
        if ($this->plannedCustomerDebit !== null && ! $debit->amount->equals($this->plannedCustomerDebit->amount)) {
            throw new InvalidArgumentException('finalized_usd_quote_debit_mismatch');
        }

        return new self($this->context, $this->quoteRequest, $this->expectedFxMarginPercent,
            $this->marginBasis, $debit, $principal, $quote);
    }

    /** Internal planning snapshot; all provider mapping remains BLOCKED, even with a quote. */
    public function snapshot(string $actor, DateTimeImmutable $asOf): array
    {
        $this->context->assertUsable($actor, $asOf);
        if ($this->quote !== null) {
            $this->withQuote($this->quote, $actor, $asOf);
        }

        return ['version' => self::VERSION, 'basis' => 'historical_business_planning_assumptions',
            'pricing_authorization' => 'UNVERIFIED',
            'status' => 'PREPARATION_ONLY', 'input_mode' => $this->quoteRequest->mode->value,
            'entered_amount' => $this->quoteRequest->mode === PayoutAmountMode::Source
                ? $this->plannedCustomerDebit->snapshot()
                : (new Money($this->context->corridor->destinationCurrency, $this->quoteRequest->amount))->snapshot(),
            'planned_customer_wallet_debit' => $this->plannedCustomerDebit?->snapshot(),
            'proposed_conversion_principal' => $this->proposedConversionPrincipal?->snapshot(),
            'included_fee' => ['currency' => 'USD', 'amount' => self::FEE_USD],
            'expected_fx_margin_percent' => $this->expectedFxMarginPercent->value, 'margin_basis' => $this->marginBasis,
            'quoted_receive_amount' => $this->quote === null ? null
                : (new Money($this->context->corridor->destinationCurrency, $this->quote->destinationAmount))->snapshot(),
            'quoted_rate' => $this->quote?->rate->value, 'quote_id' => $this->quote?->id,
            'quote_response_sha256' => $this->quote?->responseSha256,
            'quote_expires_at' => $this->quote?->expiresAt->format('Y-m-d\TH:i:s.uP'),
            'receive_amount_status' => 'QUOTE_REFERENCE_ONLY', 'provider_fee_mapping' => 'BLOCKED',
            'provider_margin_applicability' => 'UNVERIFIED', 'provider_wallet_debit' => 'UNVERIFIED',
            'corridor_eligibility' => 'UNVERIFIED', 'financial_verification' => 'NOT_VERIFIED',
            'confirmation' => 'DENIED', 'reservation' => 'DENIED', 'admission' => 'DENIED', 'dispatch' => 'DENIED'];
    }

    private static function amount(string $value): Decimal
    {
        if (strlen($value) > 64) {
            throw new InvalidArgumentException('finalized_usd_amount_invalid');
        }
        $amount = new Decimal($value);
        if ($amount->isZero() || ! $amount->fitsScale(2)) {
            throw new InvalidArgumentException('finalized_usd_amount_invalid');
        }

        return $amount;
    }

    /** Contracted country/currency/rail metadata. Never reprice the provider quote. */
    private static function margin(CorridorIdentity $corridor): array
    {
        $row = DocumentaryFeeSchedule::contractedLocal($corridor->sourceCurrency,
            $corridor->destinationCountry, $corridor->destinationCurrency, $corridor->payoutMethod);

        return $row === null
            ? [self::DEFAULT_MARGIN_PERCENT, 'uncontracted_planning_reference_only']
            : [$row['margin_percent'], 'accepted_signed_local_schedule'];
    }
}
