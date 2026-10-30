<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\FxQuote;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Explicit recovery from an already captured exchange; never queries NIUM or repeats I/O. */
final class NiumSgdLocalOutcomeRecovery
{
    public function recover(Transfer $transfer): Transfer
    {
        return DB::transaction(function () use ($transfer): Transfer {
            $locked = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
            app(NiumSgdLocalCugScope::class)->assertTransfer($locked);
            app(NiumSgdLocalPayoutWorkflow::class)->assertIntent($locked);
            $workflow = $locked->raw_data['sgd_local_workflow'];
            $context = app(NiumPayoutFxLockService::class)->context($locked->provider, $locked->user);
            $fx = $locked->status === 'draft' && $locked->provider_operation_key === null
                && in_array($workflow['state'] ?? null, ['locking', 'lock_unknown'], true);
            $payment = in_array($locked->status, ['submitting', 'submission_unknown'], true)
                && $locked->provider_operation_key !== null && ! filled($locked->external_transfer_id)
                && isset($locked->raw_data['nium_execution']['dispatch_started_at']);
            if (! $fx && ! $payment) {
                throw new RuntimeException('This operation does not require captured-outcome recovery.');
            }
            $uuid = $fx ? ($workflow['lock_request_id'] ?? null) : substr($locked->provider_operation_key, 5);
            if (! is_string($uuid) || ! Str::isUuid($uuid)) {
                throw new RuntimeException('A durable captured request UUID is required.');
            }
            $operation = $fx ? 'create_payout_fx_lock' : 'transfer_money';
            $logs = ApiRequestLog::query()->with('niumExchangeEvidence')->where('provider_id', $locked->provider_id)
                ->where('user_id', $locked->user_id)->where('operation', $operation)
                ->where('request_headers->x-request-id', $uuid)->get();
            if ($logs->count() !== 1) {
                throw new RuntimeException('An unambiguous captured provider exchange is required. Request outcome review.');
            }
            $log = $logs->sole();
            $evidence = $log->niumExchangeEvidence;
            $endpoint = (string) config('services.nium.'.($fx ? 'payout_fx_lock_endpoint' : 'transfer_endpoint'));
            $redacted = app(NiumService::class)->path($endpoint, ['client' => '[REDACTED]', 'customer' => '[REDACTED]', 'wallet' => '[REDACTED]']);
            $redacted = str_replace(urlencode('[REDACTED]'), '[REDACTED]', $redacted);
            $expectedUrl = rtrim($context['base_url'], '/').'/'.ltrim($redacted, '/');
            // The existing audit URL projector also redacts long endpoint segments.
            $expectedUrl = preg_replace('~(?<=/)[a-z0-9_-]{16,}$~i', '[REDACTED]', $expectedUrl);
            $response = $evidence?->raw_response_body;
            $capturedHeaders = array_change_key_case($evidence?->request_headers ?? [], CASE_LOWER);
            if (! $log->is_success || $log->response_status < 200 || $log->response_status >= 300
                || $log->request_method !== ($fx ? 'GET' : 'POST') || $log->client_hash_id !== $context['client']
                || strtok($log->request_url, '?') !== $expectedUrl || $evidence === null || ! $evidence->response_received
                || ! is_string($response) || ! hash_equals((string) $evidence->response_body_sha256, hash('sha256', $response))
                || ($capturedHeaders['x-request-id'] ?? null) !== [$uuid]
                || (! $fx && ($log->related_transfer_id !== $locked->id || $log->external_reference !== $locked->provider_operation_key))) {
                throw new RuntimeException('Captured provider evidence does not match this operation. Request outcome review.');
            }
            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                throw new RuntimeException('Captured provider response is unavailable.');
            }
            $raw = $locked->raw_data;
            if ($fx) {
                $contract = NiumPayoutFxLockContract::parse($data, $locked->source_currency, $locked->target_currency,
                    NiumSgdLocalCugScope::principal($locked->source_amount));
                $quotes = FxQuote::query()->where('provider_id', $locked->provider_id)->where('user_id', $locked->user_id)
                    ->where('quote_ref', $contract['audit_id'])->lockForUpdate()->get();
                if ($quotes->count() > 1) {
                    throw new RuntimeException('Captured audit has ambiguous local bindings.');
                }
                $quote = $quotes->first();
                if ($quote === null) {
                    $quote = FxQuote::query()->create(['user_id' => $locked->user_id, 'provider_id' => $locked->provider_id, 'quote_ref' => $contract['audit_id'],
                        'source_currency' => $locked->source_currency, 'target_currency' => $locked->target_currency,
                        'source_amount' => $contract['source_amount'],
                        'target_amount' => (string) BigDecimal::of($contract['source_amount'])->multipliedBy($contract['rate'])->toScale(8, RoundingMode::DOWN),
                        'net_rate' => $contract['rate'], 'fee_amount' => 0, 'expires_at' => $contract['expires_at'],
                        'raw_data' => ['provider_fx_type' => 'payout_fx_lock', 'audit_id' => $contract['audit_id'],
                            'payout_fx_lock_contract' => $contract, 'provider_context' => $context,
                            'lock_request_uuid' => $uuid, 'recovered_api_request_log_id' => $log->id, 'provider_fee_status' => 'unavailable']]);
                }
                if (Transfer::query()->where('fx_quote_id', $quote->id)->whereKeyNot($locked->id)->exists()
                    || ! NiumPayoutFxLockContract::sameBinding($quote->raw_data['payout_fx_lock_contract'] ?? null, $contract)) {
                    throw new RuntimeException('Captured audit is already bound or its stored evidence changed.');
                }
                $locked->fx_quote_id = $quote->id;
                $locked->fx_rate = $quote->net_rate;
                NiumPayoutFxLockContract::assertMatchesTransfer($quote, $locked, $context);
                $raw['sgd_local_workflow']['state'] = 'locked';
            } else {
                $request = $evidence->raw_request_body;
                $expected = $locked->raw_data['sgd_local_approval'] ?? [];
                $payload = is_string($request) ? json_decode($request, true, 512, JSON_THROW_ON_ERROR) : null;
                $reference = $data['system_reference_number'] ?? $data['systemReferenceNumber'] ?? null;
                $hold = LedgerEntry::query()->where('reference', "transfer:{$locked->id}:hold")->first();
                $wireIntent = NiumSgdLocalRemittancePayload::fromSnapshot($expected);
                if (! is_string($request) || ! hash_equals((string) $evidence->request_body_sha256, hash('sha256', $request))
                    || ! NiumPayoutFxLockContract::sameBinding($payload, $wireIntent)
                    || ! NiumPayoutFxLockContract::sameBinding($expected['provider_context'] ?? null, $context)
                    || ($hold?->raw_data['operation_key'] ?? null) !== $locked->provider_operation_key
                    || LedgerEntry::query()->whereIn('reference', ["transfer:{$locked->id}:release", "transfer:{$locked->id}:debit"])->exists()
                    || ! is_string($reference) || trim($reference) === '') {
                    throw new RuntimeException('Captured response has no verified payment reference. Request outcome review.');
                }
                // Acceptance is not settlement. Preserve the unknown state and hold until a status GET/webhook resolves it.
                $locked->external_transfer_id = $reference;
            }
            $raw['sgd_local_workflow']['captured_outcome_recovery'] = ['api_request_log_id' => $log->id,
                'response_sha256' => $evidence->response_body_sha256, 'recovered_at' => now()->toISOString(), 'request_uuid' => $uuid];
            $locked->raw_data = $raw;
            $locked->save();

            return $locked;
        });
    }
}
