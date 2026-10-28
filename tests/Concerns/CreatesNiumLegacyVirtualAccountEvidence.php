<?php

namespace Tests\Concerns;

use App\Models\ApiRequestLog;
use App\Models\NiumApiExchangeEvidence;
use App\Models\NiumVirtualAccountOperation as Operation;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumVirtualAccountRequestIdentity;
use Illuminate\Support\Str;

trait CreatesNiumLegacyVirtualAccountEvidence
{
    private function legacyEvidenceOperation(UserProviderAccount $account, bool $frozen = true): Operation
    {
        $identity = $this->ledgerIdentity($account);
        $operation = $this->legacyLedgerOperation($account, Operation::CLAIMED, [
            'bank_name' => 'DBS_HK',
            'claimed_at' => now()->subSeconds(2),
            'legacy_target_snapshot' => $frozen ? $identity->snapshot() : null,
            'legacy_provider_request_id' => $frozen ? (string) Str::uuid() : null,
        ]);
        $operation->update(['status' => Operation::UNKNOWN, 'submitted_at' => now()->subSecond()]);

        return $operation->fresh();
    }

    private function legacyPostEvidence(
        Operation $operation,
        NiumVirtualAccountRequestIdentity $identity,
        string $paymentId,
        array $exchangeChanges = [],
        bool $link = true,
    ): ApiRequestLog {
        $snapshot = $identity->snapshot();
        $requestId = $operation->legacy_provider_request_id ?? (string) Str::uuid();
        $requestBody = json_encode($snapshot['payload'], JSON_THROW_ON_ERROR);
        $responseBody = json_encode(['uniquePaymentId' => $paymentId], JSON_THROW_ON_ERROR);
        $endpoint = '/api/v2/client/[REDACTED]/customer/[REDACTED]/wallet/[REDACTED]/paymentId';
        $log = ApiRequestLog::query()->create([
            'provider_id' => $snapshot['provider_id'], 'user_id' => $snapshot['user_id'],
            'operation' => 'assign_payment_id', 'client_hash_id' => $snapshot['client_id'],
            'external_reference' => $operation->operation_id, 'request_method' => 'POST',
            'request_url' => $snapshot['provider_origin'].$endpoint, 'endpoint_path' => $endpoint,
            'request_headers' => ['x-request-id' => $requestId], 'response_status' => 200,
            'request_started_at' => now(), 'request_finished_at' => now(),
        ]);
        NiumApiExchangeEvidence::query()->create(array_replace([
            'api_request_log_id' => $log->id, 'request_method' => 'POST',
            'request_url' => $snapshot['provider_origin'].$snapshot['endpoint'],
            'assignment_target' => null, 'capture_version' => 1,
            'raw_request_body' => $requestBody, 'raw_response_body' => $responseBody,
            'request_headers' => ['x-request-id' => [$requestId]], 'response_received' => true,
            'request_body_sha256' => hash('sha256', $requestBody), 'response_body_sha256' => hash('sha256', $responseBody),
        ], $exchangeChanges));
        if ($link) {
            $operation->update(['api_request_log_id' => $log->id]);
        }

        return $log;
    }
}
