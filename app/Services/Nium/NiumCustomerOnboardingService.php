<?php

namespace App\Services\Nium;

use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Integrations\Contracts\OnboardingProvider;
use App\Services\Integrations\DataObjects\ProviderOnboardingResult;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class NiumCustomerOnboardingService implements OnboardingProvider
{
    public function __construct(
        private readonly NiumService $niumService,
        private readonly NiumCustomerPayloadFactory $payloadFactory,
        private readonly NiumCustomerDocumentPreparationService $documentPreparationService,
        private readonly NiumProviderAccountStateService $stateService,
        private readonly NiumCustomerErrorMapper $errorMapper,
    ) {}

    public function syncUser(IntegrationProvider $provider, User $user): UserProviderAccount
    {
        return $this->synchronizeUser($provider, $user)['account'];
    }

    /**
     * @return array{account: UserProviderAccount, pending_document_count: int}
     */
    private function synchronizeUser(IntegrationProvider $provider, User $user): array
    {
        $providerAccount = $this->provisionProviderAccount($provider, $user);

        try {
            $account = $providerAccount->fresh();

            if (filled($account->external_customer_id)) {
                return [
                    'account' => $this->retrieveCustomer($account, $user),
                    'pending_document_count' => 0,
                ];
            }

            $this->payloadFactory->validateRequiredSourceData($user);
            $preparation = $this->documentPreparationService->prepare($user);

            if (! $preparation['ready']) {
                return [
                    'account' => $account->fresh(),
                    'pending_document_count' => $preparation['pending_document_count'],
                ];
            }

            $lookup = $this->findByExternalReference($account, $user);

            if ($lookup['ambiguous']) {
                return [
                    'account' => $this->stateService->markReconciliationFailure(
                        $account,
                        'external_id_lookup_ambiguous',
                        'nium_v5_customer_list_response',
                    ),
                    'pending_document_count' => 0,
                ];
            }

            $account = $lookup['customer'] !== null
                ? $this->applyResolvedCustomer($account, $lookup['customer'], 'nium_v5_customer_list_response')
                : $this->createCustomer($account, $user);

            return [
                'account' => $account,
                'pending_document_count' => 0,
            ];
        } catch (NiumProviderIdConflictException) {
            return [
                'account' => $providerAccount->fresh(),
                'pending_document_count' => 0,
            ];
        } catch (Throwable $exception) {
            $this->stateService->markReconciliationFailure(
                $providerAccount,
                $this->errorMapper->codeFromThrowable($exception) ?? 'customer_sync_failed',
                'nium_v5_customer_sync',
            );

            throw $exception;
        }
    }

    public function beginOnboarding(
        IntegrationProvider $provider,
        User $user,
        ?UserProviderAccount $existingProviderAccount = null,
    ): ProviderOnboardingResult {
        $synchronization = $this->synchronizeUser($provider, $user);

        return $this->result(
            $provider,
            $synchronization['account'],
            $synchronization['pending_document_count'],
        );
    }

    public function completeOnboarding(
        IntegrationProvider $provider,
        User $user,
        UserProviderAccount $providerAccount,
        array $payload,
    ): ProviderOnboardingResult {
        $reservedFields = [
            'status',
            'subStatus',
            'sub_status',
            'external_customer_id',
            'external_account_id',
            'customerHashId',
            'walletHashId',
            'walletHashIds',
        ];

        $supplied = array_values(array_intersect($reservedFields, array_keys($payload)));

        if ($supplied !== []) {
            throw new RuntimeException('Nium status and provider identifiers can only be updated from authenticated Nium responses.');
        }

        if (! filled($providerAccount->external_customer_id)) {
            throw new RuntimeException('No authenticated Nium customer exists to complete. Start onboarding first.');
        }

        return $this->result($provider, $this->retrieveCustomer($providerAccount, $user));
    }

    private function createCustomer(UserProviderAccount $providerAccount, User $user): UserProviderAccount
    {
        $externalReference = (string) $providerAccount->external_reference;

        $payload = $this->payloadFactory->build($user, $externalReference);
        $response = $this->niumService->post(
            path: $this->niumService->path(
                (string) config('services.nium.customer_create_endpoint'),
                ['client' => $this->niumService->clientId()],
            ),
            payload: $payload,
            user: $user,
        );

        if ($this->errorMapper->isDuplicateExternalId($response)) {
            $lookup = $this->findByExternalReference($providerAccount, $user);

            if ($lookup['ambiguous'] || $lookup['customer'] === null) {
                return $this->stateService->markReconciliationFailure(
                    $providerAccount,
                    $lookup['ambiguous'] ? 'duplicate_external_id_ambiguous' : 'duplicate_external_id_not_found',
                    'nium_v5_duplicate_external_id_recovery',
                );
            }

            return $this->applyResolvedCustomer(
                $providerAccount,
                $lookup['customer'],
                'nium_v5_duplicate_external_id_recovery',
            );
        }

        $data = $this->successfulResponse($response, 'Nium V5 customer creation failed.');

        if (! filled($data['customerHashId'] ?? null) || ! $this->hasWallet($data)) {
            throw new RuntimeException('Nium V5 customer response did not include customerHashId and walletHashId.');
        }

        return $this->stateService->applyAuthenticatedState(
            $providerAccount->fresh(),
            $data,
            'nium_v5_customer_create_response',
        );
    }

    public function retrieveCustomer(
        UserProviderAccount $providerAccount,
        ?User $user = null,
        ?string $verifiedCustomerHashId = null,
        ?string $requestId = null,
    ): UserProviderAccount {
        $customerHashId = $verifiedCustomerHashId ?: $providerAccount->external_customer_id;
        $response = $this->niumService->get(
            path: $this->niumService->path(
                (string) config('services.nium.customer_get_endpoint'),
                [
                    'client' => $this->niumService->clientId(),
                    'customer' => $customerHashId,
                ],
            ),
            user: $user ?? $providerAccount->user,
        );
        $data = $this->successfulResponse($response, 'Nium V5 customer retrieval failed.');

        return $this->stateService->applyAuthenticatedState(
            $providerAccount,
            $data,
            'nium_v5_customer_get_response',
            requestId: $requestId,
        );
    }

    private function findByExternalReference(UserProviderAccount $providerAccount, User $user): array
    {
        $externalReference = (string) $providerAccount->external_reference;
        $response = $this->niumService->get(
            path: $this->niumService->path(
                (string) config('services.nium.customer_list_endpoint'),
                ['client' => $this->niumService->clientId()],
            ),
            query: ['externalId' => $externalReference],
            user: $user,
        );
        $data = $this->successfulResponse($response, 'Nium V5 customer lookup failed.');
        $customers = (array) ($data['customers'] ?? []);
        $matches = array_values(array_filter(
            $customers,
            static fn ($customer): bool => is_array($customer)
                && isset($customer['externalId'])
                && hash_equals($externalReference, (string) $customer['externalId']),
        ));

        return [
            'customer' => count($matches) === 1 ? $matches[0] : null,
            'ambiguous' => count($matches) > 1 || (count($customers) > 0 && count($matches) === 0),
        ];
    }

    private function provisionProviderAccount(IntegrationProvider $provider, User $user): UserProviderAccount
    {
        return DB::transaction(function () use ($provider, $user): UserProviderAccount {
            UserProviderAccount::query()->insertOrIgnore([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'status' => 'pending',
                'metadata' => json_encode(['integration_status' => 'awaiting_nium_v5_submission']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $account = UserProviderAccount::query()
                ->where('user_id', $user->id)
                ->where('provider_id', $provider->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! filled($account->external_reference)) {
                $account = $this->stateService->applyAuthenticatedState($account, [
                    'externalId' => (string) Str::uuid(),
                    'status' => 'pending',
                    'subStatus' => null,
                ], 'origin_wallet_nium_v5_submission');
            }

            return $account;
        }, 3);
    }

    private function applyResolvedCustomer(UserProviderAccount $account, array $customer, string $source): UserProviderAccount
    {
        $resolved = $this->stateService->applyAuthenticatedState($account, $customer, $source);

        if (! filled($resolved->external_customer_id) || ! filled($resolved->external_account_id)) {
            return $this->stateService->markReconciliationFailure(
                $resolved,
                'resolved_customer_missing_verified_wallet',
                $source,
            );
        }

        return $resolved;
    }

    private function successfulResponse(Response $response, string $fallback): array
    {
        $data = $response->json() ?? [];

        if ($response->successful() && is_array($data)) {
            return $data;
        }

        throw NiumProviderRequestException::fromResponse($response, $fallback);
    }

    private function hasWallet(array $data): bool
    {
        return filled($data['walletHashId'] ?? null)
            || filled(Arr::get($data, 'wallets.0.walletHashId'))
            || filled(Arr::get($data, 'walletHashIds.0'));
    }

    private function result(
        IntegrationProvider $provider,
        UserProviderAccount $providerAccount,
        int $pendingDocumentCount = 0,
    ): ProviderOnboardingResult {
        $status = (string) $providerAccount->status;
        $awaitingDocuments = $pendingDocumentCount > 0;

        return new ProviderOnboardingResult(
            providerAccount: $providerAccount->fresh('provider'),
            status: $status,
            nextAction: $awaitingDocuments
                ? 'wait_for_document_processing'
                : match ($status) {
                    'active' => 'provider_onboarding_completed',
                    'rejected', 'failed', 'blocked' => 'contact_support',
                    default => $providerAccount->rfi_status === 'requested' ? 'respond_to_rfi' : 'wait_for_provider_review',
                },
            message: $awaitingDocuments
                ? 'Nium customer onboarding is waiting for KYC document processing. Retry onboarding shortly.'
                : match ($status) {
                    'active' => 'Nium customer onboarding is clear and the wallet is ready.',
                    'rejected' => 'Nium customer onboarding was rejected.',
                    'failed' => 'Nium customer onboarding failed.',
                    'blocked' => 'Nium customer account is not eligible.',
                    default => $providerAccount->rfi_status === 'requested'
                        ? 'Nium requires additional information before the account can be activated.'
                        : 'Nium customer onboarding is in progress.',
                },
            metadata: [
                'provider_code' => $provider->code,
                'provider_account_status' => $providerAccount->status,
                'provider_status' => $providerAccount->provider_status,
                'provider_sub_status' => $providerAccount->provider_sub_status,
                'compliance_status' => $providerAccount->compliance_status,
                'rfi_status' => $providerAccount->rfi_status,
                'pending_document_count' => $awaitingDocuments
                    ? $pendingDocumentCount
                    : null,
            ],
        );
    }
}
