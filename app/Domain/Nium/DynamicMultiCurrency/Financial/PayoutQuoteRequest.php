<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Financial;

use InvalidArgumentException;

/** Quote reference amounts are NOT remittance instructions or guaranteed credits. */
final readonly class PayoutQuoteRequest
{
    public Decimal $amount;

    public function __construct(
        public string $customer,
        public string $sourceCurrency,
        public string $destinationCurrency,
        public PayoutAmountMode $mode,
        string $amount,
        public string $lockPeriod = '5_mins',
    ) {
        if ($customer === '' || trim($customer) !== $customer || preg_match('/[\x00-\x1F\x7F]/', $customer)
            || preg_match('/^[A-Z]{3}$/D', $sourceCurrency) !== 1
            || preg_match('/^[A-Z]{3}$/D', $destinationCurrency) !== 1
            || $sourceCurrency === $destinationCurrency
            || ! in_array($lockPeriod, ['5_mins', '15_mins', '1_hour', '4_hours', '8_hours', '24_hours'], true)
            || strlen($amount) > 64) {
            throw new InvalidArgumentException('payout_quote_request_invalid');
        }
        $this->amount = new Decimal($amount);
        if ($this->amount->isZero()) {
            throw new InvalidArgumentException('payout_quote_amount_invalid');
        }
    }

    /** Exact JSON number tokens, without a float round trip. Never pass through json_decode/re-encode. */
    public function json(): string
    {
        $body = json_encode([
            'quoteType' => 'payout', 'sourceCurrencyCode' => $this->sourceCurrency,
            'destinationCurrencyCode' => $this->destinationCurrency, 'customerHashId' => $this->customer,
            'conversionSchedule' => 'immediate', 'executionType' => 'at_conversion_time',
            'quoteIntent' => 'EXECUTABLE', 'lockPeriod' => $this->lockPeriod,
        ], JSON_THROW_ON_ERROR);

        return substr($body, 0, -1).',"'.$this->mode->quoteField().'":'.$this->amount->value.'}';
    }
}
