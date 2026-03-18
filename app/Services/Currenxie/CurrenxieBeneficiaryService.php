<?php

namespace App\Services\Currenxie;

use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Services\Integrations\Contracts\BeneficiaryProvider;
use App\Services\Integrations\ProviderHttpClient;
use Illuminate\Http\Client\Response;
use RuntimeException;

class CurrenxieBeneficiaryService implements BeneficiaryProvider
{
    public function createBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): Beneficiary
    {
        $response = $this->client($provider)->post(
            path: (string) config('services.currenxie.beneficiary_endpoint'),
            payload: $this->buildBeneficiaryPayload($beneficiary),
            user: $beneficiary->user,
        );

        return $this->handleWriteResponse($provider, $beneficiary, $response, 'create');
    }

    public function updateBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): Beneficiary
    {
        if (! filled($beneficiary->external_beneficiary_id)) {
            return $this->createBeneficiary($provider, $beneficiary);
        }

        $response = $this->client($provider)->put(
            path: $this->beneficiaryResourcePath(
                (string) config('services.currenxie.beneficiary_update_endpoint'),
                (string) $beneficiary->external_beneficiary_id,
            ),
            payload: $this->buildBeneficiaryPayload($beneficiary),
            user: $beneficiary->user,
        );

        return $this->handleWriteResponse($provider, $beneficiary, $response, 'update');
    }

    public function deleteBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): void
    {
        if (! filled($beneficiary->external_beneficiary_id)) {
            return;
        }

        $response = $this->client($provider)->delete(
            path: $this->beneficiaryResourcePath(
                (string) config('services.currenxie.beneficiary_delete_endpoint'),
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

    private function client(IntegrationProvider $provider): ProviderHttpClient
    {
        return new ProviderHttpClient(
            provider: $provider,
            serviceConfigKey: 'currenxie',
            headers: [
                'X-API-KEY' => (string) config('services.currenxie.api_key'),
                'X-API-SECRET' => (string) config('services.currenxie.api_secret'),
            ],
        );
    }

    private function buildBeneficiaryPayload(Beneficiary $beneficiary): array
    {
        return [
            'user_reference' => (string) $beneficiary->user_id,
            'beneficiary_type' => $beneficiary->beneficiary_type,
            'full_name' => $beneficiary->full_name,
            'company_name' => $beneficiary->company_name,
            'email' => $beneficiary->email,
            'phone' => $beneficiary->phone,
            'country_code' => $beneficiary->country_code,
            'currency' => $beneficiary->currency,
            'bank_name' => $beneficiary->bank_name,
            'bank_code' => $beneficiary->bank_code,
            'branch_code' => $beneficiary->branch_code,
            'account_number' => $beneficiary->account_number,
            'iban' => $beneficiary->iban,
            'swift_bic' => $beneficiary->swift_bic,
            'address_line1' => $beneficiary->address_line1,
            'address_line2' => $beneficiary->address_line2,
            'city' => $beneficiary->city,
            'state' => $beneficiary->state,
            'postal_code' => $beneficiary->postal_code,
        ];
    }

    private function handleWriteResponse(
        IntegrationProvider $provider,
        Beneficiary $beneficiary,
        Response $response,
        string $action,
    ): Beneficiary {
        $responseData = $response->json() ?? ['raw' => $response->body()];

        if (! $response->successful()) {
            $beneficiary->update([
                'status' => "{$action}_failed",
                'raw_data' => array_merge($beneficiary->raw_data ?? [], [
                    "{$action}_error" => $responseData,
                ]),
            ]);

            throw new RuntimeException("{$provider->name} beneficiary {$action} failed.");
        }

        $beneficiary->update([
            'external_beneficiary_id' => $responseData['id'] ?? $responseData['beneficiary_id'] ?? $beneficiary->external_beneficiary_id,
            'status' => $responseData['status'] ?? 'active',
            'raw_data' => array_merge($beneficiary->raw_data ?? [], [
                "{$action}_response" => $responseData,
            ]),
        ]);

        return $beneficiary->fresh();
    }

    private function beneficiaryResourcePath(string $template, string $externalBeneficiaryId): string
    {
        return str_replace('{beneficiary}', $externalBeneficiaryId, $template);
    }
}
