<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use App\Services\Wallet\Concurrency\OwnerFirst;
use App\Models\ApiRequestLog;
use App\Models\Beneficiary;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumBeneficiaryService;
use RuntimeException;
use Illuminate\Support\Facades\DB;

/** Checks original authenticated registration bytes, not editable provider_verified flags. */
final class DynamicRegisteredAccountEvidence
{
    public function __construct(private readonly NiumBeneficiaryService $beneficiaries,
        private readonly DynamicBeneficiaryDetailsEvidence $details) {}

    public function inspect(Beneficiary $beneficiary, UserProviderAccount $account, array $quoteBinding, bool $refresh = false): array
    {
        if (DB::transactionLevel() > 0) OwnerFirst::current([$account->user_id, $beneficiary->user_id]);
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
        if (($response['beneficiaryHashId'] ?? null) !== $beneficiary->external_beneficiary_id) {
            throw new RuntimeException('dynamic_quote_registration_evidence_unavailable');
        }
        $current = $this->beneficiaries->receivingAccountBinding($beneficiary);
        // Normalize serialization only, including numeric tokens. Never normalize
        // account identifiers, leading zeroes, names or routing values.
        $current = ExactProviderJson::decode(json_encode($current, JSON_THROW_ON_ERROR));
        $payloadMatches = self::canonical($registered) === self::canonical($current);
        $customerBound = ($response['customerHashId'] ?? null) === $account->external_customer_id;
        $details = null;
        // Never use a GET to overrule an explicit contradictory/null registration
        // echo. The fallback is only for old successful registrations without it.
        if ($payloadMatches && ! array_key_exists('customerHashId', $response)) {
            try {
                $details = $this->details->snapshot($beneficiary, $account, $quoteBinding,
                    json_decode($exchange->raw_request_body, true, 64, JSON_THROW_ON_ERROR), $refresh);
                $customerBound = ! isset($quoteBinding['beneficiary_customer_binding_sha256'])
                    || hash_equals($quoteBinding['beneficiary_customer_binding_sha256'], $details['binding_sha256']);
            } catch (\Throwable) {
                $customerBound = false;
            }
        }
        return ['current_record_sha256' => DynamicReceivingAccountBinding::fingerprint($beneficiary),
            'legal_entity' => $registered['clientLegalEntity'] ?? null,
            'registered_request_sha256' => $exchange->request_body_sha256,
            'registered_response_sha256' => $exchange->response_body_sha256,
            'payload_matches' => $payloadMatches, 'customer_scope_confirmed' => $customerBound,
            ...($details !== null && $customerBound ? ['customer_binding_sha256' => $details['binding_sha256']] : []),
            'reason_codes' => [...($payloadMatches ? [] : ['dynamic_registered_account_payload_changed']),
                ...($customerBound ? [] : ['dynamic_registered_account_customer_scope_unavailable'])]];
    }

    /** Read-only under locks. The raw provider response stays in sealed internal evidence. */
    public function bindingObservation(Beneficiary $beneficiary, UserProviderAccount $account, array $binding): ?array
    {
        if (! isset($binding['beneficiary_customer_binding_sha256'])) return null;
        $result = $this->inspect($beneficiary, $account, $binding);
        if ($result['reason_codes'] !== []) throw new RuntimeException($result['reason_codes'][0]);
        $exchange = ApiRequestLog::query()->findOrFail($binding['registration_log_id'])->niumExchangeEvidence;
        $snapshot = $this->details->snapshot($beneficiary, $account, $binding,
            json_decode($exchange->raw_request_body, true, 64, JSON_THROW_ON_ERROR));
        if (! hash_equals($binding['beneficiary_customer_binding_sha256'], $snapshot['binding_sha256'])) {
            throw new RuntimeException('dynamic_registered_account_customer_scope_unavailable');
        }
        return $snapshot;
    }

    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return array_map(static fn ($item) => is_array($item) ? self::canonical($item) : $item, $value);
    }
}
