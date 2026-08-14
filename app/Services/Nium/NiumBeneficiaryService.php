<?php

namespace App\Services\Nium;

use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Services\Integrations\Contracts\BeneficiaryProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use RuntimeException;

class NiumBeneficiaryService implements BeneficiaryProvider
{
    public function __construct(
        private readonly NiumService $niumService,
        private readonly NiumSupportedCorridorService $corridorService,
        private readonly NiumBeneficiaryExecutionAuthorization $executionAuthorization,
        private readonly NiumBeneficiaryAccountResolver $accountResolver,
    ) {}

    private const BASE_FIELDS = ['beneficiaryAccountType', 'beneficiaryCountryCode', 'beneficiaryName', 'destinationCurrency', 'payoutMethod'];

    private const CONDITIONAL_FIELDS = [
        'beneficiaryAccountNumber', 'beneficiaryAddress', 'beneficiaryBankAccountType', 'beneficiaryBankCode',
        'beneficiaryBankName', 'beneficiaryCity', 'beneficiaryContactCountryCode', 'beneficiaryContactNumber',
        'beneficiaryEmail', 'beneficiaryIdentificationType', 'beneficiaryIdentificationValue', 'beneficiaryPostcode',
        'beneficiaryState', 'destinationCountry', 'proxyType', 'proxyValue', 'remitterBeneficiaryRelationship',
        'routingCodeType1', 'routingCodeValue1', 'routingCodeType2', 'routingCodeValue2',
    ];

    public function assertReadyForCreate(Beneficiary $beneficiary): void
    {
        $beneficiary->loadMissing('user.profile');
        $this->accountResolver->resolve($beneficiary->user, $beneficiary->provider_id);
        $this->validateCorridor($beneficiary);
        $this->assertSchemaProven($beneficiary);
        $this->buildBeneficiaryPayload($beneficiary);
    }

