<?php

namespace App\Services\Nium;

use App\Models\UserProviderAccount;
use Throwable;

final class NiumSubmitKycBiometricUrlResolver
{
    public function __construct(
        private readonly NiumCustomerOnboardingService $customerOnboardingService,
    ) {}

    public function resolve(
        UserProviderAccount $account,
        string $externalId,
        ?string $providerReferenceId,
    ): ?string {
        $applicant = $this->resolveApplicant($account, $externalId, $providerReferenceId);
        $biometricUrl = $applicant['biometricUrl'] ?? null;

        return is_string($biometricUrl) && trim($biometricUrl) !== '' ? $biometricUrl : null;
    }

    public function resolveApplicant(
        UserProviderAccount $account,
        string $externalId,
        ?string $providerReferenceId,
    ): ?array {
        $customer = null;

        try {
            $this->customerOnboardingService->retrieveCustomer(
                providerAccount: $account,
                user: $account->user,
                retrievedPayload: $customer,
            );
        } catch (Throwable) {
            return null;
        }

        $applicant = $customer['applicant'] ?? null;
        if (! is_array($applicant)
            || ! hash_equals($externalId, trim((string) ($applicant['externalId'] ?? '')))) {
            return null;
        }

        $expectedReference = trim((string) $providerReferenceId);
        $actualReference = trim((string) ($applicant['referenceId'] ?? ''));
        if ($expectedReference !== ''
            && ($actualReference === '' || ! hash_equals($expectedReference, $actualReference))) {
            return null;
        }

        return $applicant;
    }
}
