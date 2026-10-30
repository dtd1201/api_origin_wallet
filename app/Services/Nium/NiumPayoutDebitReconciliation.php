<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\Balance;
use App\Models\LedgerEntry;
use App\Models\NiumPayoutPilotAuthorization;
use App\Models\Transfer;
use App\Models\UserProviderAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Authenticated wallet transactions, never remittance status or the display transaction projection. */
final class NiumPayoutDebitReconciliation
{
    private const ENDPOINT = '/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/transactions';

    public static function applies(Transfer $transfer): bool
    {
        return $transfer->source_currency === 'USD' && $transfer->target_currency === 'SGD'
            && strtolower((string) $transfer->provider()->value('code')) === 'nium';
    }

    /** Read only, outside locks. Return capture identities, never caller-supplied accounting amounts. */
    public function prepare(Transfer $transfer): array
    {
        try {
            if (DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
                throw new RuntimeException('financial_read_requires_committed_state');
            }
            $context = $this->context($transfer);
            if (config('services.nium.wallet_transactions_endpoint') !== self::ENDPOINT) {
                throw new RuntimeException('wallet_transaction_contract_unsupported');
            }
            $nium = app(NiumService::class);
            $path = $nium->path(self::ENDPOINT, $context);
            $requests = [];
            // Read the wallet, not a remittance-only filter that can omit separate Fee_Debit rows.
            for ($page = 0; $page < 20; $page++) {
                $uuid = Str::uuid()->toString();
                $response = $nium->getWithRequestId($path, ['page' => $page, 'size' => 100, 'order' => 'ASC'],
                    $transfer->user, $uuid, 'payout_wallet_activity', $transfer->provider_operation_key, $transfer->id);
                if (! $response->successful()) {
                    throw new RuntimeException('wallet_transaction_read_failed');
                }
                $data = $this->decode($response->body());
                [$pages] = $this->pagination($data);
                $requests[] = $uuid;
                if ($page + 1 >= $pages) {
                    return ['requests' => $requests, 'context' => $context,
                        'operation_key' => $transfer->provider_operation_key, 'system_reference' => $transfer->external_transfer_id];
                }
            }
            throw new RuntimeException('wallet_transaction_scan_incomplete');
        } catch (Throwable) {
            return ['error' => 'authenticated_wallet_activity_unavailable'];
        }
    }

