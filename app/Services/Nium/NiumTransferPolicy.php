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

    public function normalizeCreate(
        array $validated,
        User $user,
        IntegrationProvider $provider,
        Beneficiary $beneficiary,
        array $authoritativePurposeCodes,
    ): array {
        $targetCurrency = strtoupper(trim((string) ($validated['target_currency'] ?? '')));

        $this->rejectUnsupportedCreateValues($validated);
        $this->assertCustomerAndBeneficiary(
            $user,
            $provider,
            $beneficiary,
            $targetCurrency,
        );

        $purpose = trim((string) ($validated['purpose_code'] ?? ''));

        if (! $this->purposeIsSupported($purpose, $authoritativePurposeCodes)) {
            throw new RuntimeException('Unsupported Nium purpose_code.');
        }

        return [
            ...$validated,
            'reference_text' => trim((string) ($validated['reference_text'] ?? '')),
            'source_bank_account_id' => null,

            // Nium payout FX locks are authoritative and are attached
            // server-side at submission time, never accepted from the client.
            'fx_quote_id' => null,

            'transfer_type' => 'payout',
            'source_currency' => self::SOURCE_CURRENCY,
            'target_currency' => $targetCurrency,
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

        if (! $provider instanceof IntegrationProvider
            || ! $user instanceof User
            || ! $beneficiary instanceof Beneficiary) {
            throw new RuntimeException(
                'Nium transfer is missing its authoritative user, provider, or beneficiary.'
            );
        }

        $sourceCurrency = strtoupper(trim((string) $transfer->source_currency));
        $targetCurrency = strtoupper(trim((string) $transfer->target_currency));
        $crossCurrency = $sourceCurrency !== $targetCurrency;

        $this->assertCustomerAndBeneficiary(
            $user,
            $provider,
            $beneficiary,
            $targetCurrency,
        );

        if ($transfer->source_bank_account_id !== null) {
            throw new RuntimeException(
                'Nium wallet transfers do not accept a source bank account.'
            );
        }

        if ($transfer->transfer_type !== 'payout'
            || $sourceCurrency !== self::SOURCE_CURRENCY
            || $transfer->target_amount !== null
            || (float) $transfer->fee_amount !== 0.0
            || strtoupper((string) $transfer->fee_currency) !== self::SOURCE_CURRENCY
            || ! $this->purposeIsSupported(
                trim((string) $transfer->purpose_code),
                $authoritativePurposeCodes,
            )
            || ! filled(trim((string) $transfer->reference_text))
            || mb_strlen(trim((string) $transfer->reference_text)) > 255) {
            throw new RuntimeException(
                'Transfer does not match the supported Nium HK SWIFT payout policy.'
            );
        }

        if (! $crossCurrency) {
            if ($targetCurrency !== self::DESTINATION_CURRENCY
                || $transfer->fx_quote_id !== null
                || $transfer->fx_rate !== null) {
                throw new RuntimeException(
                    'Nium same-currency USD transfers must not contain an FX lock.'
                );
            }

            return;
        }

        if (! (bool) config('services.nium.payout_fx_enabled', false)) {
            throw new RuntimeException('Nium payout FX is not enabled.');
        }

        if ($transfer->fx_quote_id === null || ! is_numeric($transfer->fx_rate)) {
            throw new RuntimeException(
                'Nium cross-currency payout requires an authoritative payout FX lock.'
            );
        }
    }

    public function providerPayload(
        Transfer $transfer,
        array $authoritativePurposeCodes,
    ): array {
        $this->assertTransfer($transfer, $authoritativePurposeCodes);

        $sourceCurrency = strtoupper((string) $transfer->source_currency);
        $targetCurrency = strtoupper((string) $transfer->target_currency);
        $crossCurrency = $sourceCurrency !== $targetCurrency;

        $payout = [
            'sourceAmount' => (float) $transfer->source_amount,
            'sourceCurrency' => self::SOURCE_CURRENCY,
            'destinationCurrency' => $targetCurrency,
            'payoutMethod' => self::PAYOUT_METHOD,
            'tradeOrderID' => $transfer->reference_text,
            'swiftFeeType' => self::SWIFT_FEE_TYPE,
        ];

        if ($crossCurrency) {
            $quote = $transfer->fxQuote;

            if ($quote === null
                || ($quote->raw_data['provider_fx_type'] ?? null) !== 'payout_fx_lock'
                || ! is_numeric($quote->quote_ref)) {
                throw new RuntimeException(
                    'Nium cross-currency payout requires an authoritative payout FX audit ID.'
                );
            }

            $payout['auditId'] = (int) $quote->quote_ref;
        }

        return [
            'beneficiary' => [
                'id' => $transfer->beneficiary->external_beneficiary_id,
            ],
            'payout' => $payout,
            'purposeCode' => $transfer->purpose_code,
            'sourceOfFunds' => self::SOURCE_OF_FUNDS,
            'customerComments' => $transfer->reference_text,
        ];
    }

    private function purposeIsSupported(
        string $code,
        array $authoritativePurposeCodes,
    ): bool {
        return $code !== ''
            && collect($authoritativePurposeCodes)->contains('code', $code);
    }

    private function assertCustomerAndBeneficiary(
        User $user,
        IntegrationProvider $provider,
        Beneficiary $beneficiary,
        string $targetCurrency,
    ): void {
        $user->loadMissing('kycProfile');
        $profile = $user->kycProfile;

        if (! $profile instanceof KycProfile
            || strtolower((string) $profile->status) !== 'approved'
            || strtolower((string) $profile->applicant_type) !== 'business'
            || $this->customerRegion($profile) !== 'HK') {
            throw new RuntimeException(
                'Nium transfers currently require an HK corporate customer.'
            );
        }

        if ($beneficiary->user_id !== $user->id
            || $beneficiary->provider_id !== $provider->id) {
            throw new RuntimeException(
                'Nium beneficiary does not belong to the transfer user and provider.'
            );
        }

        if (strtolower((string) $beneficiary->status) !== 'active') {
            throw new RuntimeException('Nium beneficiary must be active.');
        }

        $payoutMethod = $this->payoutMethodResolver->resolve($beneficiary);

        if (strtoupper((string) $beneficiary->country_code) !== 'HK'
            || strtoupper((string) $beneficiary->currency) !== $targetCurrency
            || $payoutMethod !== self::PAYOUT_METHOD) {
            throw new RuntimeException(
                'Nium beneficiary does not match the requested HK SWIFT payout corridor.'
            );
        }

        if (! filled($beneficiary->external_beneficiary_id)) {
            throw new RuntimeException(
                'Nium transfer requires a synced beneficiary.'
            );
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
        if (strtolower(trim((string) ($values['transfer_type'] ?? ''))) !== 'payout') {
            throw new RuntimeException('Unsupported Nium transfer_type.');
        }

        if (strtoupper(trim((string) ($values['source_currency'] ?? ''))) !== self::SOURCE_CURRENCY) {
            throw new RuntimeException('Unsupported Nium source_currency.');
        }

        $targetCurrency = strtoupper(trim((string) ($values['target_currency'] ?? '')));

        if (strlen($targetCurrency) !== 3) {
            throw new RuntimeException('Unsupported Nium target_currency.');
        }

        if ($targetCurrency !== self::DESTINATION_CURRENCY
            && ! (bool) config('services.nium.payout_fx_enabled', false)) {
            throw new RuntimeException('Nium payout FX is not enabled.');
        }

        if (! is_string($values['purpose_code'] ?? null)
            || trim((string) $values['purpose_code']) === '') {
            throw new RuntimeException('Nium purpose_code is required.');
        }

        if (! filled(trim((string) ($values['reference_text'] ?? '')))) {
            throw new RuntimeException('Nium reference_text is required.');
        }

        if (filled($values['source_bank_account_id'] ?? null)) {
            throw new RuntimeException(
                'Nium wallet transfers do not accept a source bank account.'
            );
        }

        if (filled($values['fx_quote_id'] ?? null)) {
            throw new RuntimeException(
                'Nium payout FX locks are acquired by the server at submission time.'
            );
        }
    }

    private function safeClientMetadata(array $rawData): array
    {
        return array_filter([
            'source' => isset($rawData['source'])
                ? substr((string) $rawData['source'], 0, 50)
                : null,
            'flow' => isset($rawData['flow'])
                ? substr((string) $rawData['flow'], 0, 50)
                : null,
        ], static fn (mixed $value): bool => filled($value));
    }
}
