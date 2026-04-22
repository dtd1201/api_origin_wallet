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
    ) {
    }

    public function createBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): Beneficiary
    {
        $beneficiary->loadMissing('user');
        $this->verifyAccountIfRequested($beneficiary);

        $payload = $this->buildBeneficiaryPayload($beneficiary);
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
                'raw_data' => array_merge($beneficiary->raw_data ?? [], [
                    'verification_request' => $payload,
                    'verification_error' => $responseData,
                ]),
            ]);

            throw new RuntimeException($responseData['message'] ?? 'Nium account verification failed.');
        }

        $responseData = $response->json() ?? ['raw' => $response->body()];

        $beneficiary->update([
            'raw_data' => array_merge($beneficiary->raw_data ?? [], [
                'verification_request' => $payload,
                'verification_response' => $responseData,
            ]),
        ]);
    }

    public function updateBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): Beneficiary
    {
        $beneficiary->loadMissing('user');

        if (! filled($beneficiary->external_beneficiary_id)) {
            return $this->createBeneficiary($provider, $beneficiary);
        }

        $endpoint = (string) config('services.nium.beneficiary_update_endpoint', '');

        if ($endpoint === '') {
            throw new RuntimeException('Nium beneficiary update is not enabled. Configure NIUM_BENEFICIARY_UPDATE_ENDPOINT when the exact endpoint is confirmed.');
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
                'raw_data' => array_merge($beneficiary->raw_data ?? [], [
                    'delete_error' => $responseData,
                ]),
            ]);

            throw new RuntimeException("{$provider->name} beneficiary deletion failed.");
        }
    }

    private function buildBeneficiaryPayload(Beneficiary $beneficiary): array
    {
        $rawData = (array) ($beneficiary->raw_data ?? []);
        $nium = (array) ($rawData['nium'] ?? []);
        $routingInfo = $this->routingInfo($beneficiary, $nium);
        $payload = [
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
                'alias' => $nium['beneficiary']['alias'] ?? null,
                'remitterBeneficiaryRelationship' => $nium['beneficiary']['remitterBeneficiaryRelationship'] ?? null,
            ], static fn ($value) => $value !== null && $value !== ''),
            'destinationCountry' => $beneficiary->country_code,
            'destinationCurrency' => $beneficiary->currency,
            'payoutMethod' => strtoupper((string) ($nium['payoutMethod'] ?? $nium['payout_method'] ?? 'LOCAL')),
            'accountNumber' => $beneficiary->account_number ?: $beneficiary->iban,
            'bankAccountType' => $nium['bankAccountType'] ?? $nium['bank_account_type'] ?? 'CHECKING',
            'bankCode' => $beneficiary->bank_code,
            'routingInfo' => $routingInfo,
        ];

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

        if (! $response->successful() || ! filled($payload['beneficiaryHashId'] ?? $payload['id'] ?? $beneficiary->external_beneficiary_id)) {
            $beneficiary->update([
                'status' => "{$action}_failed",
                'raw_data' => array_merge($beneficiary->raw_data ?? [], [
                    "{$action}_request" => $requestPayload,
                    "{$action}_error" => $responseData,
                ]),
            ]);

            throw new RuntimeException($responseData['message'] ?? "{$provider->name} beneficiary {$action} failed.");
        }

        $beneficiary->update([
            'external_beneficiary_id' => $payload['beneficiaryHashId'] ?? $payload['id'] ?? $beneficiary->external_beneficiary_id,
            'status' => $this->normalizeBeneficiaryStatus($payload['status'] ?? 'ACTIVE'),
            'raw_data' => array_merge($beneficiary->raw_data ?? [], [
                "{$action}_request" => $requestPayload,
                "{$action}_response" => $responseData,
            ]),
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
            $items[] = ['type' => $nium['bankCodeType'] ?? 'BANKCODE', 'value' => $beneficiary->bank_code];
        }

        if (filled($beneficiary->branch_code)) {
            $items[] = ['type' => $nium['branchCodeType'] ?? 'BRANCHCODE', 'value' => $beneficiary->branch_code];
        }

        return $items;
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
            'bankAccountType' => $verification['bankAccountType']
                ?? $nium['bankAccountType']
                ?? $nium['bank_account_type']
                ?? 'CHECKING',
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
}
