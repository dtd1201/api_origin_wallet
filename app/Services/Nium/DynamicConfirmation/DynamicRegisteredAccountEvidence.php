<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use App\Models\ApiRequestLog;
use App\Models\Beneficiary;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumBeneficiaryService;
use RuntimeException;
use Illuminate\Support\Facades\DB;

/** Checks original authenticated registration bytes, not editable provider_verified flags. */
final class DynamicRegisteredAccountEvidence
{
    public function __construct(private readonly NiumBeneficiaryService $beneficiaries) {}

    public function inspect(Beneficiary $beneficiary, UserProviderAccount $account, array $quoteBinding): array
    {
        $log = ApiRequestLog::query()->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->findOrFail($quoteBinding['registration_log_id']);
        $exchange = $log->niumExchangeEvidence()->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->first();
        if ($log->user_id !== $beneficiary->user_id || $log->provider_id !== $beneficiary->provider_id
            || ! $log->is_success || $exchange === null || ! $exchange->response_received
            || $log->client_hash_id !== $quoteBinding['client']
            || hash('sha256', $exchange->raw_request_body) !== $quoteBinding['registration_request_sha256']
            || hash('sha256', $exchange->raw_response_body) !== $quoteBinding['registration_response_sha256']) {
            throw new RuntimeException('dynamic_quote_registration_evidence_unavailable');
        }
        $registered = ExactProviderJson::decode($exchange->raw_request_body);
        $response = ExactProviderJson::decode($exchange->raw_response_body);
        $response = $response['data'] ?? $response;
        $current = $this->beneficiaries->receivingAccountBinding($beneficiary);
        // Normalize serialization only, including numeric tokens. Never normalize
        // account identifiers, leading zeroes, names or routing values.
        $current = ExactProviderJson::decode(json_encode($current, JSON_THROW_ON_ERROR));
        $payloadMatches = self::canonical($registered) === self::canonical($current);
        $customerBound = ($response['customerHashId'] ?? null) === $account->external_customer_id;
        return ['current_record_sha256' => DynamicReceivingAccountBinding::fingerprint($beneficiary),
            'legal_entity' => $registered['clientLegalEntity'] ?? null,
            'registered_request_sha256' => $exchange->request_body_sha256,
            'registered_response_sha256' => $exchange->response_body_sha256,
            'payload_matches' => $payloadMatches, 'customer_scope_confirmed' => $customerBound,
            'reason_codes' => [...($payloadMatches ? [] : ['dynamic_registered_account_payload_changed']),
                ...($customerBound ? [] : ['dynamic_registered_account_customer_scope_unavailable'])]];
    }

    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return array_map(static fn ($item) => is_array($item) ? self::canonical($item) : $item, $value);
    }
}
