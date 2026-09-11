<?php

namespace App\Services\Nium;

use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\KycProfile;
use App\Models\Transfer;
use App\Models\User;
use RuntimeException;

final class NiumTransferPolicy
{
    public function __construct(
        private readonly NiumRegionResolver $regionResolver,
        private readonly NiumBeneficiaryPayoutMethodResolver $payoutMethodResolver,
    ) {}

    public const SOURCE_CURRENCY = 'USD';

    public const DESTINATION_CURRENCY = 'USD';

    public const PAYOUT_METHOD = 'SWIFT';

    public const SOURCE_OF_FUNDS = 'Corporate Account';

    public const SWIFT_FEE_TYPE = 'SHA';

    public function appliesTo(IntegrationProvider $provider): bool
    {
        return strtolower(trim((string) $provider->code)) === 'nium';
    }

    public function normalizeCreate(array $validated, User $user, IntegrationProvider $provider, Beneficiary $beneficiary, array $authoritativePurposeCodes): array
    {
        $this->assertCustomerAndBeneficiary($user, $provider, $beneficiary);
        $this->rejectUnsupportedCreateValues($validated);
        $purpose = trim((string) ($validated['purpose_code'] ?? ''));
        if (! $this->purposeIsSupported($purpose, $authoritativePurposeCodes)) {
            throw new RuntimeException('Unsupported Nium purpose_code.');
        }

        return [
            ...$validated,
            'reference_text' => trim((string) ($validated['reference_text'] ?? '')),
            'source_bank_account_id' => null,
            'fx_quote_id' => null,
            'transfer_type' => 'payout',
            'source_currency' => self::SOURCE_CURRENCY,
            'target_currency' => self::DESTINATION_CURRENCY,
            'target_amount' => null,
            'fx_rate' => null,
            'fee_amount' => 0,
            'fee_currency' => self::SOURCE_CURRENCY,
            'purpose_code' => $purpose,
            'raw_data' => $this->safeClientMetadata((array) ($validated['raw_data'] ?? [])),
        ];
    }

    public function assertTransfer(Transfer $transfer, array $authoritativePurposeCodes): void
    {
        $provider = $transfer->provider;
        $user = $transfer->user;
        $beneficiary = $transfer->beneficiary;

        if (! $provider instanceof IntegrationProvider || ! $user instanceof User || ! $beneficiary instanceof Beneficiary) {
            throw new RuntimeException('Nium transfer is missing its authoritative user, provider, or beneficiary.');
        }

        $this->assertCustomerAndBeneficiary($user, $provider, $beneficiary);

        if ($transfer->source_bank_account_id !== null || $transfer->fx_quote_id !== null) {
            throw new RuntimeException('Nium USD wallet transfers do not accept a bank account or FX quote.');
        }

        if ($transfer->transfer_type !== 'payout'
            || strtoupper((string) $transfer->source_currency) !== self::SOURCE_CURRENCY
            || strtoupper((string) $transfer->target_currency) !== self::DESTINATION_CURRENCY
            || $transfer->target_amount !== null
            || $transfer->fx_rate !== null
            || (float) $transfer->fee_amount !== 0.0
            || strtoupper((string) $transfer->fee_currency) !== self::SOURCE_CURRENCY
            || ! $this->purposeIsSupported(trim((string) $transfer->purpose_code), $authoritativePurposeCodes)
            || ! filled(trim((string) $transfer->reference_text))
            || mb_strlen(trim((string) $transfer->reference_text)) > 255) {
            throw new RuntimeException('Transfer does not match the supported Nium HK USD SWIFT policy.');
        }
    }

