<?php

namespace App\Services\Nium;

use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Services\Integrations\Contracts\BeneficiaryProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class NiumBeneficiaryService implements BeneficiaryProvider
{
    public function __construct(
        private readonly NiumService $niumService,
        private readonly NiumSupportedCorridorService $corridorService,
        private readonly NiumProviderAccountStateService $accountStateService,
        private readonly NiumBeneficiaryPreflightValidator $preflightValidator,
        private readonly NiumBeneficiaryRequirementsService $requirementsService,
    ) {}

    public function requirements(Beneficiary $beneficiary): array
    {
        $beneficiary->loadMissing('user.profile');
        $this->accountStateService->assertEligible($beneficiary->user);
        $nium = (array) data_get($beneficiary->raw_data, 'nium', []);
        $corridor = $this->corridorService->assertSupported($beneficiary, (string) ($nium['payoutMethod'] ?? $nium['payout_method'] ?? 'SWIFT'), []);

        try {
            $schema = $this->requirementsService->requirements($beneficiary->user, $corridor);
            $this->requirementsService->assertMatchesCorridor($schema, $corridor);
        } catch (RuntimeException|ConnectionException) {
            throw ValidationException::withMessages(['raw_data.nium' => 'Beneficiary requirements are unavailable. Retry before saving.']);
        }

        return [$corridor, $schema];
    }

    public function createBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): Beneficiary
    {
        [$corridor, $schema] = $this->requirements($beneficiary);

        return $this->createValidatedBeneficiary($provider, $beneficiary, $corridor, $schema);
    }

    public function createValidatedBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary, array $selectedCorridor, NiumBeneficiaryRequirementsResult $schema): Beneficiary
    {
        $beneficiary->loadMissing('user.profile');
        $this->accountStateService->assertEligible($beneficiary->user);
        $this->requirementsService->assertMatchesCorridor($schema, $selectedCorridor);
        $payload = $this->validatedPayload($beneficiary, $schema);
        $this->verifyAccountIfRequested($beneficiary);

        $response = $this->niumService->post(
            path: $this->niumService->path(
                (string) config('services.nium.beneficiary_endpoint'),
                [
                    'client' => $this->niumService->clientId(),
                    'customer' => $this->niumService->customerId($beneficiary->user),
                ],
            ),
            payload: $payload,
            user: $beneficiary->user,
            operation: 'beneficiary_create',
            externalReference: (string) $beneficiary->id,
        );

        return $this->handleWriteResponse($provider, $beneficiary, $response, 'create', $payload);
    }

    private function validatedPayload(Beneficiary $beneficiary, NiumBeneficiaryRequirementsResult $schema): array
    {
        $corridor = $this->validateCorridor($beneficiary, $schema);
        $payload = $this->buildBeneficiaryPayload($beneficiary);
        foreach ((array) data_get($beneficiary->raw_data, 'nium.additionalFields', []) as $name => $value) {
            $field = collect($schema->fields)->firstWhere('name', $name);
            if ($field === null || in_array($name, ['clientLegalEntity', ...array_keys($schema->dimensions)], true)
                || str_starts_with($name, 'routingCode') || array_key_exists($name, $payload)
                || ($schema->routingCodeTypes === ['SWIFT'] && in_array($name, ['beneficiaryBankCode', 'beneficiaryBranchCode'], true))) {
                throw ValidationException::withMessages(["raw_data.nium.additionalFields.{$name}" => 'This field cannot override the selected beneficiary details.']);
            }
            if ($value === null || $value === '') {
                continue;
            }
            $payload[$name] = match ($field['type'] ?? 'string') {
                'boolean' => match ($value) {
                    'true' => true, 'false' => false, default => $value
                },
                'integer' => preg_match('/^-?\d+$/', (string) $value) ? (int) $value : $value,
                'number' => is_numeric($value) ? (float) $value : $value,
                default => $value,
            };
        }
        $evidence = $this->preflightValidator->validate([
            ...$payload, 'customerType' => $schema->dimensions['customerType'],
        ], $corridor, $schema);
        $raw = (array) ($beneficiary->raw_data ?? []);
        $raw['nium']['beneficiary_preflight'] = $evidence;
        $beneficiary->raw_data = $raw;

        return $payload;
    }

    private function verifyAccountIfRequested(Beneficiary $beneficiary): void
    {
        $rawData = (array) ($beneficiary->raw_data ?? []);
        $nium = (array) ($rawData['nium'] ?? []);
        $shouldVerify = (bool) ($nium['verify_before_create'] ?? false);

        if (! $shouldVerify) {
            return;
        }

        if (! (bool) config('services.nium.account_verification_enabled', false)) {
            throw new RuntimeException('Nium account verification is not enabled for this client entitlement.');
        }

        $payload = $this->buildAccountVerificationPayload($beneficiary, $nium);
        $response = $this->niumService->post(
            path: $this->niumService->path(
                (string) config('services.nium.account_verification_endpoint'),
                [
                    'client' => $this->niumService->clientId(),
                    'customer' => $this->niumService->customerId($beneficiary->user),
                ],
            ),
            payload: $payload,
            user: $beneficiary->user,
        );

        if (! $response->successful()) {
            $responseData = $response->json() ?? ['raw' => $response->body()];

            $beneficiary->update([
                'status' => 'verification_failed',
                'raw_data' => $this->mergeSafeOperationalData($beneficiary, $responseData),
            ]);

            throw new RuntimeException($responseData['message'] ?? 'Nium account verification failed.');
        }

        $responseData = $response->json() ?? ['raw' => $response->body()];

        $beneficiary->update([
            'raw_data' => $this->mergeSafeOperationalData($beneficiary, $responseData),
        ]);
    }

    public function updateBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): Beneficiary
    {
        $beneficiary->loadMissing('user.profile');
        $this->accountStateService->assertEligible($beneficiary->user);

        if (! filled($beneficiary->external_beneficiary_id)) {
            return $this->createBeneficiary($provider, $beneficiary);
        }

        $endpoint = (string) config('services.nium.beneficiary_update_endpoint', '');

        if ($endpoint === '') {
            throw new RuntimeException('Nium beneficiary update is not enabled. Configure NIUM_BENEFICIARY_UPDATE_ENDPOINT when the exact endpoint is confirmed.');
        }
        if (! (bool) config('services.nium.beneficiary_update_enabled', false)) {
            throw new RuntimeException('Nium beneficiary update is not enabled for this client entitlement.');
        }

        [, $schema] = $this->requirements($beneficiary);
        $payload = $this->validatedPayload($beneficiary, $schema);
        $response = $this->niumService->put(
            path: $this->niumService->path(
                $endpoint,
                [
                    'client' => $this->niumService->clientId(),
                    'customer' => $this->niumService->customerId($beneficiary->user),
                    'beneficiary' => $beneficiary->external_beneficiary_id,
                ],
            ),
            payload: $payload,
            user: $beneficiary->user,
        );

        return $this->handleWriteResponse($provider, $beneficiary, $response, 'update', $payload);
    }

    public function deleteBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): void
    {
        $beneficiary->loadMissing('user');

        if (! filled($beneficiary->external_beneficiary_id)) {
            return;
        }

        $endpoint = (string) config('services.nium.beneficiary_delete_endpoint', '');

        if ($endpoint === '') {
            throw new RuntimeException('Nium beneficiary delete is not enabled. Configure NIUM_BENEFICIARY_DELETE_ENDPOINT when the exact endpoint is confirmed.');
        }
        if (! (bool) config('services.nium.beneficiary_delete_enabled', false)) {
            throw new RuntimeException('Nium beneficiary delete is not enabled for this client entitlement.');
        }

        $response = $this->niumService->delete(
            path: $this->niumService->path(
                $endpoint,
                [
                    'client' => $this->niumService->clientId(),
                    'customer' => $this->niumService->customerId($beneficiary->user),
                    'beneficiary' => $beneficiary->external_beneficiary_id,
                ],
            ),
            user: $beneficiary->user,
        );

        if (! $response->successful()) {
            $responseData = $response->json() ?? ['raw' => $response->body()];

            $beneficiary->update([
                'status' => 'delete_failed',
                'raw_data' => $this->safeOperationalData($responseData),
            ]);

            throw new RuntimeException("{$provider->name} beneficiary deletion failed.");
        }
    }

    private function buildBeneficiaryPayload(Beneficiary $beneficiary): array
    {
        $rawData = (array) ($beneficiary->raw_data ?? []);
        $nium = (array) ($rawData['nium'] ?? []);
        $payload = array_merge([
            'clientLegalEntity' => 'HK',
            'beneficiaryContactCountryCode' => $nium['beneficiaryContactCountryCode'] ?? $nium['beneficiary_contact_country_code'] ?? null,
            'beneficiaryContactNumber' => $beneficiary->phone,
            'beneficiaryAccountType' => $this->beneficiaryAccountType($beneficiary),
            'beneficiaryEmail' => $beneficiary->email,
            'destinationCountry' => $beneficiary->country_code,
            'destinationCurrency' => $beneficiary->currency,
            'payoutMethod' => strtoupper((string) ($nium['payoutMethod'] ?? $nium['payout_method'] ?? 'SWIFT')),
            'beneficiaryName' => $this->beneficiaryName($beneficiary),
            'beneficiaryAlias' => $nium['beneficiaryAlias'] ?? $nium['beneficiary_alias'] ?? Arr::get($nium, 'beneficiary.alias'),
            'beneficiaryPostcode' => $beneficiary->postal_code,
            'beneficiaryAddress' => $beneficiary->address_line1,
            'beneficiaryCity' => $beneficiary->city,
            'beneficiaryCountryCode' => $beneficiary->country_code,
            'beneficiaryState' => $beneficiary->state,
            'remitterBeneficiaryRelationship' => $nium['remitterBeneficiaryRelationship']
                ?? $nium['remitter_beneficiary_relationship']
                ?? Arr::get($nium, 'beneficiary.remitterBeneficiaryRelationship'),
            'beneficiaryAccountNumber' => $beneficiary->account_number ?: $beneficiary->iban,
            'beneficiaryBankAccountType' => $nium['beneficiaryBankAccountType'] ?? null,
            'beneficiaryBankName' => $beneficiary->bank_name,
            'beneficiaryBankCode' => $beneficiary->bank_code,
            'beneficiaryIdentificationType' => $nium['beneficiaryIdentificationType'] ?? $nium['beneficiary_identification_type'] ?? null,
            'beneficiaryIdentificationValue' => $nium['beneficiaryIdentificationValue'] ?? $nium['beneficiary_identification_value'] ?? null,
            'proxyType' => $nium['proxyType'] ?? $nium['proxy_type'] ?? null,
            'proxyValue' => $nium['proxyValue'] ?? $nium['proxy_value'] ?? null,
            'beneficiaryCardIssuerName' => $nium['beneficiaryCardIssuerName'] ?? $nium['beneficiary_card_issuer_name'] ?? null,
            'beneficiaryCardExpiryDate' => $nium['beneficiaryCardExpiryDate'] ?? $nium['beneficiary_card_expiry_date'] ?? null,
            'authenticationCode' => $nium['authenticationCode'] ?? $nium['authentication_code'] ?? null,
            'encryptedBeneficiaryCardToken' => $nium['encryptedBeneficiaryCardToken'] ?? $nium['encrypted_beneficiary_card_token'] ?? null,
            'convertDestinationCurrency' => $nium['convertDestinationCurrency'] ?? $nium['convert_destination_currency'] ?? null,
            'autoSweepPayoutAccount' => $nium['autoSweepPayoutAccount'] ?? $nium['auto_sweep_payout_account'] ?? null,
            'defaultAutoSweepPayoutAccount' => $nium['defaultAutoSweepPayoutAccount'] ?? $nium['default_auto_sweep_payout_account'] ?? null,
            'beneficiaryContactName' => $nium['beneficiaryContactName'] ?? $nium['beneficiary_contact_name'] ?? null,
            'beneficiaryEntityType' => $nium['beneficiaryEntityType'] ?? $nium['beneficiary_entity_type'] ?? null,
            'beneficiaryDob' => $nium['beneficiaryDob'] ?? $nium['beneficiary_dob'] ?? null,
            'beneficiaryEstablishmentDate' => $nium['beneficiaryEstablishmentDate'] ?? $nium['beneficiary_establishment_date'] ?? null,
            'beneficiaryName_local' => $nium['beneficiaryName_local'] ?? $nium['beneficiary_name_local'] ?? null,
        ], $this->routingCodePayload($beneficiary, $nium));

        if (isset($nium['request']) && is_array($nium['request'])) {
            $payload = array_replace_recursive($payload, $nium['request']);
        }

        return array_filter($payload, static fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    private function handleWriteResponse(
        IntegrationProvider $provider,
        Beneficiary $beneficiary,
        Response $response,
        string $action,
        array $requestPayload,
    ): Beneficiary {
        $responseData = $response->json() ?? ['raw' => $response->body()];
        $payload = $this->beneficiaryResponsePayload($responseData);
        $providerBeneficiaryId = $payload['beneficiaryHashId'] ?? $payload['id'] ?? null;
        $resolvedBeneficiaryId = $action === 'create'
            ? $providerBeneficiaryId
            : ($providerBeneficiaryId ?? $beneficiary->external_beneficiary_id);

        if (! $response->successful() || ! filled($resolvedBeneficiaryId)) {
            $beneficiary->update([
                'status' => "{$action}_failed",
                'raw_data' => $this->mergeSafeOperationalData($beneficiary, $responseData),
            ]);

            throw new RuntimeException($responseData['message'] ?? "{$provider->name} beneficiary {$action} failed.");
        }

        $beneficiary->update([
            'external_beneficiary_id' => $resolvedBeneficiaryId,
            'status' => $this->normalizeBeneficiaryStatus($payload['status'] ?? 'ACTIVE'),
            'payout_method' => strtoupper((string) $requestPayload['payoutMethod']),
            'raw_data' => $this->mergeSuccessfulWriteData($beneficiary, $responseData, $requestPayload),
        ]);

        return $beneficiary->fresh();
    }

    private function routingInfo(Beneficiary $beneficiary, array $nium): array
    {
        $routingInfo = $nium['routingInfo'] ?? $nium['routing_info'] ?? null;

        if (is_array($routingInfo) && $routingInfo !== []) {
            return array_values(array_filter($routingInfo, 'is_array'));
        }

        $items = [];

        if (filled($beneficiary->swift_bic)) {
            $items[] = ['type' => 'SWIFT', 'value' => $beneficiary->swift_bic];
        }

        if (filled($beneficiary->bank_code)) {
            if (! filled($nium['bankCodeType'] ?? null)) {
                throw ValidationException::withMessages(['bank_code' => 'The bank code needs a routing type supported by the selected Nium corridor.']);
            }
            $items[] = ['type' => $nium['bankCodeType'], 'value' => $beneficiary->bank_code];
        }

        if (filled($beneficiary->branch_code)) {
            if (! filled($nium['branchCodeType'] ?? null)) {
                throw ValidationException::withMessages(['branch_code' => 'The branch code needs a routing type supported by the selected Nium corridor.']);
            }
            $items[] = ['type' => $nium['branchCodeType'], 'value' => $beneficiary->branch_code];
        }

        return $items;
    }

    private function routingCodePayload(Beneficiary $beneficiary, array $nium): array
    {
        $routingInfo = $this->routingInfo($beneficiary, $nium);
        $payload = [];

        foreach (array_slice($routingInfo, 0, 2) as $index => $routing) {
            $number = $index + 1;
            $payload["routingCodeType{$number}"] = $routing['type'] ?? null;
            $payload["routingCodeValue{$number}"] = $routing['value'] ?? null;
        }

        return $payload;
    }

    private function beneficiaryAccountType(Beneficiary $beneficiary): string
    {
        return $this->accountType($beneficiary) === 'BUSINESS' ? 'Corporate' : 'Individual';
    }

    private function beneficiaryResponsePayload(array $responseData): array
    {
        $payload = Arr::get($responseData, 'data')
            ?? Arr::get($responseData, 'beneficiary')
            ?? $responseData;

        return is_array($payload) ? $payload : [];
    }

    private function accountType(Beneficiary $beneficiary): string
    {
        return in_array(strtolower((string) $beneficiary->beneficiary_type), ['company', 'business', 'corporate'], true)
            ? 'BUSINESS'
            : 'INDIVIDUAL';
    }

    private function beneficiaryName(Beneficiary $beneficiary): string
    {
        return $this->accountType($beneficiary) === 'BUSINESS'
            ? (string) ($beneficiary->company_name ?: $beneficiary->full_name)
            : (string) $beneficiary->full_name;
    }

    private function normalizeBeneficiaryStatus(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            'ACTIVE', 'APPROVED', 'COMPLETED' => 'active',
            'FAILED', 'REJECTED', 'ERROR' => 'failed',
            'UNDER_REVIEW', 'PENDING', 'PROCESSING' => 'pending',
            default => strtolower((string) ($status ?? 'pending')),
        };
    }

    private function buildAccountVerificationPayload(Beneficiary $beneficiary, array $nium): array
    {
        $verification = (array) ($nium['account_verification'] ?? []);
        $payload = [
            'destinationCurrency' => $beneficiary->currency,
            'destinationCountry' => $beneficiary->country_code,
            'beneficiary' => array_filter([
                'name' => $this->beneficiaryName($beneficiary),
                'accountType' => $this->accountType($beneficiary),
                'countryCode' => $beneficiary->country_code,
                'email' => $beneficiary->email,
                'contactNumber' => $beneficiary->phone,
                'address' => $beneficiary->address_line1,
                'city' => $beneficiary->city,
                'state' => $beneficiary->state,
                'postcode' => $beneficiary->postal_code,
                'alias' => Arr::get($verification, 'beneficiary.alias'),
                'remitterBeneficiaryRelationship' => Arr::get($verification, 'beneficiary.remitterBeneficiaryRelationship')
                    ?? Arr::get($nium, 'beneficiary.remitterBeneficiaryRelationship'),
            ], static fn ($value) => $value !== null && $value !== ''),
            'accountNumber' => $beneficiary->account_number ?: $beneficiary->iban,
            'bankAccountType' => $this->normalizeBankAccountType($verification['bankAccountType']
                ?? $nium['bankAccountType']
                ?? $nium['bank_account_type']
                ?? 'CHECKING'),
            'bankCode' => $beneficiary->bank_code,
            'payoutMethod' => strtoupper((string) (
                $verification['payoutMethod']
                ?? $nium['payoutMethod']
                ?? $nium['payout_method']
                ?? 'LOCAL'
            )),
            'proxyType' => $verification['proxyType'] ?? null,
            'proxyValue' => $verification['proxyValue'] ?? null,
            'routingInfo' => $verification['routingInfo'] ?? $this->routingInfo($beneficiary, $nium),
        ];

        if (isset($verification['request']) && is_array($verification['request'])) {
            $payload = array_replace_recursive($payload, $verification['request']);
        }

        return array_filter($payload, static fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    private function safeOperationalData(array $data): array
    {
        return array_filter([
            'provider_request_id' => $data['requestId'] ?? $data['request_id'] ?? null,
            'provider_status' => $data['status'] ?? null,
            'provider_error_code' => $data['code'] ?? $data['errorCode'] ?? null,
            'beneficiary_id' => $data['beneficiaryHashId'] ?? Arr::get($data, 'data.beneficiaryHashId'),
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function mergeSafeOperationalData(Beneficiary $beneficiary, array $responseData): array
    {
        return array_replace((array) ($beneficiary->raw_data ?? []), $this->safeOperationalData($responseData));
    }

    private function mergeSuccessfulWriteData(Beneficiary $beneficiary, array $responseData, array $requestPayload): array
    {
        $rawData = $this->mergeSafeOperationalData($beneficiary, $responseData);
        $nium = (array) ($rawData['nium'] ?? []);
        $nium['provider_verified'] = [
            'payout_method' => strtoupper((string) $requestPayload['payoutMethod']),
        ];
        $rawData['nium'] = $nium;

        return $rawData;
    }

    private function validateCorridor(Beneficiary $beneficiary, ?NiumBeneficiaryRequirementsResult $schema = null): array
    {
        $nium = (array) (($beneficiary->raw_data ?? [])['nium'] ?? []);
        $payoutMethod = strtoupper((string) ($nium['payoutMethod'] ?? $nium['payout_method'] ?? 'SWIFT'));
        // Select the corridor before attempting to construct typed routing codes.
        $corridor = $this->corridorService->assertSupported($beneficiary, $payoutMethod, []);
        if ($schema !== null) {
            $this->requirementsService->assertMatchesCorridor($schema, $corridor);
        }
        $allowed = array_values(array_unique(array_filter(array_map(
            static fn ($type): string => strtoupper(trim((string) $type)),
            (array) ($corridor['routingCodeType'] ?? []),
        ))));

        if ($allowed === ['SWIFT']) {
            foreach (['bank_code', 'branch_code'] as $field) {
                if (filled($beneficiary->{$field})) {
                    throw ValidationException::withMessages([$field => 'Bank and branch codes are not supported by this corridor. Use SWIFT / BIC routing.']);
                }
            }
        }

        $explicitRouting = $nium['routingInfo'] ?? $nium['routing_info'] ?? [];
        if (! is_array($explicitRouting) || $explicitRouting === []) {
            $used = filled($beneficiary->swift_bic) ? ['SWIFT'] : [];
            foreach (['bank_code' => 'bankCodeType', 'branch_code' => 'branchCodeType'] as $field => $key) {
                if (filled($beneficiary->{$field}) && filled($nium[$key] ?? null)) {
                    $used[] = strtoupper(trim((string) $nium[$key]));
                }
            }

            $slot = filled($beneficiary->swift_bic) ? 2 : 1;
            foreach (['bank_code' => 'bankCodeType', 'branch_code' => 'branchCodeType'] as $field => $key) {
                if (! filled($beneficiary->{$field})) {
                    continue;
                }
                $candidates = array_values(array_diff($allowed, $used));
                $schemaField = collect($schema?->fields ?? [])->firstWhere('name', "routingCodeType{$slot}");
                if (isset($schemaField['allowed_values'])) {
                    $candidates = array_values(array_intersect($candidates, $schemaField['allowed_values']));
                }
                if (! filled($nium[$key] ?? null)) {
                    if (count($candidates) !== 1) {
                        throw ValidationException::withMessages([
                            $field => 'The selected Nium corridor does not identify a unique supported routing type for this code. Supply an explicitly supported routing type.',
                        ]);
                    }
                    $nium[$key] = $candidates[0];
                    $used[] = $nium[$key];
                }
                $nium[$key] = strtoupper(trim((string) $nium[$key]));
                $slot++;
            }
        }

        $routingInfo = $this->routingInfo($beneficiary, $nium);
        foreach (['bank_code', 'branch_code'] as $field) {
            if (filled($beneficiary->{$field}) && ! in_array($beneficiary->{$field}, array_column($routingInfo, 'value'), true)) {
                throw ValidationException::withMessages([$field => 'The supplied code must have a routing type supported by the selected Nium corridor.']);
            }
        }
        foreach ($routingInfo as $index => $routing) {
            $field = ($routing['value'] ?? null) === $beneficiary->bank_code ? 'bank_code'
                : ((($routing['value'] ?? null) === $beneficiary->branch_code) ? 'branch_code' : 'routingCodeType'.($index + 1));
            $type = $routing['type'] ?? null;
            $schemaField = collect($schema?->fields ?? [])->firstWhere('name', 'routingCodeType'.($index + 1));
            if ($index >= 2 || ! in_array($type, $allowed, true)
                || ! filled($routing['value'] ?? null)
                || (isset($schemaField['allowed_values']) && ! in_array($type, $schemaField['allowed_values'], true))) {
                throw ValidationException::withMessages([$field => 'The routing code type is not supported by the selected Nium corridor or validation schema.']);
            }
        }

        $raw = (array) ($beneficiary->raw_data ?? []);
        $raw['nium'] = $nium;
        $beneficiary->raw_data = $raw;

        return $corridor;
    }

    private function normalizeBankAccountType(mixed $value): string
    {
        return match (strtoupper(trim((string) $value))) {
            'CURRENT' => 'CURRENT',
            'SAVINGS', 'SAVING' => 'SAVINGS',
            default => 'CHECKING',
        };
    }
}