    public function createBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): Beneficiary
    {
        $this->assertReadyForCreate($beneficiary);
        $account = $this->accountResolver->resolve($beneficiary->user, $provider->id);
        $this->assertAccountSevenAuthorization($account->id, $beneficiary);
        if ($account->id === 7 && (bool) data_get($beneficiary->raw_data, 'nium.verify_before_create', false)) {
            throw new RuntimeException('HOLD_ACCOUNT_VERIFICATION_SEPARATE_APPROVAL_REQUIRED');
        }
        $payload = $this->buildBeneficiaryPayload($beneficiary);
        $this->verifyAccountIfRequested($beneficiary);

        $response = $this->niumService->post(
            path: $this->niumService->path(
                (string) config('services.nium.beneficiary_endpoint'),
                [
                    'client' => $this->niumService->clientId(),
                    'customer' => (string) $account->external_customer_id,
                ],
            ),
            payload: $payload,
            user: $beneficiary->user,
            operation: 'beneficiary_create',
            externalReference: (string) data_get($beneficiary->raw_data, 'nium.one_shot_claim.tuple_sha256'),
        );

        return $this->handleWriteResponse($provider, $beneficiary, $response, 'create', $payload);
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
                'raw_data' => $this->mergeProviderOutcome($beneficiary, $responseData),
            ]);

            throw new RuntimeException($responseData['message'] ?? 'Nium account verification failed.');
        }

        $responseData = $response->json() ?? ['raw' => $response->body()];

        $beneficiary->update([
            'raw_data' => $this->mergeProviderOutcome($beneficiary, $responseData),
        ]);
    }

    public function updateBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): Beneficiary
    {
        $beneficiary->loadMissing('user.profile');
        $this->accountResolver->resolve($beneficiary->user, $beneficiary->provider_id);
        $this->validateCorridor($beneficiary);

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

        $payload = $this->buildBeneficiaryPayload($beneficiary);
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
                'raw_data' => $this->mergeProviderOutcome($beneficiary, $responseData),
            ]);

            throw new RuntimeException("{$provider->name} beneficiary deletion failed.");
        }
    }

    private function buildBeneficiaryPayload(Beneficiary $beneficiary): array
    {
        $rawData = (array) ($beneficiary->raw_data ?? []);
        $nium = (array) ($rawData['nium'] ?? []);
        $conditional = array_merge([
            'beneficiaryContactCountryCode' => $nium['beneficiaryContactCountryCode'] ?? $nium['beneficiary_contact_country_code'] ?? null,
            'beneficiaryContactNumber' => $beneficiary->phone,
            'beneficiaryEmail' => $beneficiary->email,
            'destinationCountry' => $beneficiary->country_code,
            'beneficiaryAlias' => $nium['beneficiaryAlias'] ?? $nium['beneficiary_alias'] ?? Arr::get($nium, 'beneficiary.alias'),
            'beneficiaryPostcode' => $beneficiary->postal_code,
            'beneficiaryAddress' => $beneficiary->address_line1,
            'beneficiaryCity' => $beneficiary->city,
            'beneficiaryState' => $beneficiary->state,
            'remitterBeneficiaryRelationship' => $nium['remitterBeneficiaryRelationship']
                ?? $nium['remitter_beneficiary_relationship']
                ?? Arr::get($nium, 'beneficiary.remitterBeneficiaryRelationship'),
            'beneficiaryAccountNumber' => $beneficiary->account_number ?: $beneficiary->iban,
            'beneficiaryBankAccountType' => $this->bankAccountType($nium['beneficiaryBankAccountType'] ?? $nium['beneficiary_bank_account_type'] ?? null),
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

        $approvedFields = (array) Arr::get($nium, 'schema_approval.approved_fields', []);
        $payload = [
            'beneficiaryAccountType' => $this->beneficiaryAccountType($beneficiary),
            'beneficiaryCountryCode' => strtoupper((string) $beneficiary->country_code),
            'beneficiaryName' => $this->beneficiaryName($beneficiary),
            'destinationCurrency' => strtoupper((string) $beneficiary->currency),
            'payoutMethod' => $this->payoutMethod($nium),
        ];

        foreach ($approvedFields as $field) {
            if (array_key_exists($field, $conditional)) {
                $payload[$field] = $conditional[$field];
            }
        }

        foreach (self::BASE_FIELDS as $field) {
            if (! filled($payload[$field] ?? null)) {
                throw new RuntimeException("Nium beneficiary required base field [{$field}] is missing.");
            }
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

        if (! $response->successful() || ! filled($payload['beneficiaryHashId'] ?? $payload['id'] ?? $beneficiary->external_beneficiary_id)) {
            $definiteRejection = $response->clientError() && ! in_array($response->status(), [408, 429], true);
            $beneficiary->update([
                'status' => $definiteRejection ? 'rejected_no_retry' : 'outcome_unknown_no_retry',
                'raw_data' => $this->mergeProviderOutcome($beneficiary, $responseData),
            ]);

            throw new RuntimeException($responseData['message'] ?? "{$provider->name} beneficiary {$action} failed.");
        }

        $beneficiary->update([
            'external_beneficiary_id' => $payload['beneficiaryHashId'] ?? $payload['id'] ?? $beneficiary->external_beneficiary_id,
            'status' => $this->normalizeBeneficiaryStatus($payload['status'] ?? 'ACTIVE'),
            'raw_data' => $this->mergeProviderOutcome($beneficiary, $responseData),
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
                throw new RuntimeException('Nium beneficiary bank routing type must come from the supported corridor or validation schema.');
            }
            $items[] = ['type' => $nium['bankCodeType'], 'value' => $beneficiary->bank_code];
        }

        if (filled($beneficiary->branch_code)) {
            if (! filled($nium['branchCodeType'] ?? null)) {
                throw new RuntimeException('Nium beneficiary branch routing type must come from the supported corridor or validation schema.');
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
            'bankAccountType' => $this->bankAccountType($verification['bankAccountType']
                ?? $nium['bankAccountType']
                ?? $nium['bank_account_type']
                ?? null),
            'bankCode' => $beneficiary->bank_code,
            'payoutMethod' => strtoupper((string) (
                $verification['payoutMethod']
                ?? $nium['payoutMethod']
                ?? $nium['payout_method']
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

    private function validateCorridor(Beneficiary $beneficiary): void
    {
        $nium = (array) (($beneficiary->raw_data ?? [])['nium'] ?? []);
        $payoutMethod = $this->payoutMethod($nium);
        $routingTypes = array_values(array_filter(array_map(
            static fn (array $routing): ?string => isset($routing['type']) ? (string) $routing['type'] : null,
            $this->routingInfo($beneficiary, $nium),
        )));

        $this->corridorService->assertSupported($beneficiary, $payoutMethod, $routingTypes);
    }

    private function bankAccountType(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $value = trim((string) $value);
        if (! in_array($value, ['Current', 'Saving', 'Maestra', 'Checking'], true)) {
            throw new RuntimeException('Nium beneficiary bank account type must use an exact provider literal.');
        }

        return $value;
    }

    private function payoutMethod(array $nium): string
    {
        $value = $nium['payoutMethod'] ?? $nium['payout_method'] ?? null;
        if (! filled($value)) {
            throw new RuntimeException('Nium beneficiary payoutMethod must be explicit.');
        }

        $value = strtoupper(trim((string) $value));
        if (! in_array($value, ['LOCAL', 'SWIFT', 'WALLET', 'CASH', 'CARD', 'PROXY', 'CHECK'], true)) {
            throw new RuntimeException('Nium beneficiary payoutMethod is invalid.');
        }

        return $value;
    }

    private function assertSchemaProven(Beneficiary $beneficiary): void
    {
        $nium = (array) (($beneficiary->raw_data ?? [])['nium'] ?? []);
        $approval = (array) ($nium['schema_approval'] ?? []);
        if (! preg_match('/^[a-f0-9]{64}$/', (string) ($approval['schema_sha256'] ?? ''))
            || ! hash_equals((string) $approval['schema_sha256'], (string) ($nium['schema_sha256'] ?? ''))
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($approval['beneficiary_preparation_sha256'] ?? ''))
            || ! is_int($approval['schema_length'] ?? null) || $approval['schema_length'] <= 0
            || strtoupper((string) ($approval['currency_code'] ?? '')) !== strtoupper((string) $beneficiary->currency)
            || strtoupper((string) ($approval['destination_country'] ?? '')) !== strtoupper((string) $beneficiary->country_code)
            || strtoupper((string) ($approval['payout_method'] ?? '')) !== $this->payoutMethod($nium)
            || ! is_array($approval['approved_fields'] ?? null)
            || ! is_array($approval['required_fields'] ?? null)
            || ! filled($approval['reviewed_at'] ?? null)
            || ($approval['review_source'] ?? null) !== 'human_reviewed_factual_nium_schema') {
            throw new RuntimeException('HOLD_BENEFICIARY_SCHEMA_NOT_PROVEN');
        }

        $knownFields = [...self::BASE_FIELDS, ...self::CONDITIONAL_FIELDS];
        foreach ([...$approval['approved_fields'], ...$approval['required_fields']] as $field) {
            if (! is_string($field) || ! in_array($field, $knownFields, true)) {
                throw new RuntimeException('HOLD_BENEFICIARY_SCHEMA_NOT_PROVEN');
            }
        }

        foreach ($approval['required_fields'] as $field) {
            if (! in_array($field, self::BASE_FIELDS, true) && ! in_array($field, $approval['approved_fields'], true)) {
                throw new RuntimeException('HOLD_BENEFICIARY_SCHEMA_NOT_PROVEN');
            }
            if (! filled($this->buildBeneficiaryPayload($beneficiary)[$field] ?? null)) {
                throw new RuntimeException("Nium beneficiary required field [{$field}] is missing.");
            }
        }

        if (! hash_equals((string) $approval['beneficiary_preparation_sha256'], $this->preparationFingerprint($beneficiary))) {
            throw new RuntimeException('HOLD_BENEFICIARY_PREPARATION_CHANGED');
        }
    }

    private function assertAccountSevenAuthorization(int $accountId, Beneficiary $beneficiary): void
    {
        if ($accountId !== 7) {
            return;
        }

        $claim = (array) data_get($beneficiary->raw_data, 'nium.one_shot_claim', []);
        $schemaSha = (string) data_get($beneficiary->raw_data, 'nium.schema_approval.schema_sha256', '');
        $preparationSha = $this->preparationFingerprint($beneficiary);
        if (($claim['state'] ?? null) !== 'submitting'
            || ! $this->executionAuthorization->allows(7, $beneficiary->id, (string) ($claim['tuple_sha256'] ?? ''), $schemaSha, $preparationSha)) {
            throw new RuntimeException('HOLD_ACCOUNT_7_BENEFICIARY_ONE_SHOT_REQUIRED');
        }
    }

    public function preparationFingerprint(Beneficiary $beneficiary): string
    {
        $payload = $this->buildBeneficiaryPayload($beneficiary);
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function mergeProviderOutcome(Beneficiary $beneficiary, array $data): array
    {
        $raw = (array) $beneficiary->raw_data;
        $raw['nium']['provider_outcome'] = $this->safeOperationalData($data);

        return $raw;
    }
}
