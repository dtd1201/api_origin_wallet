<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use App\Domain\Nium\DynamicMultiCurrency\Financial\Decimal;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutAmountMode;
use App\Models\NiumDynamicIntent;
use App\Models\NiumDynamicPayoutOutbox;
use Throwable;

/** Evaluates authenticated transaction rows, never quote estimates or local fee placeholders. */
final class DynamicPayoutAccounting
{
    public function evaluate(NiumDynamicIntent $intent, NiumDynamicPayoutOutbox $outbox, array $batch): array
    {
        try {
            return $this->inspect($intent, $outbox, $batch);
        } catch (Throwable) {
            return ['state' => 'unavailable', 'reason' => 'dynamic_accounting_fields_unsupported'];
        }
    }

    private function inspect(NiumDynamicIntent $intent, NiumDynamicPayoutOutbox $outbox, array $batch): array
    {
        $records = $batch['records'];
        if (! is_array($records) || ! array_is_list($records)) {
            return ['state' => 'unavailable', 'reason' => 'dynamic_accounting_fields_unsupported'];
        }
        $c = $intent->confirmed_terms['context']['corridor'];
        $remittances = [];
        $reversedReference = false;
        $identities = [];
        foreach ($records as $row) {
            if (! is_array($row) || ($row['walletHashId'] ?? null) !== $intent->source_wallet
                || ($row['customerHashId'] ?? null) !== $intent->customer
                || ($row['clientHashId'] ?? null) !== $c['client']
                || ! is_string($row['authCode'] ?? null) || preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $row['authCode']) !== 1
                || isset($identities[$row['authCode']])) {
                return ['state' => 'unavailable', 'reason' => 'dynamic_accounting_scope_unverified'];
            }
            $identities[$row['authCode']] = true;
            if ($row['authCode'] === $outbox->provider_reference
                && in_array($row['transactionType'] ?? null, ['Fee_Reversal', 'Remittance_Reversal'], true)) {
                $reversedReference = true;
            }
            // externalId is null in the observed contract. A local operation key
            // alone must never discover a provider reference or settle a payment.
            if (in_array($row['transactionType'] ?? null, ['Remittance_Debit', 'Remittance_Debit_External'], true)
                && $outbox->provider_reference !== null && $row['authCode'] === $outbox->provider_reference) {
                $remittances[] = $row;
            }
        }
        if ($outbox->provider_reference === null) {
            return ['state' => 'pending', 'reason' => 'dynamic_remittance_reference_missing'];
        }
        if ($reversedReference) {
            return ['state' => 'exception', 'reason' => 'dynamic_provider_reversal_requires_review',
                'provider_reference' => $outbox->provider_reference];
        }
        if (count($remittances) === 0) {
            return ['state' => 'pending', 'reason' => 'dynamic_remittance_record_missing'];
        }
        if (count($remittances) !== 1) {
            return ['state' => 'unavailable', 'reason' => 'dynamic_remittance_identity_conflict'];
        }
        $remit = $remittances[0];
        $reference = $remit['authCode'];
        if (($outbox->provider_reference !== null && $reference !== $outbox->provider_reference)
            || (isset($remit['externalId']) && $remit['externalId'] !== $outbox->operation_key)
            || ($remit['labels']['beneficiaryId'] ?? null) !== $intent->confirmed_terms['context']['external_beneficiary_id']) {
            return ['state' => 'unavailable', 'reason' => 'dynamic_remittance_binding_unverified'];
        }
        $status = strtoupper(trim((string) ($remit['status'] ?? '')));
        if (in_array($status, ['RETURNED', 'REJECTED', 'DECLINED', 'FAILED', 'ERROR', 'BLOCKED', 'CANCELLED', 'REVERSAL'], true)
            || in_array($remit['settlementStatus'] ?? null, ['Released', 'Disputed', 'Waived'], true)) {
            $result = ['state' => 'exception', 'reason' => 'dynamic_provider_terminal_exception', 'provider_reference' => $reference];
            // Only the correlated remittance establishes its payout status. A failed
            // fee or released settlement alone does not establish a returned payout.
            $terminal = match ($status) {
                'RETURNED', 'REJECTED', 'FAILED', 'CANCELLED' => $status,
                'DECLINED' => 'REJECTED',
                'ERROR', 'BLOCKED' => 'FAILED',
                default => null,
            };
            if ($terminal !== null) {
                $result['provider_status'] = $terminal;
            }

            return $result;
        }
        $fees = [];
        foreach ($records as $row) {
            if (isset($row['labels']['originalAuthorizationCode'], $row['originalAuthorizationCode'])
                && $row['labels']['originalAuthorizationCode'] !== $row['originalAuthorizationCode']) {
                return ['state' => 'unavailable', 'reason' => 'dynamic_fee_linkage_conflict', 'provider_reference' => $reference];
            }
            $linked = ($row['labels']['originalAuthorizationCode'] ?? null) === $reference
                || ($row['originalAuthorizationCode'] ?? null) === $reference;
            if ($linked && in_array($row['transactionType'] ?? null, ['Fee_Reversal', 'Remittance_Reversal'], true)) {
                return ['state' => 'exception', 'reason' => 'dynamic_provider_reversal_requires_review', 'provider_reference' => $reference];
            }
            if (in_array($row['transactionType'] ?? null, ['Fee_Reversal', 'Remittance_Reversal'], true)
                && ! isset($row['labels']['originalAuthorizationCode']) && ! isset($row['originalAuthorizationCode'])) {
                return ['state' => 'unavailable', 'reason' => 'dynamic_reversal_linkage_unavailable', 'provider_reference' => $reference];
            }
            if (($row['transactionType'] ?? null) === 'Fee_Debit') {
                if (! isset($row['labels']['originalAuthorizationCode']) && ! isset($row['originalAuthorizationCode'])) {
                    return ['state' => 'unavailable', 'reason' => 'dynamic_fee_linkage_unavailable', 'provider_reference' => $reference];
                }
                if ($linked) {
                    $fees[] = $row;
                }
            }
        }
        if (($remit['settlementStatus'] ?? null) !== 'Settled') {
            $unsettled = ($remit['settlementStatus'] ?? null) === 'Unsettled';
            return ['state' => $unsettled ? 'pending' : 'unavailable',
                'reason' => $unsettled ? 'dynamic_provider_records_pending' : 'dynamic_provider_settlement_status_unsupported',
                'provider_reference' => $reference];
        }
        if (! in_array($status, ['APPROVED', 'PAID'], true)) {
            return ['state' => 'unavailable', 'reason' => 'dynamic_provider_status_unsupported', 'provider_reference' => $reference];
        }
        foreach ([$remit, ...$fees] as $row) {
            if (in_array(strtoupper(trim((string) ($row['status'] ?? ''))), ['RETURNED', 'REJECTED', 'DECLINED', 'FAILED', 'ERROR', 'BLOCKED', 'CANCELLED', 'REVERSAL'], true)
                || in_array($row['settlementStatus'] ?? null, ['Released', 'Disputed', 'Waived'], true)) {
                return ['state' => 'exception', 'reason' => 'dynamic_provider_terminal_exception', 'provider_reference' => $reference];
            }
            if (($row['settlementStatus'] ?? null) !== 'Settled') {
                $unsettled = ($row['settlementStatus'] ?? null) === 'Unsettled';
                return ['state' => $unsettled ? 'pending' : 'unavailable',
                    'reason' => $unsettled ? 'dynamic_provider_records_pending' : 'dynamic_provider_settlement_status_unsupported',
                    'provider_reference' => $reference];
            }
            if (! in_array(strtoupper(trim((string) ($row['status'] ?? ''))), ['APPROVED', 'PAID'], true)) {
                return ['state' => 'unavailable', 'reason' => 'dynamic_provider_status_unsupported', 'provider_reference' => $reference];
            }
            if (($row['debit'] ?? null) !== true || ! is_string($row['authCurrencyCode'] ?? null)
                || preg_match('/^[A-Z]{3}$/D', $row['authCurrencyCode']) !== 1
                || ! is_string($row['settlementAuthAmount'] ?? null)) {
                return ['state' => 'unavailable', 'reason' => 'dynamic_wallet_debit_evidence_missing', 'provider_reference' => $reference];
            }
        }
        if ($remit['authCurrencyCode'] !== 'USD') {
            return ['state' => 'unavailable', 'reason' => 'dynamic_remittance_debit_currency_unsupported', 'provider_reference' => $reference];
        }
        $principal = new Decimal($remit['settlementAuthAmount']);
        if ($principal->isZero()) {
            return ['state' => 'unavailable', 'reason' => 'dynamic_wallet_debit_evidence_missing', 'provider_reference' => $reference];
        }
        $fee = new Decimal('0');
        $debits = ['USD' => $principal];
        $allUsd = true;
        foreach ($fees as $row) {
            if (! is_string($row['labels']['feeName'] ?? null)) {
                return ['state' => 'unavailable', 'reason' => 'dynamic_fee_identity_missing', 'provider_reference' => $reference];
            }
            $amount = new Decimal($row['settlementAuthAmount']);
            $currency = $row['authCurrencyCode'];
            $debits[$currency] = ($debits[$currency] ?? new Decimal('0'))->plus($amount);
            if ($currency === 'USD') $fee = $fee->plus($amount);
            else $allUsd = false;
        }
        // Sum per-transaction settled wallet debits. effectiveAuthAmount may already include fees.
        $total = $principal->plus($fee);
        $t = $intent->confirmed_terms['financial_terms'];
        if ($intent->source_currency !== 'USD'
            || ($t['principal']['currency'] ?? null) !== 'USD'
            || ($t['transaction_fee']['currency'] ?? null) !== 'USD'
            || ($t['wallet_debit']['currency'] ?? null) !== 'USD'
            || ($t['estimated_recipient']['currency'] ?? null) !== $intent->destination_currency
            || ! in_array($intent->amount_mode, [PayoutAmountMode::Source->value, PayoutAmountMode::Destination->value], true)) {
            return ['state' => 'unavailable', 'reason' => 'dynamic_customer_accounting_terms_unverified', 'provider_reference' => $reference];
        }
        if (($remit['transactionCurrencyCode'] ?? null) !== $intent->destination_currency
            || ! is_string($remit['settlementTransactionAmount'] ?? null)) {
            return ['state' => 'unavailable', 'reason' => 'dynamic_destination_amount_evidence_missing', 'provider_reference' => $reference];
        }
        $destination = new Decimal($remit['settlementTransactionAmount']);
        if ($destination->isZero()) {
            return ['state' => 'unavailable', 'reason' => 'dynamic_destination_amount_evidence_missing', 'provider_reference' => $reference];
        }
        if (! (new Decimal($t['wallet_debit']['amount']))->equals(new Decimal($intent->total_debit))
            || ! (new Decimal($t['principal']['amount']))->plus(new Decimal($t['transaction_fee']['amount']))
                ->equals(new Decimal($intent->total_debit))) {
            return ['state' => 'unavailable', 'reason' => 'dynamic_customer_accounting_terms_unverified', 'provider_reference' => $reference];
        }
        if ($fees === []) {
            // CUG observations and agreed customer pricing do not establish this
            // account/corridor/agreement's Production fee collection model. Retain
            // the authenticated remittance debit, without classifying it as principal
            // or inferring either a zero fee or the complete wallet debit.
            return ['state' => 'pending', 'reason' => 'dynamic_fee_records_missing', 'provider_reference' => $reference,
                'receipt' => [
                    'observed_remittance_debit_usd' => $principal->value,
                    'principal_usd' => null, 'fee_usd' => null, 'wallet_debit_usd' => null,
                    'provider_wallet_debits' => null,
                    'destination_amount' => $destination->value, 'destination_currency' => $intent->destination_currency,
                    'estimated_destination_amount' => $t['estimated_recipient']['amount'],
                    'intended_destination_amount' => $t['estimated_recipient']['amount'],
                    'customer_authorized_debit_usd' => (new Decimal($intent->total_debit))->value,
                    'customer_principal_usd' => $t['principal']['amount'],
                    'customer_fee_allocation_usd' => $t['transaction_fee']['amount'],
                    'provider_fee_records' => [], 'fee_evidence' => 'MISSING_FEE_RECORDS',
                    'discrepancy' => null, 'provider_minus_customer_usd' => null,
                    'transaction_ids' => [$reference],
                    'response_sha256' => $batch['response_sha256'], 'observed_at' => $batch['observed_at'],
                ]];
        }
        $matched = $allUsd && $total->equals(new Decimal($intent->total_debit))
            && $principal->equals(new Decimal($t['principal']['amount']))
            && $fee->equals(new Decimal($t['transaction_fee']['amount']));
        $matched = $matched && array_filter($fees, static fn (array $row): bool => $row['labels']['feeName'] !== 'REMIT_BANK_FEE') === [];
        // Both modes consent to the exact quoted recipient amount. This detects
        // settlement differences; it does not guarantee provider delivery or reprice it.
        $matched = $matched && $destination->equals(new Decimal($t['estimated_recipient']['amount']));
        if (isset($remit['labels']['netExchangeRate'])) {
            if (! is_string($remit['labels']['netExchangeRate']) || ! is_string($t['fx_rate'] ?? null)) {
                return ['state' => 'unavailable', 'reason' => 'dynamic_provider_fx_observation_unverified', 'provider_reference' => $reference];
            }
            $matched = $matched && (new Decimal($remit['labels']['netExchangeRate']))->equals(new Decimal($t['fx_rate']));
        }

