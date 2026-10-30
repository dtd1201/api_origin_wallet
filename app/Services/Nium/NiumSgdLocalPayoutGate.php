<?php

namespace App\Services\Nium;

use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\User;
use RuntimeException;

/**
 * Corridor capability is evidence of eligibility, not a remittance contract.
 * This preparation must stay closed until the route contract is reviewed.
 */
final class NiumSgdLocalPayoutGate
{
    public const BLOCK_REASON = 'Nium SG/SGD/LOCAL submission is unavailable pending verification of the remittance, FX, fees, and payment-purpose contracts.';

    public function __construct(
        private readonly NiumRegionResolver $regions,
        private readonly NiumBeneficiaryPayoutMethodResolver $payoutMethods,
        private readonly NiumSupportedCorridorService $corridors,
        private readonly NiumProviderAccountStateService $accounts,
    ) {}

    /** Read-only capability inspection; passing this check never permits submission. */
    public function assertCandidate(User $user, IntegrationProvider $provider, Beneficiary $beneficiary, string $targetCurrency): array
    {
        $user->loadMissing('kycProfile');
        $profile = $user->kycProfile;

        if (strtolower(trim((string) $provider->code)) !== 'nium'
            || ! in_array(strtolower((string) $user->kyc_status), ['approved', 'verified'], true)
            || $profile === null
            || ! in_array(strtolower((string) $profile->status), ['approved', 'verified'], true)
            || strtolower((string) $profile->applicant_type) !== 'business'
            || $this->regions->resolve(
                $profile->metadata['nium_region'] ?? null,
                $profile->registered_country_code,
                $profile->residence_country_code,
                $profile->country_code,
            ) !== 'HK') {
            throw new RuntimeException('Nium SGD Local requires a verified HK corporate customer.');
        }

        $account = $this->accounts->assertEligible($user);
        if ($account->provider_id !== $provider->id || $account->reconciliation_status !== 'reconciled') {
            throw new RuntimeException('Nium SGD Local requires a reconciled account matching the payout provider.');
        }

        if ($beneficiary->user_id !== $user->id
            || $beneficiary->provider_id !== $provider->id
            || strtolower((string) $beneficiary->status) !== 'active'
            || ! filled(trim((string) $beneficiary->external_beneficiary_id))) {
            throw new RuntimeException('Nium SGD Local requires an active synced beneficiary belonging to this customer and provider.');
        }

        if (strtoupper((string) $beneficiary->country_code) !== 'SG'
            || strtoupper((string) $beneficiary->currency) !== 'SGD'
            || $targetCurrency !== 'SGD'
            || ! in_array(strtolower((string) $beneficiary->beneficiary_type), ['personal', 'individual'], true)
            || $this->payoutMethods->resolve($beneficiary) !== 'LOCAL'
            || ! preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', (string) $beneficiary->swift_bic)) {
            throw new RuntimeException('Nium SGD Local requires an SG/SGD Individual beneficiary with authoritative LOCAL payout method and SWIFT routing.');
        }

        $beneficiary->setRelation('user', $user);

        return $this->corridors->assertSupported($beneficiary, 'LOCAL', ['SWIFT']);
    }

    public static function assertSubmissionAllowed(): never
    {
        // Deliberately not configurable: the global FX flag is not route approval.
        // Implement the reviewed LOCAL payload and financial contract before opening.
        throw new RuntimeException(self::BLOCK_REASON);
    }
}
