<?php

namespace App\Services\Tazapay;

use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Services\Integrations\Contracts\BeneficiaryProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use RuntimeException;

class TazapayBeneficiaryService implements BeneficiaryProvider
{
    public function __construct(
        private readonly TazapayService $tazapayService,
    ) {
    }

    public function createBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): Beneficiary
    {
        $payload = $this->buildBeneficiaryPayload($beneficiary);
        $response = $this->tazapayService->post(
            path: (string) config('services.tazapay.beneficiary_endpoint'),
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
        $response = $this->tazapayService->put(
            path: str_replace(
                '{beneficiary}',
                urlencode((string) $beneficiary->external_beneficiary_id),
                (string) config('services.tazapay.beneficiary_update_endpoint'),
            ),
            payload: $payload,
            user: $beneficiary->user,
        );

        return $this->handleWriteResponse($provider, $beneficiary, $response, 'update', $payload);
    }

    public function deleteBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): void
    {
        // Tazapay beneficiary docs currently expose create/fetch/update flows; no delete API is wired here.
    }

    private function buildBeneficiaryPayload(Beneficiary $beneficiary): array
    {
        $rawData = (array) ($beneficiary->raw_data ?? []);
        $tazapay = (array) ($rawData['tazapay'] ?? []);
        $destinationType = strtolower((string) ($tazapay['destination_type'] ?? 'bank'));
        $bankCodes = array_filter([
            'swift_code' => $beneficiary->swift_bic ?: ($tazapay['bank_codes']['swift_code'] ?? null),
            'ifsc_code' => $tazapay['bank_codes']['ifsc_code'] ?? null,
            'aba_code' => $tazapay['bank_codes']['aba_code'] ?? $beneficiary->bank_code,
            'sort_code' => $tazapay['bank_codes']['sort_code'] ?? $beneficiary->branch_code,
        ], static fn ($value) => $value !== null && $value !== '');

        $bank = array_filter([
            'country' => $beneficiary->country_code,
            'currency' => $beneficiary->currency,
            'bank_codes' => $bankCodes,
            'bank_name' => $beneficiary->bank_name,
            'branch_name' => $tazapay['bank']['branch_name'] ?? null,
            'account_number' => $beneficiary->account_number,
            'account_type' => $tazapay['bank']['account_type'] ?? null,
            'iban' => $beneficiary->iban,
            'firc_required' => Arr::get($tazapay, 'bank.firc_required'),
            'purpose_code' => $tazapay['bank']['purpose_code'] ?? null,
            'transfer_type' => $tazapay['bank']['transfer_type'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);

        $payload = array_filter([
            'account_id' => $tazapay['account_id'] ?? config('services.tazapay.account_id'),
            'name' => $beneficiary->company_name ?: $beneficiary->full_name,
            'type' => $this->normalizeBeneficiaryType($beneficiary->beneficiary_type),
            'email' => $beneficiary->email,
            'tax_id' => $tazapay['tax_id'] ?? null,
            'national_identification_number' => $tazapay['national_identification_number'] ?? null,
            'registration_number' => $tazapay['registration_number'] ?? null,
            'date_of_birth' => $tazapay['date_of_birth'] ?? null,
            'nationality' => $tazapay['nationality'] ?? $beneficiary->country_code,
            'party_classification' => $tazapay['party_classification'] ?? null,
            'name_local' => $tazapay['name_local'] ?? null,
            'metadata' => $tazapay['metadata'] ?? null,
            'destination' => $tazapay['destination'] ?? null,
            'destination_details' => $destinationType === 'bank'
                ? [
                    'type' => 'bank',
                    'bank' => $bank,
                ]
                : ($tazapay['destination_details'] ?? null),
            'address' => array_filter([
                'line1' => $beneficiary->address_line1,
                'line2' => $beneficiary->address_line2,
                'city' => $beneficiary->city,
                'state' => $beneficiary->state,
                'country' => $beneficiary->country_code,
                'postal_code' => $beneficiary->postal_code,
            ], static fn ($value) => $value !== null && $value !== ''),
            'phone' => array_filter([
                'number' => $beneficiary->phone,
                'calling_code' => $tazapay['phone']['calling_code'] ?? null,
            ], static fn ($value) => $value !== null && $value !== ''),
            'documents' => $tazapay['documents'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);

        if (($payload['destination_details']['type'] ?? null) === 'bank' && empty($payload['destination_details']['bank'])) {
            throw new RuntimeException('Tazapay beneficiary requires bank destination details.');
        }

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
        $beneficiaryPayload = (array) ($responseData['data'] ?? $responseData);

        if (! $response->successful() || ! filled($beneficiaryPayload['id'] ?? $beneficiary->external_beneficiary_id)) {
            $beneficiary->update([
                'status' => "{$action}_failed",
                'raw_data' => array_merge($beneficiary->raw_data ?? [], [
                    "{$action}_request" => $requestPayload,
                    "{$action}_error" => $responseData,
                ]),
            ]);

            throw new RuntimeException($responseData['message'] ?? "{$provider->name} beneficiary {$action} failed.");
        }

        $bankDetails = (array) Arr::get($beneficiaryPayload, 'destination_details.bank', []);
        $bankCodes = (array) ($bankDetails['bank_codes'] ?? []);

        $beneficiary->update([
            'external_beneficiary_id' => $beneficiaryPayload['id'] ?? $beneficiary->external_beneficiary_id,
            'bank_name' => $bankDetails['bank_name'] ?? $beneficiary->bank_name,
            'bank_code' => $bankCodes['aba_code'] ?? $bankCodes['swift_code'] ?? $beneficiary->bank_code,
            'branch_code' => $bankCodes['sort_code'] ?? $beneficiary->branch_code,
            'account_number' => $bankDetails['account_number'] ?? $beneficiary->account_number,
            'iban' => $bankDetails['iban'] ?? $beneficiary->iban,
            'swift_bic' => $bankCodes['swift_code'] ?? $beneficiary->swift_bic,
            'status' => $this->normalizeBeneficiaryStatus($beneficiaryPayload['status'] ?? 'active'),
            'raw_data' => array_merge($beneficiary->raw_data ?? [], [
                "{$action}_request" => $requestPayload,
                "{$action}_response" => $responseData,
            ]),
        ]);

        return $beneficiary->fresh();
    }

    private function normalizeBeneficiaryType(?string $type): string
    {
        return in_array(strtolower((string) $type), ['company', 'business', 'corporate'], true)
            ? 'business'
            : 'individual';
    }

    private function normalizeBeneficiaryStatus(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'inactive' => 'inactive',
            'processing', 'pending', 'in_review' => 'pending',
            'failed', 'error' => 'failed',
            default => 'active',
        };
    }
}
