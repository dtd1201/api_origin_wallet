<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\Beneficiary;
use Illuminate\Support\Arr;

final class NiumBeneficiaryPayoutMethodResolver
{
    public function resolve(Beneficiary $beneficiary): ?string
    {
        $stored = $this->normalize($beneficiary->payout_method);

        return $stored ?? $this->successfulRequestEvidence($beneficiary)?->payoutMethod;
    }

    public function successfulRequestEvidence(Beneficiary $beneficiary): ?NiumBeneficiaryPayoutMethodEvidence
    {
        if (! filled($beneficiary->external_beneficiary_id)) {
            return null;
        }

        $logs = ApiRequestLog::query()
            ->where('provider_id', $beneficiary->provider_id)
            ->where('user_id', $beneficiary->user_id)
            ->where('operation', 'beneficiary_create')
            ->where('external_reference', (string) $beneficiary->id)
            ->where('request_method', 'POST')
            ->where('is_success', true)
            ->whereBetween('response_status', [200, 299])
            ->latest('id')
            ->get();

        foreach ($logs as $log) {
            $payoutMethod = $this->requestPayoutMethod((array) $log->request_body);
            $externalBeneficiaryId = $this->externalBeneficiaryId((array) $log->response_body);

            if ($payoutMethod !== null && $externalBeneficiaryId === (string) $beneficiary->external_beneficiary_id) {
                return new NiumBeneficiaryPayoutMethodEvidence(
                    (int) $log->id,
                    $payoutMethod,
                    $externalBeneficiaryId,
                );
            }
        }

        return null;
    }

    private function externalBeneficiaryId(array $responseBody): ?string
    {
        $value = $responseBody['beneficiary_id'] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function requestPayoutMethod(array $requestBody): ?string
    {
        foreach (['payout_method', 'payoutMethod', 'request.payoutMethod', 'payload.payoutMethod'] as $path) {
            $payoutMethod = $this->normalize(Arr::get($requestBody, $path));

            if ($payoutMethod !== null) {
                return $payoutMethod;
            }
        }

        return null;
    }

    private function normalize(mixed $payoutMethod): ?string
    {
        if (! is_scalar($payoutMethod)) {
            return null;
        }

        $payoutMethod = strtoupper(trim((string) $payoutMethod));

        return $payoutMethod !== '' ? $payoutMethod : null;
    }
}