        $result = ['state' => $matched ? 'matched' : 'mismatched',
            'reason' => $matched ? 'dynamic_provider_accounting_matched' : 'dynamic_provider_accounting_mismatch',
            'provider_reference' => $reference, 'receipt' => [
                'principal_usd' => $principal->value, 'fee_usd' => $allUsd ? $fee->value : null,
                'wallet_debit_usd' => $allUsd ? $total->value : null,
                'provider_wallet_debits' => array_map(static fn (Decimal $amount): string => $amount->value, $debits),
                'destination_amount' => $destination->value, 'destination_currency' => $intent->destination_currency,
                // Retain the historical key and distinguish consent from actual credit.
                'estimated_destination_amount' => $t['estimated_recipient']['amount'],
                'intended_destination_amount' => $t['estimated_recipient']['amount'],
                'destination_minus_estimate' => (string) \Brick\Math\BigDecimal::of($destination->value)
                    ->minus($t['estimated_recipient']['amount'])->stripTrailingZeros(),
                'customer_authorized_debit_usd' => (new Decimal($intent->total_debit))->value,
                'customer_principal_usd' => $t['principal']['amount'], 'customer_fee_allocation_usd' => $t['transaction_fee']['amount'],
                'provider_fee_records' => array_map(static fn (array $row): array => [
                    'id' => $row['authCode'], 'fee_name' => $row['labels']['feeName'],
                    'amount' => $row['settlementAuthAmount'], 'currency' => $row['authCurrencyCode']], $fees),
                'fee_evidence' => 'SETTLED_FEE_RECORDS',
                'fx_observations' => array_intersect_key((array) ($remit['labels'] ?? []),
                    array_flip(['netExchangeRate', 'markupRate'])),
                'discrepancy' => ! $matched,
                'provider_minus_customer_usd' => $allUsd
                    ? (string) \Brick\Math\BigDecimal::of($total->value)->minus($intent->total_debit)->stripTrailingZeros() : null,
                'transaction_ids' => array_column([$remit, ...$fees], 'authCode'),
                'response_sha256' => $batch['response_sha256'], 'observed_at' => $batch['observed_at'],
            ]];
        if ($matched && $status === 'PAID') {
            $result['provider_status'] = 'PAID';
        }

        return $result;
    }
}
