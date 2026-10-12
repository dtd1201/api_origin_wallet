<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\NiumApiExchangeEvidence;
use App\Models\User;
use App\Models\UserProviderAccount;
use DateTimeImmutable;
use Throwable;

final class NiumCurrentBiometricKyc
{
    /** @return array{status: string, mode: string, url: string, reference_id: string|null}|null */
    public function forUser(User $user): ?array
    {
        $account = $user->providerAccounts()
            ->whereHas('provider', fn ($query) => $query->whereRaw('LOWER(code) = ?', ['nium']))
            ->latest('id')->first();
        $attempts = $account?->metadata['nium_submit_kyc_attempts'] ?? null;
        if (! is_array($attempts) || $attempts === []) {
            return null;
        }

        $latest = collect(array_values($attempts))->filter(fn ($attempt) => is_array($attempt))->values()
            ->sortBy(fn (array $attempt, int $index) => sprintf('%020d-%020d',
                $this->time($attempt['updated_at'] ?? $attempt['created_at'] ?? null), $index))->last();
        if (! is_array($latest)
            || strtolower((string) ($latest['state'] ?? '')) !== 'accepted'
            || strtolower((string) ($latest['kyc_mode'] ?? '')) !== 'biometric_kyc'
            || strtolower((string) ($latest['entity_type'] ?? '')) !== 'applicant') {
            return null;
        }

        $externalId = trim((string) ($latest['external_id'] ?? ''));
        $referenceId = trim((string) ($latest['provider_reference_id'] ?? ''));
        $url = $latest['biometric_url'] ?? null;
        if ($externalId === '' || $referenceId === '') {
            return null;
        }

        $attemptAt = max($this->time($latest['submit_kyc_log_at'] ?? null), $this->time($latest['updated_at'] ?? null));
        $attemptStatus = strtolower((string) ($latest['entity_kyc_status'] ?? $latest['kyc_status'] ?? ''));
        if ($attemptStatus !== '' && ! in_array($attemptStatus, ['initiated', 'retry'], true)) {
            return null;
        }

        $newerStatus = $this->newerEntityStatus($account, $externalId, $referenceId, $attemptAt);
        if ($newerStatus !== null && ! in_array($newerStatus, ['initiated', 'retry'], true)) {
            return null;
        }

        $customerGet = $this->customerGet(
            $account,
            $externalId,
            $referenceId,
            $attemptAt,
            $latest['biometric_url_fingerprint'] ?? null,
        );
        if ($customerGet['terminal'] ?? false) {
            return null;
        }
        if (isset($customerGet['status'], $customerGet['url'])) {
            return $this->result($customerGet['status'], $customerGet['url'], $referenceId);
        }

        if (in_array($attemptStatus, ['initiated', 'retry'], true) && $this->trustedAttemptUrl($latest, $url)) {
            return $this->result($attemptStatus, $url, $referenceId);
        }

        if (! $this->trustedAttemptUrl($latest, $url)) {
            return null;
        }

        $submitStatus = $this->submitKyc($account, $latest, $externalId, $referenceId, $url);

        return $submitStatus === null ? null : $this->result($submitStatus, $url, $referenceId);
    }

    private function result(string $status, string $url, string $referenceId): array
    {
        return ['status' => $status, 'mode' => 'biometric_kyc', 'url' => $url, 'reference_id' => $referenceId];
    }

    private function submitKyc(UserProviderAccount $account, array $attempt, string $externalId, string $referenceId, string $url): ?string
    {
        $logId = $attempt['submit_kyc_log_id'] ?? null;
        if (! is_int($logId) || $logId <= 0) {
            return null;
        }

        $log = ApiRequestLog::query()->with('niumExchangeEvidence')->whereKey($logId)
            ->where('provider_id', $account->provider_id)->where('user_id', $account->user_id)
            ->where('operation', 'submit_kyc')->where('request_method', 'POST')->where('is_success', true)
            ->whereBetween('response_status', [200, 299])->first();
        if ($log === null || ! $this->logTargetsCustomer($log, $account)) {
            return null;
        }
        $body = $this->evidenceBody($log->niumExchangeEvidence);
        $evidenceUrl = is_array($body) ? ($body['biometricUrl'] ?? $body['redirectUrl'] ?? null) : null;
        if (! is_array($body)
            || strtolower((string) ($body['kycStatus'] ?? '')) !== 'initiated'
            || strtolower((string) ($body['entityType'] ?? '')) !== 'applicant'
            || strtolower((string) ($body['kycMode'] ?? '')) !== 'biometric_kyc'
            || ! hash_equals($externalId, trim((string) ($body['externalId'] ?? '')))
            || ! hash_equals($referenceId, trim((string) ($body['referenceId'] ?? '')))
            || ! is_string($evidenceUrl)
            || ! hash_equals($this->fingerprint($url), $this->fingerprint($evidenceUrl))) {
            return null;
        }

        return 'initiated';
    }

