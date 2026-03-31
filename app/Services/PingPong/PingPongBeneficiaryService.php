<?php

namespace App\Services\PingPong;

use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Services\Integrations\Contracts\BeneficiaryProvider;
use Illuminate\Http\Client\Response;
use RuntimeException;

class PingPongBeneficiaryService implements BeneficiaryProvider
{
    public function __construct(
        private readonly PingPongService $pingPongService,
    ) {
    }

    public function createBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): Beneficiary
    {
        $response = $this->pingPongService->createRecipient(
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

        $response = $this->pingPongService->updateRecipient(
            payload: array_merge(
                ['biz_id' => (string) $beneficiary->external_beneficiary_id],
                $this->buildBeneficiaryPayload($beneficiary),
            ),
            user: $beneficiary->user,
        );

        return $this->handleWriteResponse($provider, $beneficiary, $response, 'update');
    }

    public function deleteBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): void
    {
        if (! filled($beneficiary->external_beneficiary_id)) {
            return;
        }

        $response = $this->pingPongService->deleteRecipient(
            bizId: (string) $beneficiary->external_beneficiary_id,
            user: $beneficiary->user,
        );

        $responseData = $response->json() ?? ['raw' => $response->body()];

        if (! $this->isSuccessfulResponse($response, $responseData)) {
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
        $pingPong = (array) ($rawData['pingpong'] ?? []);
        $bankDetailOverrides = (array) ($pingPong['bank_detail'] ?? []);
        $recipientDetailOverrides = (array) ($pingPong['recipient_detail'] ?? []);
        $recipientName = $this->beneficiaryName($beneficiary);

        return array_filter([
            'holder_type' => $this->holderType($beneficiary),
            'account_type' => 'RECIPIENT_BANK',
            'bank_detail' => array_filter([
                'currency' => $beneficiary->currency,
                'account_name' => $recipientName,
                'account_no' => $beneficiary->account_number ?: $beneficiary->iban,
                'bank_name' => $beneficiary->bank_name,
                'bank_code' => $beneficiary->bank_code,
                'account_type' => $bankDetailOverrides['account_type'] ?? 'CHECKING',
                'location' => $beneficiary->country_code,
                'contact_phone' => $beneficiary->phone,
                'branch_name' => $bankDetailOverrides['branch_name'] ?? null,
                'branch_code' => $beneficiary->branch_code,
                'ifsc_code' => $bankDetailOverrides['ifsc_code'] ?? null,
                'sort_code' => $bankDetailOverrides['sort_code'] ?? null,
                'address' => $this->fullAddress($beneficiary),
                'province' => $beneficiary->state,
                'city' => $beneficiary->city,
                'swift_code' => $beneficiary->swift_bic,
                'routing_no' => $bankDetailOverrides['routing_no'] ?? null,
                'iban' => $beneficiary->iban,
                'cert_type' => $bankDetailOverrides['cert_type'] ?? null,
                'cert_no' => $bankDetailOverrides['cert_no'] ?? null,
            ], static fn ($value) => $value !== null && $value !== ''),
            'recipient_detail' => array_filter([
                'recipient_type' => $recipientDetailOverrides['recipient_type'] ?? '20',
                'recipient_location' => $beneficiary->country_code,
                'phone_prefix' => $recipientDetailOverrides['phone_prefix'] ?? null,
                'phone_no' => $beneficiary->phone,
                'email' => $beneficiary->email,
                'address' => $this->fullAddress($beneficiary),
                'address_state' => $beneficiary->state,
                'address_city' => $beneficiary->city,
                'address_street' => $beneficiary->address_line2 ?: $beneficiary->address_line1,
                'address_postcode' => $beneficiary->postal_code,
                'name' => $recipientName,
                'partner_user_id' => (string) $beneficiary->id,
            ], static fn ($value) => $value !== null && $value !== ''),
            'document' => $pingPong['document'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function handleWriteResponse(
        IntegrationProvider $provider,
        Beneficiary $beneficiary,
        Response $response,
        string $action,
    ): Beneficiary {
        $responseData = $response->json() ?? ['raw' => $response->body()];

        if (! $this->isSuccessfulResponse($response, $responseData)) {
            $beneficiary->update([
                'status' => "{$action}_failed",
                'raw_data' => array_merge($beneficiary->raw_data ?? [], [
                    "{$action}_error" => $responseData,
                ]),
            ]);

            throw new RuntimeException("{$provider->name} beneficiary {$action} failed.");
        }

        $providerData = (array) ($responseData['data'] ?? []);
        $beneficiary->update([
            'external_beneficiary_id' => $providerData['biz_id'] ?? $beneficiary->external_beneficiary_id,
            'status' => $this->normalizeBeneficiaryStatus($providerData['status'] ?? ($action === 'create' ? 'PENDING' : $beneficiary->status)),
            'raw_data' => array_merge($beneficiary->raw_data ?? [], [
                "{$action}_request" => $this->buildBeneficiaryPayload($beneficiary),
                "{$action}_response" => $responseData,
            ]),
        ]);

        return $beneficiary->fresh();
    }

    private function isSuccessfulResponse(Response $response, array $responseData): bool
    {
        return $response->successful() && strtoupper((string) ($responseData['code'] ?? '')) === 'SUCCESS';
    }

    private function holderType(Beneficiary $beneficiary): string
    {
        return in_array(strtolower((string) $beneficiary->beneficiary_type), ['company', 'business', 'corporate'], true)
            ? 'COMPANY'
            : 'PERSONAL';
    }

    private function beneficiaryName(Beneficiary $beneficiary): string
    {
        return $this->holderType($beneficiary) === 'COMPANY'
            ? (string) ($beneficiary->company_name ?: $beneficiary->full_name)
            : (string) $beneficiary->full_name;
    }

    private function fullAddress(Beneficiary $beneficiary): ?string
    {
        $parts = array_filter([
            $beneficiary->address_line1,
            $beneficiary->address_line2,
            $beneficiary->city,
            $beneficiary->state,
            $beneficiary->postal_code,
            $beneficiary->country_code,
        ]);

        if ($parts === []) {
            return null;
        }

        return implode(', ', $parts);
    }

    private function normalizeBeneficiaryStatus(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            'AVAILABLE' => 'active',
            'DECLINED' => 'rejected',
            'PENDING' => 'pending',
            default => strtolower((string) ($status ?? 'pending')),
        };
    }
}
