<?php

namespace App\Services\Nium;

use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\Transfer;
use RuntimeException;

/**
 * Corridor capability is evidence of eligibility, not a remittance contract.
 * Dispatch additionally requires bound customer consent and verified pricing.
 */
final class NiumSgdLocalPayoutGate
{
    public const BLOCK_REASON = NiumPayoutFinancialControls::BLOCK_REASON;

    public function __construct(
        private readonly NiumRegionResolver $regions,
        private readonly NiumBeneficiaryPayoutMethodResolver $payoutMethods,
        private readonly NiumSupportedCorridorService $corridors,
        private readonly NiumProviderAccountStateService $accounts,
        private readonly NiumBeneficiaryService $beneficiaries,
    ) {}

    /** Read-only capability inspection; passing this check never permits submission. */
    public function assertCandidate(User $user, IntegrationProvider $provider, Beneficiary $beneficiary, string $targetCurrency): array
    {
        $this->assertLocalCandidate($user, $provider, $beneficiary, $targetCurrency);
        $corridor = $this->corridors->assertSupported($beneficiary, 'LOCAL', ['SWIFT']);
        $copy = clone $beneficiary;
        $raw = (array) $copy->raw_data;
        $raw['nium']['payoutMethod'] = 'LOCAL';
        $copy->raw_data = $raw;
        [, $schema] = $this->beneficiaries->requirements($copy);
        $this->beneficiaries->validatedPayload($copy, $schema);

        return $corridor;
    }

    /** Existing eligibility checks only; safe inside local authorization transactions. */
    public function assertLocalCandidate(User $user, IntegrationProvider $provider, Beneficiary $beneficiary, string $targetCurrency): void
    {
        $user->loadMissing('kycProfile');
        $profile = $user->kycProfile;

        if (strtolower(trim((string) $provider->code)) !== 'nium'
            || strtolower((string) $provider->status) !== 'active'
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
            || ! filled(trim((string) $beneficiary->account_number))
            || ! preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', (string) $beneficiary->swift_bic)) {
            throw new RuntimeException('Nium SGD Local requires an SG/SGD Individual beneficiary with a receiving account, authoritative LOCAL payout method and supported routing.');
        }

        $beneficiary->setRelation('user', $user);
    }

    public static function assertSubmissionAllowed(?Transfer $transfer = null): array
    {
        if ($transfer === null) {
            throw new RuntimeException(self::BLOCK_REASON);
        }
        app(NiumSgdLocalCugScope::class)->assertTransfer($transfer);
        app(NiumSgdLocalPayoutWorkflow::class)->assertIntent($transfer);

        return app(NiumPayoutFinancialControls::class)->assertFor($transfer);
    }

    public static function admission(Beneficiary $beneficiary): array
    {
        $open = false;
        try {
            app(NiumSgdAdmissionFence::class)->assertOpen();
            $open = true;
        } catch (RuntimeException) {
            // A read projection cannot open the fence or create operational files.
        }
        // A presentation of the server hold, not an eligibility or schema lookup.
        return [
            'allowed' => false, 'reason' => 'contract_verification_pending',
            'preparation_allowed' => $open && strtolower((string) $beneficiary->status) === 'active'
                && filled(trim((string) $beneficiary->external_beneficiary_id))
                && in_array(strtolower((string) $beneficiary->beneficiary_type), ['individual', 'personal'], true)
                && $beneficiary->payout_method === 'LOCAL',
            'beneficiary_id' => $beneficiary->id, 'provider_id' => $beneficiary->provider_id,
            'source_currency' => 'USD', 'destination_country' => 'SG',
            'destination_currency' => 'SGD', 'payout_method' => 'LOCAL',
        ];
    }
}
