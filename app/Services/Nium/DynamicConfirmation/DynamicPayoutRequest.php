<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use App\Domain\Nium\DynamicMultiCurrency\Financial\Decimal;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutAmountMode;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutInstruction;
use App\Models\NiumDynamicIntent;
use RuntimeException;

final class DynamicPayoutRequest
{
    public static function json(NiumDynamicIntent $intent, string $operationKey): string
    {
        $s = $intent->confirmed_terms;
        $c = $s['context']['corridor'];
        $instruction = $s['remittance_instruction'] ?? [];
        $instruction = new PayoutInstruction($instruction['purposeCode'] ?? '', $instruction['sourceOfFunds'] ?? '',
            $instruction['customerComments'] ?? '');
        if ($intent->source_currency !== 'USD' || $intent->destination_currency === 'USD'
            || $intent->destination_currency === 'SGD' || $c['payoutMethod'] !== 'LOCAL'
            || ! isset($s['finalized_usd_preparation']) || $s['quote_id'] !== $intent->quote_id) {
            throw new RuntimeException('dynamic_execution_corridor_unsupported');
        }
        $mode = PayoutAmountMode::from($intent->amount_mode);
        $amount = new Decimal($mode === PayoutAmountMode::Source
            ? $s['financial_terms']['principal']['amount'] : $s['financial_terms']['estimated_recipient']['amount']);
        $currency = $mode === PayoutAmountMode::Source ? $intent->source_currency : $intent->destination_currency;
        $scale = $s['payout_contract']['currencyScales'][$currency] ?? null;
        if ($amount->isZero() || ! is_int($scale) || $scale < 0 || ! $amount->fitsScale($scale)) {
            throw new RuntimeException('dynamic_remittance_amount_unrepresentable');
        }
        $payout = json_encode(['sourceCurrency' => 'USD', 'quoteId' => $intent->quote_id,
            'tradeOrderID' => $operationKey], JSON_THROW_ON_ERROR);
        $payout = substr($payout, 0, -1).',"'.$mode->quoteField().'":'.$amount->value.'}';
        $body = json_encode(['beneficiary' => ['id' => $s['context']['external_beneficiary_id']],
            ...$instruction->snapshot(), 'externalId' => $operationKey], JSON_THROW_ON_ERROR);

        $json = substr($body, 0, -1).',"payout":'.$payout.'}';
        if (isset($s['validated_remittance_sha256'])
            && ! hash_equals($s['validated_remittance_sha256'], hash('sha256', $json))) {
            throw new RuntimeException('dynamic_validated_remittance_changed');
        }
        return $json;
    }
}
