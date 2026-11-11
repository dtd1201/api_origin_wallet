<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use App\Models\NiumDynamicIntent;
use App\Models\NiumDynamicPayoutOutbox;
use App\Models\NiumDynamicReservation;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use RuntimeException;

/** Allowlisted customer projection. A matched wallet debit is not beneficiary-bank completion. */
final class DynamicPayoutCustomerStatus
{
    public function project(NiumDynamicIntent $intent, NiumDynamicPayoutOutbox $outbox,
        NiumDynamicReservation $reservation): array
    {
        if ($outbox->intent_id !== $intent->id || $reservation->intent_id !== $intent->id
            || $outbox->reservation_id !== $reservation->id) {
            throw new RuntimeException('dynamic_status_binding_unverified');
        }
        $returned = $outbox->provider_status === 'RETURNED';
        $terminal = in_array($outbox->provider_status, ['REJECTED', 'FAILED', 'CANCELLED'], true);
        $reconciled = ! $returned && ! $terminal && $outbox->state === 'matched'
            && $outbox->financial_state === 'matched' && $reservation->state === 'consumed'
            && $outbox->financial_risk_state === 'OBSERVED_PROVIDER_ACCOUNTING_MATCHED'
            && $reservation->debit_entry_id !== null;
        $status = match (true) {
            $returned => 'returned',
            $terminal => 'failed',
            $outbox->state === 'exception' => 'requires_attention',
            $outbox->state === 'cancelled' => 'cancelled',
            $reconciled && $outbox->provider_status === 'PAID' => 'paid',
            $reconciled => 'reconciled',
            $outbox->state === 'ambiguous' => 'pending_provider_confirmation',
            $outbox->state === 'held' => 'queued',
            default => 'processing',
        };
        $terms = $intent->confirmed_terms;

        return [
            'id' => $intent->id,
            'beneficiary' => ['id' => $intent->beneficiary_id,
                'country_code' => $terms['context']['corridor']['destinationCountry']],
            'source_currency' => $intent->source_currency, 'destination_currency' => $intent->destination_currency,
            'amount_mode' => $intent->amount_mode,
            'total_usd_debit' => $intent->total_debit,
            'principal' => $this->money($terms['financial_terms']['principal']),
            'included_fee' => $this->money($terms['financial_terms']['transaction_fee']),
            'destination_amount' => $this->money($terms['financial_terms']['estimated_recipient']),
            'status' => $status, 'financial_reconciled' => $reconciled,
            'message' => match ($status) {
                'returned' => 'The provider reported a return. Support must review the returned funds.',
                'failed' => 'The provider reported that this payment failed or was cancelled. Support must review the funds. Do not submit it again.',
                'requires_attention' => 'This transfer requires support review. Do not submit it again.',
                'pending_provider_confirmation' => 'The provider outcome is pending. Do not submit it again.',
                'paid' => 'The provider reported payment and wallet accounting reconciled.',
                'reconciled' => 'Provider wallet accounting reconciled. Beneficiary-bank delivery is not confirmed here.',
                'cancelled' => 'This transfer was cancelled before submission.',
                default => 'The transfer is being processed.',
            },
            'created_at' => $this->utc($intent->created_at),
            'consented_at' => $this->utc($intent->consented_at),
            'quote_expires_at' => $this->utc($intent->quote_expires_at),
            'submission_started_at' => $this->utc($outbox->submission_started_at),
            'reconciled_at' => $this->utc($outbox->reconciled_at),
        ];
    }

    private function utc(DateTimeInterface|string|null $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    private function money(array $value): array
    {
        return ['currency' => $value['currency'], 'amount' => $value['amount']];
    }
}
