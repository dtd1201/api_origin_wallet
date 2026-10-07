<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumCustomerOnboardingService;
use App\Services\Nium\NiumSubmitKycBiometricUrlResolver;
use Mockery\MockInterface;
use Tests\TestCase;

final class NiumSubmitKycBiometricUrlResolverTest extends TestCase
{
    public function test_matching_external_id_reference_id_and_biometric_url_returns_url(): void
    {
        $url = 'https://idv.nium.test/prod/jumio/start?referenceNumber=matching';

        $this->assertSame($url, $this->resolve([
            'externalId' => 'origin-wallet-applicant-4',
            'referenceId' => '7205cfe0-bf31-415e-b07d-e911b7e9d58e',
            'biometricUrl' => $url,
        ]));
    }

    public function test_mismatched_external_id_returns_null(): void
    {
        $this->assertNull($this->resolve([
            'externalId' => 'origin-wallet-applicant-999',
            'referenceId' => '7205cfe0-bf31-415e-b07d-e911b7e9d58e',
            'biometricUrl' => 'https://idv.nium.test/wrong-external-id',
        ]));
    }

    public function test_mismatched_reference_id_returns_null(): void
    {
        $this->assertNull($this->resolve([
            'externalId' => 'origin-wallet-applicant-4',
            'referenceId' => 'different-reference-id',
            'biometricUrl' => 'https://idv.nium.test/wrong-reference-id',
        ]));
    }

    public function test_expected_reference_id_with_missing_applicant_reference_id_returns_null(): void
    {
        $this->assertNull($this->resolve([
            'externalId' => 'origin-wallet-applicant-4',
            'biometricUrl' => 'https://idv.nium.test/missing-reference-id',
        ]));
    }

    private function resolve(array $applicant): ?string
    {
        $account = new UserProviderAccount([
            'external_customer_id' => 'ed61adf3-ad32-4a05-96a1-909318194002',
        ]);
        $account->setRelation('user', new User);

        $retrieval = $this->mock(
            NiumCustomerOnboardingService::class,
            function (MockInterface $mock) use ($applicant): void {
                $mock->shouldReceive('retrieveCustomer')
                    ->once()
                    ->andReturnUsing(function (
                        UserProviderAccount $providerAccount,
                        ?User $user = null,
                        ?string $verifiedCustomerHashId = null,
                        ?string $requestId = null,
                        ?array &$retrievedPayload = null,
                    ) use ($applicant): UserProviderAccount {
                        $retrievedPayload = ['applicant' => $applicant];

                        return $providerAccount;
                    });
            },
        );

        return (new NiumSubmitKycBiometricUrlResolver($retrieval))->resolve(
            $account,
            'origin-wallet-applicant-4',
            '7205cfe0-bf31-415e-b07d-e911b7e9d58e',
        );
    }
}
