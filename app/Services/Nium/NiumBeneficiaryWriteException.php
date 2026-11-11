<?php

namespace App\Services\Nium;

use Illuminate\Http\Client\Response;
use RuntimeException;

final class NiumBeneficiaryWriteException extends RuntimeException
{
    public readonly array $safeDiagnostic;

    public function __construct(Response $response, string $stage, ?string $requestId = null)
    {
        parent::__construct('Nium beneficiary provider response could not be accepted.');

        $projector = app(NiumSafeValueProjector::class);
        $body = $response->json();
        $body = is_array($body) ? $body : [];
        // Select only error projections. Do not retain provider messages, returned IDs or raw bodies.
        $safeErrors = array_intersect_key($projector->apiResponseBody($body, $response->status()), array_flip([
            'error_code', 'error_category', 'error_fingerprint', 'error_field_fingerprint',
            'error_path_fingerprint', 'error_parameter_fingerprint', 'error_items',
        ]));
        $this->safeDiagnostic = array_filter([
            'stage' => in_array($stage, ['beneficiary_create', 'beneficiary_update', 'beneficiary_account_verification'], true) ? $stage : 'unknown',
            'http_status' => $projector->safeHttpStatus($response->status()),
            'response_received' => true,
            'failure_kind' => $response->successful() ? 'missing_beneficiary_id' : 'http_error',
            'request_id' => $projector->requestId($requestId),
            'response_request_id' => $projector->requestId($response->header('x-request-id')),
            'provider_error_count' => is_array($body['errors'] ?? null) ? count($body['errors']) : 0,
            'errors' => $safeErrors,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