    public function providerPayload(Transfer $transfer, array $authoritativePurposeCodes): array
    {
        $this->assertTransfer($transfer, $authoritativePurposeCodes);

        return [
            'beneficiary' => ['id' => $transfer->beneficiary->external_beneficiary_id],
            'payout' => [
                'sourceAmount' => (float) $transfer->source_amount,
                'sourceCurrency' => self::SOURCE_CURRENCY,
                'destinationCurrency' => self::DESTINATION_CURRENCY,
                'payoutMethod' => self::PAYOUT_METHOD,
                'tradeOrderID' => $transfer->reference_text,
                'swiftFeeType' => self::SWIFT_FEE_TYPE,
            ],
            'purposeCode' => $transfer->purpose_code,
            'sourceOfFunds' => self::SOURCE_OF_FUNDS,
            'customerComments' => $transfer->reference_text,
        ];
    }

    private function purposeIsSupported(string $code, array $authoritativePurposeCodes): bool
    {
        return $code !== '' && collect($authoritativePurposeCodes)->contains('code', $code);
    }

    private function assertCustomerAndBeneficiary(User $user, IntegrationProvider $provider, Beneficiary $beneficiary): void
    {
        $user->loadMissing('kycProfile');
        $profile = $user->kycProfile;
        if (! $profile instanceof KycProfile
            || strtolower((string) $profile->status) !== 'approved'
            || strtolower((string) $profile->applicant_type) !== 'business'
            || $this->customerRegion($profile) !== 'HK') {
            throw new RuntimeException('Nium transfers currently require an HK corporate customer.');
        }
        if ($beneficiary->user_id !== $user->id || $beneficiary->provider_id !== $provider->id) {
            throw new RuntimeException('Nium beneficiary does not belong to the transfer user and provider.');
        }
        if (strtolower((string) $beneficiary->status) !== 'active') {
            throw new RuntimeException('Nium beneficiary must be active.');
        }
        $payoutMethod = $this->payoutMethodResolver->resolve($beneficiary);
        if (strtoupper((string) $beneficiary->country_code) !== 'HK'
            || strtoupper((string) $beneficiary->currency) !== self::DESTINATION_CURRENCY
            || $payoutMethod !== self::PAYOUT_METHOD) {
            throw new RuntimeException('Nium beneficiary does not match the supported HK USD SWIFT corridor.');
        }
        if (! filled($beneficiary->external_beneficiary_id)) {
            throw new RuntimeException('Nium transfer requires a synced beneficiary.');
        }
    }

    private function customerRegion(KycProfile $profile): string
    {
        $metadata = (array) ($profile->metadata ?? []);

        return $this->regionResolver->resolve(
            $metadata['nium_region'] ?? null,
            $profile->registered_country_code,
            $profile->residence_country_code,
            $profile->country_code,
        );
    }

    private function rejectUnsupportedCreateValues(array $values): void
    {
        $expected = [
            'transfer_type' => 'payout',
            'source_currency' => self::SOURCE_CURRENCY,
            'target_currency' => self::DESTINATION_CURRENCY,
            'purpose_code' => null,
        ];
        foreach ($expected as $field => $value) {
            if ($field === 'purpose_code') {
                if (! is_string($values[$field] ?? null) || trim($values[$field]) === '') {
                    throw new RuntimeException('Nium purpose_code is required.');
                }

                continue;
            }
            if (strtoupper((string) ($values[$field] ?? '')) !== strtoupper($value)) {
                throw new RuntimeException("Unsupported Nium {$field}.");
            }
        }
        if (! filled(trim((string) ($values['reference_text'] ?? '')))) {
            throw new RuntimeException('Nium reference_text is required.');
        }
        if (filled($values['source_bank_account_id'] ?? null) || filled($values['fx_quote_id'] ?? null)) {
            throw new RuntimeException('Nium USD wallet transfers do not accept a bank account or FX quote.');
        }
    }

    private function safeClientMetadata(array $rawData): array
    {
        return array_filter([
            'source' => isset($rawData['source']) ? substr((string) $rawData['source'], 0, 50) : null,
            'flow' => isset($rawData['flow']) ? substr((string) $rawData['flow'], 0, 50) : null,
        ], static fn (mixed $value): bool => filled($value));
    }
}
