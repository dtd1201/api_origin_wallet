<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;

final class NiumSupportEvidenceFormatter
{
    public function format(ApiRequestLog $log): array
    {
        return array_filter([
            'client_hash_id' => $log->client_hash_id,
            'operation' => $log->operation,
            'endpoint' => $log->endpoint_path,
            'external_reference' => $log->external_reference,
            'x_request_id' => $log->request_headers['x-request-id'] ?? null,
            'request_started_at_utc' => $log->request_started_at?->utc()->toIso8601String(),
            'request_finished_at_utc' => $log->request_finished_at?->utc()->toIso8601String(),
            'http_status' => $log->response_status,
            'transport_outcome' => $log->transport_outcome,
            'error_code' => $log->response_body['error_code'] ?? null,
            'message' => $log->response_body['message'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }
}
