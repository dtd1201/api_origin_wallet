<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\NiumVirtualAccountOperation as Operation;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use Illuminate\Support\Str;
use RuntimeException;

/** Original target proof is distinct from proof that a POST assigned a Payment ID. */
final class NiumLegacyVirtualAccountEvidence
{
    public function target(Operation $operation, UserProviderAccount $account): NiumVirtualAccountRequestIdentity
    {
        if ($operation->legacy_target_snapshot !== null) {
            $identity = NiumVirtualAccountRequestIdentity::fromSnapshot($operation->legacy_target_snapshot);
            if (! Str::isUuid($operation->legacy_provider_request_id)) {
                throw new RuntimeException('legacy_target_evidence_invalid');
            }
        } else {
            // Only the persisted operation-to-POST link can select historical evidence.
            // Never choose a latest log, timing match, GET tag or current account mapping.
            $log = ApiRequestLog::query()->find($operation->api_request_log_id);
            if ($log === null || Operation::query()->where('api_request_log_id', $log->id)->whereKeyNot($operation->id)->exists()) {
                throw new RuntimeException('legacy_target_evidence_missing_or_ambiguous');
            }
            $exchange = $log->niumExchangeEvidence()->first();
            $target = $exchange?->assignment_target ?? $exchange?->request_url;
            if (! is_string($target) || ! preg_match('#^https://[^/]+(/api/v2/client/([A-Za-z0-9_-]+)/customer/([A-Za-z0-9_-]+)/wallet/([A-Za-z0-9_-]+)/paymentId)$#D', $target, $parts)) {
                throw new RuntimeException('legacy_target_evidence_missing_or_redacted');
            }
            $origin = NiumVirtualAccountRequestIdentity::origin($target);
            $identity = NiumVirtualAccountRequestIdentity::fromSnapshot([
                'environment' => 'nium:'.hash('sha256', $origin), 'provider_origin' => $origin,
                'provider_account_id' => (int) $operation->user_provider_account_id,
                'provider_id' => (int) $log->provider_id, 'user_id' => (int) $log->user_id,
                'client_id' => $parts[2], 'customer_id' => $parts[3], 'wallet_id' => $parts[4],
                'api_version' => 'v2', 'endpoint' => $parts[1],
                'payload' => $this->payload($operation),
            ]);
            $this->verifyExchange($operation, $log, $identity);
        }
        $snapshot = $identity->snapshot();
        if ($snapshot['api_version'] !== 'v2' || $snapshot['provider_account_id'] !== (int) $operation->user_provider_account_id
            || $snapshot['payload'] !== $this->payload($operation)
            || (int) $account->id !== $snapshot['provider_account_id']) {
            throw new RuntimeException('legacy_target_evidence_conflict');
        }
        // Comparison only: today's configuration never supplies historical proof.
        $identity->assertCurrentTarget($account);

        return $identity;
    }

    public function assignedPaymentId(Operation $operation, UserProviderAccount $account): string
    {
        $identity = $this->target($operation, $account);
        $snapshot = $identity->snapshot();
        $logs = ApiRequestLog::query()->where('provider_id', $snapshot['provider_id'])
            ->where('user_id', $snapshot['user_id'])->where('operation', 'assign_payment_id')
            ->where('external_reference', $operation->operation_id)->get();
        if ($logs->count() !== 1) {
            throw new RuntimeException('legacy_post_evidence_missing_or_ambiguous');
        }
        $log = $logs->sole();
        if ($operation->legacy_target_snapshot === null && (int) $operation->api_request_log_id !== (int) $log->id) {
            throw new RuntimeException('legacy_post_evidence_conflict');
        }
        $response = $this->verifyExchange($operation, $log, $identity);
        if ($log->response_status !== 200 || isset($response['errors'])
            || ! NiumVirtualAccountAssignmentResponse::matchesTarget($response, $identity)
            || ! NiumPaymentIdValidator::isValid($response['uniquePaymentId'] ?? null)) {
            throw new RuntimeException('legacy_post_outcome_unresolved_or_conflicting');
        }
        // A retained authenticated receipt for this literal ID may contradict the
        // proposed owner. Do not discard it because today's mapping differs.
        foreach (WebhookEvent::query()->where('provider_id', $snapshot['provider_id'])
            ->whereNotNull('nium_va_evidence')->get() as $event) {
            $payload = NiumVirtualAccountWebhookContract::payload($event);
            if (($payload['uniquePaymentId'] ?? null) === $response['uniquePaymentId']
                && ! NiumVirtualAccountWebhookContract::permitsAssignment($event, $identity, $response['uniquePaymentId'])) {
                throw new RuntimeException('legacy_webhook_evidence_conflict');
            }
        }

        return $response['uniquePaymentId'];
    }

    public function payload(Operation $operation): array
    {
        $payload = NiumVirtualAccountAssignmentContract::payload('v2', $operation->currency,
            $operation->account_category, $operation->account_type, $operation->bank_name);
        ksort($payload, SORT_STRING);

        return $payload;
    }

    private function verifyExchange(Operation $operation, ApiRequestLog $log, NiumVirtualAccountRequestIdentity $identity): array
    {
        $snapshot = $identity->snapshot();
        $exchange = $log->niumExchangeEvidence()->first();
        $requestId = $log->request_headers['x-request-id'] ?? null;
        if ($exchange === null || $exchange->capture_version !== 1 || ! is_string($requestId) || ! Str::isUuid($requestId)
            || ($operation->legacy_provider_request_id !== null && $requestId !== $operation->legacy_provider_request_id)
            || ($exchange->request_headers['x-request-id'] ?? null) !== [$requestId]
            || $log->provider_id !== $snapshot['provider_id'] || $log->user_id !== $snapshot['user_id']
            || $log->operation !== 'assign_payment_id' || $log->external_reference !== $operation->operation_id
            || $log->client_hash_id !== $snapshot['client_id'] || $log->request_method !== 'POST'
            || $exchange->request_method !== 'POST'
            || ($exchange->assignment_target ?? $exchange->request_url) !== $snapshot['provider_origin'].$snapshot['endpoint']
            || $operation->submitted_at === null || $log->request_started_at === null
            || $operation->submitted_at->lt($operation->claimed_at)
            || $log->request_started_at->lt($operation->submitted_at)
            || $log->request_finished_at === null || $log->request_finished_at->lt($log->request_started_at)
            || ! $exchange->response_received
            || ! is_string($exchange->raw_request_body) || ! is_string($exchange->raw_response_body)
            || ! is_string($exchange->request_body_sha256) || ! is_string($exchange->response_body_sha256)
            || ! hash_equals($exchange->request_body_sha256, hash('sha256', $exchange->raw_request_body))
            || ! hash_equals($exchange->response_body_sha256, hash('sha256', $exchange->raw_response_body))) {
            throw new RuntimeException('legacy_post_evidence_conflict');
        }
        $payload = json_decode($exchange->raw_request_body, true, flags: JSON_THROW_ON_ERROR);
        $response = json_decode($exchange->raw_response_body, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload) || ! is_array($response) || array_is_list($response)) {
            throw new RuntimeException('legacy_post_evidence_invalid');
        }
        ksort($payload, SORT_STRING);
        if ($payload !== $snapshot['payload']) {
            throw new RuntimeException('legacy_post_payload_conflict');
        }

        return $response;
    }
}
