<?php

namespace App\Services\Wise;

use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Services\Integrations\Contracts\BeneficiaryProvider;
use App\Services\Integrations\BeneficiaryOperationService;
use App\Services\Integrations\CoordinatesBeneficiaryOperations;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use RuntimeException;

class WiseBeneficiaryService implements BeneficiaryProvider
{
    use CoordinatesBeneficiaryOperations;

    use WiseDataFormatter;

    public function __construct(
        private readonly WiseService $wiseService,
    ) {
    }

    protected function performCreateBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): Beneficiary
    {
        $beneficiary->loadMissing('user');
        $payload = $this->buildBeneficiaryPayload($beneficiary);
        BeneficiaryOperationService::beginMutation($beneficiary, 'create');
        $response = $this->wiseService->post(
            path: (string) config('services.wise.recipient_endpoint'),
            payload: $payload,
            user: $beneficiary->user,
        );

        return $this->handleWriteResponse($provider, $beneficiary, $response, 'create', $payload);
    }

    protected function performUpdateBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): Beneficiary
    {
        if (! filled($beneficiary->external_beneficiary_id)) {
            return $this->createBeneficiary($provider, $beneficiary);
        }

        $beneficiary->loadMissing('user');
        $wise = (array) (($beneficiary->raw_data ?? [])['wise'] ?? []);

        if (($wise['recreate_on_update'] ?? true) !== true) {
            throw new RuntimeException('Wise recipient update is not supported directly by the public API. Recreate the recipient instead.');
        }

        $previousExternalId = (string) $beneficiary->external_beneficiary_id;
        BeneficiaryOperationService::write($beneficiary, ['external_beneficiary_id' => null]);
        $beneficiary = $this->createBeneficiary($provider, BeneficiaryOperationService::current($beneficiary)->load('user'));

        BeneficiaryOperationService::beginMutation($beneficiary, 'wise_cleanup');
        $deleteResponse = $this->wiseService->delete(
            path: str_replace(
                '{accountId}',
                urlencode($previousExternalId),
                '/v2/accounts/{accountId}'
            ),
            user: $beneficiary->user,
        );

        if (! $deleteResponse->successful() && $deleteResponse->status() !== 403) {
            BeneficiaryOperationService::write($beneficiary, [
                'raw_data' => array_merge($beneficiary->raw_data ?? [], [
                    'previous_external_beneficiary_id' => $previousExternalId,
                    'cleanup_error' => $deleteResponse->json() ?? ['raw' => $deleteResponse->body()],
                ]),
            ]);
        }

        return $beneficiary;
    }

    protected function performDeleteBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): void
    {
        $beneficiary->loadMissing('user');

        if (! filled($beneficiary->external_beneficiary_id)) {
            return;
        }

        BeneficiaryOperationService::beginMutation($beneficiary, 'delete');
        $response = $this->wiseService->delete(
            path: str_replace(
                '{accountId}',
                urlencode((string) $beneficiary->external_beneficiary_id),
                '/v2/accounts/{accountId}'
            ),
            user: $beneficiary->user,
        );

        if (! $response->successful()) {
            $responseData = $response->json() ?? ['raw' => $response->body()];

            BeneficiaryOperationService::write($beneficiary, [
                'status' => 'delete_failed',
                'raw_data' => array_merge($beneficiary->raw_data ?? [], [
                    'delete_error' => $responseData,
                ]),
            ]);

            throw new RuntimeException($this->transferFailureMessage($responseData, "{$provider->name} beneficiary deletion failed."));
        }
    }

    private function buildBeneficiaryPayload(Beneficiary $beneficiary): array
    {
        $wise = (array) (($beneficiary->raw_data ?? [])['wise'] ?? []);
        $payload = array_filter([
            'currency' => $beneficiary->currency,
            'type' => $this->defaultRecipientType($beneficiary, $wise),
            'profile' => $this->wiseService->profileId($beneficiary->user),
            'accountHolderName' => $this->beneficiaryName($beneficiary),
            'ownedByCustomer' => $this->ownedByCustomer($beneficiary),
            'details' => $this->defaultRecipientDetails($beneficiary, $wise),
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);

        if (isset($wise['request']) && is_array($wise['request'])) {
            $payload = array_replace_recursive($payload, $wise['request']);
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

        if (! $response->successful() || ! filled($responseData['id'] ?? null)) {
            BeneficiaryOperationService::write($beneficiary, [
                'status' => "{$action}_failed",
                'raw_data' => array_merge($beneficiary->raw_data ?? [], [
                    "{$action}_request" => $requestPayload,
                    "{$action}_error" => $responseData,
                ]),
            ]);

            throw new RuntimeException($this->transferFailureMessage($responseData, "{$provider->name} beneficiary {$action} failed."));
        }

        BeneficiaryOperationService::write($beneficiary, [
            'external_beneficiary_id' => (string) $responseData['id'],
            'status' => $this->normalizeBeneficiaryStatus(
                Arr::get($responseData, 'status'),
                Arr::get($responseData, 'active')
            ),
            'raw_data' => array_merge($beneficiary->raw_data ?? [], [
                "{$action}_request" => $requestPayload,
                "{$action}_response" => $responseData,
                'confirmations' => $responseData['confirmations'] ?? null,
            ]),
        ]);

        return BeneficiaryOperationService::current($beneficiary);
    }
}