    /** Called under the existing transfer lock; accounting and evidence acceptance commit together. */
    public function apply(Transfer $transfer, array $capture): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Financial reconciliation requires the transfer lock transaction.');
        }
        try {
            $context = $this->context($transfer);
            if (isset($capture['error']) || ($capture['operation_key'] ?? null) !== $transfer->provider_operation_key
                || ($capture['system_reference'] ?? null) !== $transfer->external_transfer_id
                || ! NiumPayoutFxLockContract::sameBinding($capture['context'] ?? null, $context)) {
                throw new RuntimeException('authenticated_wallet_activity_unavailable_or_binding_changed');
            }
            $rows = $this->capturedRows($transfer, $capture, $context);
            [$principal, $fees] = $this->project($rows, $transfer, $context);
            if ($transfer->status !== 'completed') {
                throw new RuntimeException('payout_outcome_requires_financial_review');
            }
            // A savepoint ensures a failed/conflicting charge never leaves a partial application.
            DB::transaction(fn () => $this->post($transfer, $principal, $fees, $context));
        } catch (Throwable $exception) {
            $this->state($transfer, ['state' => 'awaiting_evidence',
                'reason' => $exception instanceof RuntimeException ? $exception->getMessage() : 'financial_evidence_unverifiable']);
        }
    }

    private function context(Transfer $transfer): array
    {
        $approved = $transfer->raw_data['sgd_local_approval']['provider_context'] ?? null;
        $execution = $transfer->raw_data['nium_execution'] ?? [];
        $accounts = UserProviderAccount::query()->where('user_id', $transfer->user_id)->where('provider_id', $transfer->provider_id)->get();
        if (! self::applies($transfer) || ! is_array($approved) || $accounts->count() !== 1
            || ! filled($transfer->external_transfer_id) || ! filled($transfer->provider_operation_key)
            || ($execution['operation_key'] ?? null) !== $transfer->provider_operation_key
            || ! isset($execution['dispatch_started_at'])) {
            throw new RuntimeException('original_dispatched_wallet_binding_missing');
        }
        $current = ['client' => app(NiumService::class)->clientId(), 'customer' => $accounts->sole()->external_customer_id,
            'wallet' => $accounts->sole()->external_account_id, 'base_url' => config('services.nium.base_url')];
        if (! NiumPayoutFxLockContract::sameBinding($approved, $current)) {
            throw new RuntimeException('original_dispatched_wallet_binding_changed');
        }

        return $approved;
    }

    private function capturedRows(Transfer $transfer, array $capture, array $context): array
    {
        $requests = $capture['requests'] ?? [];
        if (! is_array($requests) || count($requests) < 1 || count($requests) > 20 || count(array_unique($requests)) !== count($requests)) {
            throw new RuntimeException('wallet_transaction_scan_incomplete');
        }
        $expectedUrl = rtrim($context['base_url'], '/').str_replace(
            ['{clientHashId}', '{customerHashId}', '{walletHashId}'], '[REDACTED]', self::ENDPOINT);
        $rows = [];
        $total = null;
        foreach ($requests as $page => $uuid) {
            $logs = ApiRequestLog::query()->with('niumExchangeEvidence')->where('provider_id', $transfer->provider_id)
                ->where('user_id', $transfer->user_id)->where('related_transfer_id', $transfer->id)
                ->where('operation', 'payout_wallet_activity')->where('external_reference', $transfer->provider_operation_key)
                ->where('request_headers->x-request-id', $uuid)->get();
            if ($logs->count() !== 1) {
                throw new RuntimeException('wallet_transaction_capture_missing_or_ambiguous');
            }
            $log = $logs->sole();
            $evidence = $log->niumExchangeEvidence;
            $raw = $evidence?->raw_response_body;
            if ($log->client_hash_id !== $context['client'] || $log->request_method !== 'GET' || $log->request_url !== $expectedUrl
                || ! $log->is_success || $log->response_status !== 200 || $evidence === null || $evidence->capture_version !== 1
                || ! $evidence->response_received || $evidence->request_method !== 'GET' || $evidence->request_url !== $expectedUrl
                || ($evidence->request_headers['x-request-id'] ?? null) !== [$uuid]
                || ($log->request_body['wallet_activity_query_sha256'] ?? null) !== hash('sha256', json_encode(['page' => $page, 'size' => 100, 'order' => 'ASC'], JSON_THROW_ON_ERROR))
                || ! is_string($raw) || ! hash_equals((string) $evidence->response_body_sha256, hash('sha256', $raw))) {
                throw new RuntimeException('wallet_transaction_capture_unverifiable');
            }
            $data = $this->decode($raw);
            [$pages, $elements] = $this->pagination($data);
            if (count($requests) !== max(1, $pages) || ($total !== null && $total !== $elements)) {
                throw new RuntimeException('wallet_transaction_scan_incomplete');
            }
            $total = $elements;
            foreach ($data['content'] as $row) {
                if (! is_array($row)) {
                    throw new RuntimeException('wallet_transaction_schema_unverified');
                }
                $rows[] = ['row' => $row, 'api_request_log_id' => $log->id, 'response_sha256' => $evidence->response_body_sha256];
            }
        }
        if (count($rows) !== $total) {
            throw new RuntimeException('wallet_transaction_scan_incomplete');
        }

        return $rows;
    }

    private function project(array $rows, Transfer $transfer, array $context): array
    {
        $principal = [];
        $fees = [];
        $seen = [];
        $linkedFees = LedgerEntry::query()->where('source_type', 'transfer')->where('source_id', (string) $transfer->id)
            ->where('entry_type', 'adjustment')->where('reference', 'like', 'nium:wallet-debit:%')
            ->where('provider_id', $transfer->provider_id)->where('user_id', $transfer->user_id)->where('currency', $transfer->source_currency)
            ->where('raw_data->operation_key', $transfer->provider_operation_key)->get()->pluck('raw_data.financial_evidence.auth_code')->filter()->all();
        foreach ($rows as $evidence) {
            $row = $evidence['row'];
            if (($row['transactionType'] ?? null) === 'Fee_Debit'
                && ($row['labels']['originalAuthorizationCode'] ?? null) === $transfer->external_transfer_id
                && is_string($row['authCode'] ?? null)) {
                $linkedFees[] = $row['authCode'];
            }
        }
        foreach ($rows as $evidence) {
            $row = $evidence['row'];
            $reference = $row['authCode'] ?? null;
            $original = $row['labels']['originalAuthorizationCode'] ?? null;
            if ($reference !== $transfer->external_transfer_id && $original !== $transfer->external_transfer_id
                && ! in_array($reference, $linkedFees, true) && ! in_array($original, $linkedFees, true)) {
                continue;
            }
            if (! is_string($reference) || trim($reference) === '' || isset($seen[$reference])
                || ($row['clientHashId'] ?? null) !== $context['client'] || ($row['customerHashId'] ?? null) !== $context['customer']
                || ($row['walletHashId'] ?? null) !== $context['wallet'] || ($row['authCurrencyCode'] ?? null) !== $transfer->source_currency
                || ($row['debit'] ?? null) !== true || ($row['settlementStatus'] ?? null) !== 'Settled'
                || ! in_array($row['status'] ?? null, ['Approved', 'APPROVED', 'Settled', 'SETTLED'], true)) {
                throw new RuntimeException('wallet_transaction_binding_or_settlement_unverified');
            }
            $seen[$reference] = true;
            $amount = $this->money($row['settlementAuthAmount'] ?? null);
            if (bccomp($amount, $this->money($row['authAmount'] ?? null), 8) !== 0) {
                throw new RuntimeException('wallet_transaction_amount_changed_requires_review');
            }
            $event = ['auth_code' => $reference, 'amount' => $amount,
                'transaction_type' => $row['transactionType'] ?? null,
                'api_request_log_id' => $evidence['api_request_log_id'], 'response_sha256' => $evidence['response_sha256']];
            if ($reference === $transfer->external_transfer_id && in_array($event['transaction_type'], ['Remittance_Debit', 'Remittance_Debit_External'], true)) {
                if (bccomp($amount, (string) $transfer->source_amount, 8) !== 0) {
                    throw new RuntimeException('principal_debit_differs_from_authorized_source_amount');
                }
                $principal[] = $event;
            } elseif ($original === $transfer->external_transfer_id && $event['transaction_type'] === 'Fee_Debit') {
                $fees[] = $event;
            } else {
                // Reversal/waiver/return evidence needs controlled review; never infer a refund from status.
                throw new RuntimeException('wallet_transaction_reversal_or_type_requires_review');
            }
        }
        if (count($principal) !== 1) {
            throw new RuntimeException('settled_principal_debit_missing_or_ambiguous');
        }

        return [$principal[0], $fees];
    }

    private function post(Transfer $transfer, array $principal, array $fees, array $context): void
    {
        if (! config('wallet.ledger.enabled', true)) {
            throw new RuntimeException('reservation_ledger_disabled');
        }
        $hold = LedgerEntry::query()->where('reference', "transfer:{$transfer->id}:hold")->first();
        if ($hold === null || ($hold->raw_data['operation_key'] ?? null) !== $transfer->provider_operation_key
            || $hold->user_id !== $transfer->user_id || $hold->provider_id !== $transfer->provider_id || $hold->currency !== $transfer->source_currency
            || $hold->entry_type !== 'hold' || $hold->status !== 'posted' || $hold->source_type !== 'transfer' || $hold->source_id !== (string) $transfer->id
            || bccomp($hold->amount, '-'.$principal['amount'], 8) !== 0
            || LedgerEntry::query()->where('reference', "transfer:{$transfer->id}:release")->exists()) {
            throw new RuntimeException('original_owned_reservation_unverified');
        }
        $balance = Balance::query()->whereKey($hold->balance_id)->where('user_id', $transfer->user_id)
            ->where('provider_id', $transfer->provider_id)->where('external_account_id', $context['wallet'])
            ->where('currency', $transfer->source_currency)->lockForUpdate()->firstOrFail();
        $debit = LedgerEntry::query()->where('reference', "transfer:{$transfer->id}:debit")->first();
        if ($debit !== null && (! $this->ownsEntry($debit, $transfer, $balance) || $debit->entry_type !== 'debit'
            || bccomp($debit->amount, '-'.$principal['amount'], 8) !== 0)) {
            throw new RuntimeException('existing_principal_debit_conflicts');
        }
        $principalReference = $this->eventReference($context, $principal);
        $marker = LedgerEntry::query()->where('reference', $principalReference)->first();
        if ($marker !== null && (! $this->ownsEntry($marker, $transfer, $balance) || $marker->entry_type !== 'reconciliation'
            || bccomp($marker->amount, '0', 8) !== 0)) {
            throw new RuntimeException('provider_debit_already_owned_by_another_transfer');
        }
        if ($debit === null) {
            if (bccomp($balance->reserved_balance, $principal['amount'], 8) < 0) {
                throw new RuntimeException('original_reservation_insufficient');
            }
            $balance->reserved_balance = bcsub($balance->reserved_balance, $principal['amount'], 8);
            $balance->ledger_balance = bcsub($balance->ledger_balance, $principal['amount'], 8);
            $this->entry($transfer, $balance, "transfer:{$transfer->id}:debit", 'debit', '-'.$principal['amount'], $principal);
        }
        // A zero-value append-only marker binds even an already settled legacy principal to this provider event.
        if ($marker === null) {
            $this->entry($transfer, $balance, $principalReference, 'reconciliation', '0', $principal);
        }
        foreach ($fees as $fee) {
            $reference = $this->eventReference($context, $fee);
            $existing = LedgerEntry::query()->where('reference', $reference)->first();
            if ($existing !== null) {
                if (! $this->ownsEntry($existing, $transfer, $balance) || $existing->entry_type !== 'adjustment'
                    || bccomp($existing->amount, '-'.$fee['amount'], 8) !== 0) {
                    throw new RuntimeException('provider_fee_conflicts_with_posted_adjustment');
                }

                continue;
            }
            // Record the observed liability even if unexpected charges make available funds negative.
            // This is post-dispatch accounting, never a pre-dispatch funding safeguard.
            $uncovered = app(NiumPayoutPilotPolicy::class)->applyFee($transfer, $balance, $fee['amount']);
            $balance->available_balance = bcsub($balance->available_balance, $uncovered, 8);
            $balance->ledger_balance = bcsub($balance->ledger_balance, $fee['amount'], 8);
            $pilotId = $transfer->raw_data['sgd_local_approval']['financial_terms']['authorization_id'] ?? null;
            $allocation = $pilotId === null ? [] : ['authorization_id' => $pilotId,
                'reserved_amount_used' => bcsub($fee['amount'], $uncovered, 8), 'uncovered_amount' => $uncovered];
            $this->entry($transfer, $balance, $reference, 'adjustment', '-'.$fee['amount'], $fee, $allocation);
        }
        $balance->as_of = now();
        $balance->save();
        $charges = LedgerEntry::query()->where('source_type', 'transfer')->where('source_id', (string) $transfer->id)
            ->where('entry_type', 'adjustment')->where('reference', 'like', 'nium:wallet-debit:%')
            ->where('provider_id', $transfer->provider_id)->where('user_id', $transfer->user_id)->where('currency', $transfer->source_currency)
            ->where('raw_data->operation_key', $transfer->provider_operation_key)
            ->get()->reduce(fn (string $sum, LedgerEntry $entry) => bcsub($sum, $entry->amount, 8), '0');
        $this->state($transfer, ['state' => bccomp($charges, '0', 8) === 0 ? 'principal_reconciled' : 'unexpected_charges_reconciled',
            'reason' => null,
            'currency' => $transfer->source_currency, 'principal_debit' => $principal['amount'], 'observed_charge_debit' => $charges,
            'observed_total_debit' => bcadd($principal['amount'], $charges, 8),
            'charge_completeness' => 'not_proven_by_absence_of_fee_rows']);
        $pilotId = $transfer->raw_data['sgd_local_approval']['financial_terms']['authorization_id'] ?? null;
        if ($pilotId !== null) {
            $pilot = NiumPayoutPilotAuthorization::query()->findOrFail($pilotId);
            $this->state($transfer, ['pilot_charge_reserve_remaining' => $pilot->reserve_remaining,
                'pilot_charge_ceiling_breached' => $pilot->charge_ceiling === null ? null : bccomp($charges, $pilot->charge_ceiling, 8) > 0,
                'pilot_charge_exposure' => $pilot->policy === NiumPayoutPilotPolicy::UNCAPPED_POLICY ? 'uncapped' : 'funded_reserve',
                'pilot_additional_charges_observed' => bccomp($charges, '0', 8) > 0]);
        }
    }

    private function entry(Transfer $transfer, Balance $balance, string $reference, string $type, string $amount, array $evidence, array $allocation = []): void
    {
        LedgerEntry::query()->create(['balance_id' => $balance->id, 'user_id' => $transfer->user_id, 'provider_id' => $transfer->provider_id,
            'reference' => $reference, 'entry_type' => $type, 'status' => 'posted', 'currency' => $transfer->source_currency,
            'amount' => $amount, 'balance_after' => $balance->ledger_balance, 'source_type' => 'transfer', 'source_id' => (string) $transfer->id,
            'description' => 'NIUM authenticated wallet debit reconciliation.', 'posted_at' => now(),
            'raw_data' => ['operation_key' => $transfer->provider_operation_key, 'external_transfer_id' => $transfer->external_transfer_id,
                'provider_context' => $transfer->raw_data['sgd_local_approval']['provider_context'], 'financial_evidence' => $evidence,
                ...($allocation === [] ? [] : ['pilot_reserve_allocation' => $allocation])]]);
    }

    private function ownsEntry(LedgerEntry $entry, Transfer $transfer, Balance $balance): bool
    {
        return $entry->balance_id === $balance->id && $entry->user_id === $transfer->user_id && $entry->provider_id === $transfer->provider_id
            && $entry->currency === $transfer->source_currency && $entry->status === 'posted' && $entry->source_type === 'transfer'
            && $entry->source_id === (string) $transfer->id && ($entry->raw_data['operation_key'] ?? null) === $transfer->provider_operation_key;
    }

    private function eventReference(array $context, array $event): string
    {
        return 'nium:wallet-debit:'.hash('sha256', json_encode([
            $context['base_url'], $context['client'], $context['customer'], $context['wallet'], $event['auth_code'],
        ], JSON_THROW_ON_ERROR));
    }

    private function state(Transfer $transfer, array $state): void
    {
        $raw = $transfer->raw_data;
        $previous = $raw['nium_actual_debit'] ?? [];
        $raw['nium_actual_debit'] = array_merge($previous, $state, ['checked_at' => now()->toISOString()]);
        $transfer->update(['raw_data' => $raw]);
    }

    private function money(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,8})?$/D', $value) || bccomp($value, '0', 8) <= 0) {
            throw new RuntimeException('wallet_transaction_amount_unverifiable');
        }

        return bcadd($value, '0', 8);
    }

    private function pagination(array $data): array
    {
        if (! is_array($data['content'] ?? null) || ! array_is_list($data['content']) || count($data['content']) > 100
            || ! ctype_digit((string) ($data['totalPages'] ?? '')) || ! ctype_digit((string) ($data['totalElements'] ?? ''))
            || (int) $data['totalPages'] > 20 || (int) $data['totalElements'] > 2000) {
            throw new RuntimeException('wallet_transaction_scan_incomplete');
        }

        return [(int) $data['totalPages'], (int) $data['totalElements']];
    }

    private function decode(string $raw): array
    {
        // Preserve provider decimal tokens before json_decode can round them through binary floats.
        $quoted = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"(*SKIP)(*F)|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/',
            static fn (array $match): string => '"'.$match[0].'"', $raw);
        $data = json_decode($quoted, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException('wallet_transaction_schema_unverified');
        }

        return $data;
    }
}