    /** @return array{status?: string, url?: string, terminal?: bool} */
    private function customerGet(
        UserProviderAccount $account,
        string $externalId,
        string $referenceId,
        int $attemptAt,
        mixed $expectedFingerprint,
    ): array {
        $logs = ApiRequestLog::query()->with('niumExchangeEvidence')->where('provider_id', $account->provider_id)
            ->where('user_id', $account->user_id)->where('request_method', 'GET')->where('is_success', true)
            ->whereBetween('response_status', [200, 299])
            ->whereHas('niumExchangeEvidence', fn ($query) => $query->where('response_received', true))
            ->orderByDesc('created_at')->orderByDesc('id')->get();

        foreach ($logs as $log) {
            if (! $this->logTargetsCustomer($log, $account)) {
                continue;
            }
            $customer = $this->evidenceBody($log->niumExchangeEvidence);
            $applicant = is_array($customer) ? ($customer['applicant'] ?? null) : null;
            if (! is_array($applicant)
                || ! hash_equals((string) $account->external_customer_id, trim((string) ($customer['customerHashId'] ?? '')))
                || strtolower((string) ($applicant['entityType'] ?? '')) !== 'applicant'
                || ! hash_equals($externalId, trim((string) ($applicant['externalId'] ?? '')))
                || ! hash_equals($referenceId, trim((string) ($applicant['referenceId'] ?? '')))
                || strtolower((string) ($applicant['kycMode'] ?? '')) !== 'biometric_kyc') {
                continue;
            }

            $status = strtolower((string) ($applicant['kycStatus'] ?? ''));
            $observedAt = $this->time($log->created_at?->toISOString());
            $evidenceUrl = $applicant['biometricUrl'] ?? null;
            if (! in_array($status, ['initiated', 'retry'], true)) {
                $sameSession = $this->isValidUrl($evidenceUrl)
                    && is_string($expectedFingerprint)
                    && preg_match('/^[a-f0-9]{16}$/', $expectedFingerprint) === 1
                    && hash_equals($expectedFingerprint, $this->fingerprint($evidenceUrl));

                return $observedAt > $attemptAt || $sameSession ? ['terminal' => true] : [];
            }
            if (in_array($status, ['initiated', 'retry'], true)
                && $this->isValidUrl($evidenceUrl)
                && ($expectedFingerprint === null
                    || (is_string($expectedFingerprint)
                        && preg_match('/^[a-f0-9]{16}$/', $expectedFingerprint) === 1
                        && hash_equals($expectedFingerprint, $this->fingerprint($evidenceUrl))))) {
                return ['status' => $status, 'url' => $evidenceUrl];
            }

            return $observedAt > $attemptAt ? ['terminal' => true] : [];
        }

        return [];
    }

    private function newerEntityStatus(UserProviderAccount $account, string $externalId, string $referenceId, int $attemptAt): ?string
    {
        $matches = collect((array) ($account->metadata['nium_entity_kyc_states'] ?? []))
            ->filter(fn (mixed $entity): bool => is_array($entity)
                && strtolower((string) ($entity['entity_type'] ?? '')) === 'applicant'
                && hash_equals($externalId, trim((string) ($entity['external_id'] ?? '')))
                && hash_equals($referenceId, trim((string) ($entity['provider_reference_id'] ?? '')))
                && strtolower((string) ($entity['kyc_mode'] ?? '')) === 'biometric_kyc'
                && $this->time($entity['updated_at'] ?? null) > $attemptAt)
            ->sortByDesc(fn (array $entity): int => $this->time($entity['updated_at'] ?? null))->values();

        if ($matches->isEmpty()) {
            return null;
        }

        $newestAt = $this->time($matches->first()['updated_at'] ?? null);
        $newestStatuses = $matches
            ->takeWhile(fn (array $entity): bool => $this->time($entity['updated_at'] ?? null) === $newestAt)
            ->map(fn (array $entity): string => strtolower((string) ($entity['kyc_status'] ?? '')))
            ->unique()
            ->values();

        return $newestStatuses->count() === 1 ? $newestStatuses->sole() : '';
    }

    private function evidenceBody(?NiumApiExchangeEvidence $evidence): ?array
    {
        $raw = $evidence?->raw_response_body;
        if (! $evidence?->response_received || ! is_string($raw) || $raw === ''
            || ! is_int($evidence->response_byte_length) || strlen($raw) !== $evidence->response_byte_length
            || ! is_string($evidence->response_body_sha256)
            || ! hash_equals($evidence->response_body_sha256, hash('sha256', $raw))) {
            return null;
        }
        $body = json_decode($raw, true);

        return is_array($body) && ! array_is_list($body) ? $body : null;
    }

    private function logTargetsCustomer(ApiRequestLog $log, UserProviderAccount $account): bool
    {
        $path = (string) (parse_url((string) $log->request_url, PHP_URL_PATH) ?: $log->endpoint_path ?: '');
        $customer = preg_quote((string) $account->external_customer_id, '/');

        return preg_match('/\/customer\/'.$customer.'(?:\/|$)/', $path) === 1
            && $log->niumExchangeEvidence?->request_method === $log->request_method
            && hash_equals(
                (string) $log->request_url,
                (string) $log->niumExchangeEvidence?->request_url,
            );
    }

    private function trustedAttemptUrl(array $attempt, mixed $url): bool
    {
        $fingerprint = $attempt['biometric_url_fingerprint'] ?? null;

        return $this->isValidUrl($url) && is_string($fingerprint)
            && preg_match('/^[a-f0-9]{16}$/', $fingerprint) === 1
            && hash_equals($fingerprint, $this->fingerprint($url));
    }

    private function fingerprint(string $url): string
    {
        return substr(hash('sha256', $url), 0, 16);
    }

    private function time(mixed $value): int
    {
        if (! is_string($value) || $value === '') {
            return 0;
        }

        try {
            return (int) (new DateTimeImmutable($value))->format('Uu');
        } catch (Throwable) {
            return 0;
        }
    }

    private function isValidUrl(mixed $url): bool
    {
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }
}
