<?php

namespace App\Services\Integrations\Support;

use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Integrations\Contracts\OnboardingProvider;
use App\Services\Integrations\Contracts\ProviderPayloadMapper;
use App\Services\Integrations\DataObjects\ProviderOnboardingResult;
use App\Services\Integrations\ProviderAccountStatusManager;
use App\Services\Integrations\ProviderHttpClient;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class AbstractApiOnboardingProvider implements OnboardingProvider
{
    public function beginOnboarding(
        IntegrationProvider $provider,
        User $user,
        ?UserProviderAccount $existingProviderAccount = null,
    ): ProviderOnboardingResult {
        $providerAccount = $this->syncUser($provider, $user);

        return new ProviderOnboardingResult(
            providerAccount: $providerAccount->fresh('provider'),
            status: (string) $providerAccount->status,
            nextAction: $this->nextActionForStatus((string) $providerAccount->status),
            message: $this->messageForStatus($provider, (string) $providerAccount->status),
            metadata: [
                'provider_code' => $provider->code,
                'provider_account_status' => $providerAccount->status,
            ],
        );
    }

    public function completeOnboarding(
        IntegrationProvider $provider,
        User $user,
        UserProviderAccount $providerAccount,
        array $payload,
    ): ProviderOnboardingResult {
        throw new RuntimeException("{$provider->name} does not support callback-based onboarding completion.");
    }

    final public function syncUser(IntegrationProvider $provider, User $user): UserProviderAccount
    {
        $user->loadMissing([
            'profile',
            'kycProfile.documents',
            'kycProfile.relatedPersons.documents',
            'kycProfile.providerSubmissions.provider',
            'kycProfile.requirements',
        ]);

        if ($user->profile === null) {
            throw new RuntimeException('User profile is required before syncing with provider.');
        }

        $client = new ProviderHttpClient(
            provider: $provider,
            serviceConfigKey: $this->serviceConfigKey(),
            headers: $this->requestHeaders(),
        );

        $payloadMapper = $this->payloadMapper();

        return DB::transaction(function () use ($user, $provider, $client, $payloadMapper): UserProviderAccount {
            $providerAccount = $user->providerAccounts()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'provider_id' => $provider->id,
                    'external_account_id' => null,
                ],
                [
                    'status' => 'pending',
                    'metadata' => [
                        'integration_status' => 'awaiting_provider_details',
                    ],
                ]
            );

            $customerResponse = $client->post(
                path: $this->customerEndpoint(),
                payload: $payloadMapper->buildCustomerPayload($user),
                user: $user,
            );
            $customerData = $customerResponse->json() ?? [];

            if (! $customerResponse->successful()) {
                $providerAccount->update([
                    'status' => 'failed',
                    'metadata' => array_merge($providerAccount->metadata ?? [], [
                        'integration_status' => 'customer_create_failed',
                        'last_error' => $customerData,
                    ]),
                ]);

                throw new RuntimeException("{$provider->name} customer creation failed.");
            }

            $accountResponse = $client->post(
                path: $this->accountEndpoint(),
                payload: $payloadMapper->buildAccountPayload($user, $customerData),
                user: $user,
            );
            $accountData = $accountResponse->json() ?? [];

            if (! $accountResponse->successful()) {
                $providerAccount->update([
                    'external_customer_id' => $payloadMapper->extractCustomerId($customerData),
                    'status' => 'failed',
                    'metadata' => array_merge($providerAccount->metadata ?? [], [
                        'integration_status' => 'account_create_failed',
                        'customer_response' => $customerData,
                        'last_error' => $accountData,
                    ]),
                ]);

                throw new RuntimeException("{$provider->name} account creation failed.");
            }

            $statusManager = app(ProviderAccountStatusManager::class);
            $providerAccountStatus = $statusManager->normalizeProviderAccountSubmissionStatus(
                $accountData['status'] ?? null
            );

            $providerAccount->update([
                'external_customer_id' => $payloadMapper->extractCustomerId($customerData),
                'external_account_id' => $payloadMapper->extractAccountId($accountData),
                'account_name' => $payloadMapper->extractAccountName($user, $accountData),
                'status' => $providerAccountStatus,
                'metadata' => array_merge($providerAccount->metadata ?? [], [
                    'integration_status' => "submitted_to_{$provider->code}",
                    'customer_response' => $customerData,
                    'account_response' => $accountData,
                    'submitted_at' => now()->toISOString(),
                ]),
            ]);

            $providerAccount = $providerAccount->fresh('user');
            $statusManager->syncUserStatusFromProviderAccount($providerAccount);

            return $providerAccount->fresh();
        });
    }

    abstract protected function serviceConfigKey(): string;

    abstract protected function requestHeaders(): array;

    abstract protected function customerEndpoint(): string;

    abstract protected function accountEndpoint(): string;

    abstract protected function payloadMapper(): ProviderPayloadMapper;

    protected function nextActionForStatus(string $status): string
    {
        return match (strtolower($status)) {
            'active' => 'provider_onboarding_completed',
            'rejected', 'failed' => 'contact_support',
            default => 'wait_for_provider_review',
        };
    }

    protected function messageForStatus(IntegrationProvider $provider, string $status): string
    {
        return match (strtolower($status)) {
            'active' => "{$provider->name} onboarding completed successfully.",
            'rejected' => "{$provider->name} onboarding was rejected.",
            'failed' => "{$provider->name} onboarding failed.",
            default => "{$provider->name} onboarding request sent successfully.",
        };
    }
}
