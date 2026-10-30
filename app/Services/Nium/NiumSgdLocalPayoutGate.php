<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use RuntimeException;

/**
 * Corridor capability is evidence of eligibility, not a remittance contract.
 * Financial admission requires verified provider pricing and a bound exact total debit.
 */
final class NiumSgdLocalPayoutGate
{
    public const BLOCK_REASON = NiumPayoutFinancialControls::BLOCK_REASON;

    public function __construct(
        private readonly NiumRegionResolver $regions,
        private readonly NiumBeneficiaryPayoutMethodResolver $payoutMethods,
        private readonly NiumSupportedCorridorService $corridors,
        private readonly NiumProviderAccountStateService $accounts,
        private readonly NiumService $nium,
    ) {}

    /** Read-only capability inspection; passing this check never permits submission. */
    public function assertCandidate(User $user, IntegrationProvider $provider, Beneficiary $beneficiary, string $targetCurrency): array
    {
        $this->assertLocalCandidate($user, $provider, $beneficiary, $targetCurrency);

        return $this->corridors->assertSupported($beneficiary, 'LOCAL', ['SWIFT']);
    }

    /** Existing local eligibility and registration evidence only; no provider request. */
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
            || ! preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', (string) $beneficiary->swift_bic)) {
            throw new RuntimeException('Nium SGD Local requires an SG/SGD Individual beneficiary with authoritative LOCAL payout method and SWIFT routing.');
        }

        $beneficiary->setRelation('user', $user);
        $this->assertRegistered($beneficiary);

    }

    private function assertRegistered(Beneficiary $beneficiary): void
    {
        // Customer-editable metadata and a populated external ID do not prove
        // provider registration. Inspect the captured response, never make a POST.
        $log = ApiRequestLog::query()
            ->where('provider_id', $beneficiary->provider_id)
            ->where('user_id', $beneficiary->user_id)
            ->where('operation', 'beneficiary_create')
            ->where('external_reference', (string) $beneficiary->id)
            ->latest('id')->first();
        $evidence = $log?->niumExchangeEvidence;
        // Audit URLs deliberately redact provider IDs. Ownership comes from the
        // log's user/provider/local-beneficiary binding and the response ID.
        $expectedPath = $this->nium->path((string) config('services.nium.beneficiary_endpoint'), [
            'client' => '[REDACTED]',
            'customer' => '[REDACTED]',
        ]);
        $expectedPath = str_replace(urlencode('[REDACTED]'), '[REDACTED]', $expectedPath);
        $expectedUrl = rtrim((string) config('services.nium.base_url'), '/').'/'.ltrim($expectedPath, '/');
        if ($log === null || ! $log->is_success || $log->request_method !== 'POST'
            || $log->response_status < 200 || $log->response_status >= 300
            || $log->client_hash_id !== $this->nium->clientId()
            || $log->request_url !== $expectedUrl
            || $evidence === null || ! $evidence->response_received) {
            throw new RuntimeException('Nium SGD Local requires captured provider registration evidence for this beneficiary and customer.');
        }

        try {
            $raw = $evidence->raw_response_body;
            $response = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (DecryptException|\JsonException) {
            throw new RuntimeException('Nium SGD Local beneficiary registration evidence is unreadable.');
        }
        $payload = is_array($response) ? ($response['data'] ?? $response['beneficiary'] ?? $response) : null;
        $registration = $this->payoutMethods->successfulRequestEvidence($beneficiary);
        if (! is_string($raw) || ! hash_equals((string) $evidence->response_body_sha256, hash('sha256', $raw))
            || ! is_array($payload) || ($payload['status'] ?? null) !== 'ACTIVE'
            || ($payload['beneficiaryHashId'] ?? $payload['id'] ?? null) !== $beneficiary->external_beneficiary_id
            || $registration?->apiRequestLogId !== $log->id || $registration->payoutMethod !== 'LOCAL') {
            throw new RuntimeException('Nium SGD Local requires an explicitly active registered provider beneficiary with matching LOCAL evidence.');
        }
    }

    public static function assertSubmissionAllowed(?Transfer $transfer = null): array
    {
        // Ordinary create/FX paths have no reviewed workflow intent and remain closed.
        if ($transfer === null) {
            throw new RuntimeException(self::BLOCK_REASON);
        }

        try {
            app(NiumSgdLocalCugScope::class)->assertTransfer($transfer);
            app(NiumSgdLocalPayoutWorkflow::class)->assertIntent($transfer);

            return app(NiumPayoutFinancialControls::class)->assertFor($transfer);
        } catch (\Throwable) {
            throw new RuntimeException(self::BLOCK_REASON);
        }
    }

    public static function admission(Beneficiary $beneficiary): array
    {
        $preparation = app(NiumSgdLocalCugScope::class)->canPrepare($beneficiary);

        // A presentation of the server hold, not an eligibility or schema lookup.
        return [
            'allowed' => false, 'reason' => 'financial_terms_unverified',
            'beneficiary_id' => $beneficiary->id, 'provider_id' => $beneficiary->provider_id,
            'source_currency' => 'USD', 'destination_country' => 'SG',
            'destination_currency' => 'SGD', 'payout_method' => 'LOCAL',
            'preparation_allowed' => $preparation,
            'fx_lock_enabled' => $preparation && (bool) config('services.nium.payout_fx_enabled', false),
        ];
    }
}
