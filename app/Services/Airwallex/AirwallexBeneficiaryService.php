<?php

namespace App\Services\Airwallex;

use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Services\Integrations\Contracts\BeneficiaryProvider;
use Illuminate\Http\Client\Response;
use RuntimeException;

class AirwallexBeneficiaryService implements BeneficiaryProvider
{
    public function __construct(
        private readonly AirwallexService $airwallexService,
    ) {
    }

    public function createBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): Beneficiary
    {
        $payload = $this->buildBeneficiaryPayload($beneficiary);
        $response = $this->airwallexService->post(
            path: (string) config('services.airwallex.beneficiary_endpoint'),
            payload: $payload,
            user: $beneficiary->user,
        );

        return $this->handleWriteResponse($provider, $beneficiary, $response, 'create', $payload);
    }

    public function updateBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): Beneficiary
    {
        if (! filled($beneficiary->external_beneficiary_id)) {
            return $this->createBeneficiary($provider, $beneficiary);
        }

        $payload = $this->buildBeneficiaryPayload($beneficiary);
        $response = $this->airwallexService->put(
            path: $this->beneficiaryResourcePath(
                (string) config('services.airwallex.beneficiary_update_endpoint'),
                (string) $beneficiary->external_beneficiary_id,
            ),
            payload: $payload,
            user: $beneficiary->user,
        );

        return $this->handleWriteResponse($provider, $beneficiary, $response, 'update', $payload);
    }

    public function deleteBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): void
    {
        if (! filled($beneficiary->external_beneficiary_id)) {
            return;
        }

        $response = $this->airwallexService->delete(
            path: $this->beneficiaryResourcePath(
                (string) config('services.airwallex.beneficiary_delete_endpoint'),
                (string) $beneficiary->external_beneficiary_id,
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
        $airwallex = (array) ($rawData['airwallex'] ?? []);
        $entityType = $this->entityType($beneficiary);
        [$firstName, $lastName] = $this->splitName($beneficiary->full_name);

        $payload = array_filter([
            'nickname' => $beneficiary->full_name,
            'transfer_methods' => $airwallex['transfer_methods'] ?? [strtoupper((string) ($airwallex['transfer_method'] ?? 'LOCAL'))],
            'beneficiary' => array_filter([
                'type' => 'BANK_ACCOUNT',
                'entity_type' => $entityType,
                'company_name' => $entityType === 'COMPANY' ? ($beneficiary->company_name ?: $beneficiary->full_name) : null,
                'first_name' => $entityType === 'PERSONAL' ? $firstName : null,
                'last_name' => $entityType === 'PERSONAL' ? $lastName : null,
                'address' => array_filter([
                    'country_code' => $beneficiary->country_code,
                    'state' => $beneficiary->state,
                    'city' => $beneficiary->city,
                    'postcode' => $beneficiary->postal_code,
                    'street_address' => trim(implode(' ', array_filter([
                        $beneficiary->address_line1,
                        $beneficiary->address_line2,
                    ]))),
                ], static fn ($value) => $value !== null && $value !== ''),
                'bank_details' => array_filter([
                    'account_currency' => $beneficiary->currency,
                    'account_name' => $beneficiary->full_name,
                    'account_number' => $beneficiary->account_number,
                    'iban' => $beneficiary->iban,
                    'swift_code' => $beneficiary->swift_bic,
                    'bank_name' => $beneficiary->bank_name,
                    'bank_country_code' => $beneficiary->country_code,
                    'local_clearing_system' => $airwallex['local_clearing_system'] ?? null,
                    'account_routing_type1' => $airwallex['account_routing_type1'] ?? null,
                    'account_routing_value1' => $beneficiary->bank_code ?: ($airwallex['account_routing_value1'] ?? null),
                    'account_routing_type2' => $airwallex['account_routing_type2'] ?? null,
                    'account_routing_value2' => $beneficiary->branch_code ?: ($airwallex['account_routing_value2'] ?? null),
                    'bank_account_category' => $airwallex['bank_account_category'] ?? null,
                ], static fn ($value) => $value !== null && $value !== ''),
                'additional_info' => array_filter([
                    'personal_email' => $beneficiary->email,
                    'business_phone_number' => $beneficiary->phone,
                ], static fn ($value) => $value !== null && $value !== ''),
            ], static fn ($value) => $value !== null && $value !== [] && $value !== ''),
        ], static fn ($value) => $value !== null && $value !== [] && $value !== '');

        return $payload;
    }

    private function handleWriteResponse(
        IntegrationProvider $provider,
        Beneficiary $beneficiary,
        Response $response,
        string $action,
        array $requestPayload,
    ): Beneficiary {
        $responseData = $response->json() ?? ['raw' => $response->body()];

        if (! $response->successful()) {
            $beneficiary->update([
                'status' => "{$action}_failed",
                'raw_data' => array_merge($beneficiary->raw_data ?? [], [
                    "{$action}_request" => $requestPayload,
                    "{$action}_error" => $responseData,
                ]),
            ]);

            throw new RuntimeException("{$provider->name} beneficiary {$action} failed.");
        }

        $beneficiaryPayload = (array) ($responseData['beneficiary'] ?? []);
        $bankDetails = (array) ($beneficiaryPayload['bank_details'] ?? []);

        $beneficiary->update([
            'external_beneficiary_id' => $responseData['id'] ?? $responseData['beneficiary_id'] ?? $beneficiary->external_beneficiary_id,
            'full_name' => $beneficiary->full_name,
            'company_name' => $beneficiaryPayload['company_name'] ?? $beneficiary->company_name,
            'country_code' => $beneficiaryPayload['address']['country_code'] ?? $beneficiary->country_code,
            'currency' => $bankDetails['account_currency'] ?? $beneficiary->currency,
            'bank_name' => $bankDetails['bank_name'] ?? $beneficiary->bank_name,
            'bank_code' => $bankDetails['account_routing_value1'] ?? $beneficiary->bank_code,
            'branch_code' => $bankDetails['account_routing_value2'] ?? $beneficiary->branch_code,
            'account_number' => $bankDetails['account_number'] ?? $beneficiary->account_number,
            'iban' => $bankDetails['iban'] ?? $beneficiary->iban,
            'swift_bic' => $bankDetails['swift_code'] ?? $beneficiary->swift_bic,
            'status' => $this->normalizeStatus($responseData['status'] ?? 'active'),
            'raw_data' => array_merge($beneficiary->raw_data ?? [], [
                "{$action}_request" => $requestPayload,
                "{$action}_response" => $responseData,
            ]),
        ]);

        return $beneficiary->fresh();
    }

    private function beneficiaryResourcePath(string $template, string $externalBeneficiaryId): string
    {
        return str_replace('{beneficiary}', $externalBeneficiaryId, $template);
    }

    private function entityType(Beneficiary $beneficiary): string
    {
        return in_array(strtolower((string) $beneficiary->beneficiary_type), ['company', 'business', 'corporate'], true)
            ? 'COMPANY'
            : 'PERSONAL';
    }

    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];
        $firstName = array_shift($parts) ?: $fullName;
        $lastName = implode(' ', $parts);

        return [$firstName, $lastName !== '' ? $lastName : $firstName];
    }

    private function normalizeStatus(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            'PENDING', 'IN_REVIEW', 'PROCESSING' => 'pending',
            'FAILED', 'ERROR' => 'failed',
            default => 'active',
        };
    }
}
