<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Models\IntegrationProvider;
use App\Models\KycProfile;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use App\Services\Nium\NiumHkManualSubmitKycService;
use App\Services\Nium\NiumSafeValueProjector;
use App\Services\Nium\NiumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

final class NiumHkManualSubmitKycTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_permission_is_required(): void
    {
        $customer = User::factory()->create();
        $normalUser = User::factory()->create();

        $this->withToken($this->issueTokenFor($normalUser))
            ->postJson("/api/admin/users/{$customer->id}/kyc-profile/submit-kyc")
            ->assertForbidden();
    }

    public function test_manual_submit_uses_metadata_applicant_without_entity_webhook_and_persists_real_biometric_url(): void
    {
        $context = $this->context();
        $biometricUrl = 'https://verify.example.test/real-nium-session-token';
        $calls = $this->mockResponse($context, [
            'biometricUrl' => $biometricUrl,
            'customerHashId' => $context['account']->external_customer_id,
            'entityType' => 'APPLICANT',
            'externalId' => $context['external_id'],
            'kycMode' => 'BIOMETRIC_KYC',
            'kycStatus' => 'initiated',
            'referenceId' => 'manual-provider-response-reference',
        ]);

        $result = app(NiumHkManualSubmitKycService::class)->submit($context['user']);

        $this->assertDatabaseMissing('webhook_events', ['event_type' => 'CUSTOMER_ENTITY_KYC_STATUS']);
        $this->assertSame(1, $calls->count);
        $this->assertStringContainsString('/submitKyc', $calls->path);
        $this->assertSame('applicant', $calls->payload['entityType']);
        $this->assertSame('biometric_kyc', $calls->payload['kycMode']);
        $this->assertTrue(Str::isUuid($calls->payload['entityReferenceId']));
        $this->assertNotSame($context['provider_reference_id'], $calls->payload['entityReferenceId']);
        $this->assertStringStartsNotWith('origin-wallet-manual-', $calls->payload['entityReferenceId']);
        $this->assertSame($biometricUrl, $result['biometric_url']);
        $this->assertSame('manual-provider-response-reference', $result['provider_reference_id']);
        $this->assertSame('accepted', $result['state']);
        $this->assertSame('applicant', $result['entity_type']);
        $this->assertSame($context['external_id'], $result['external_id']);
        $this->assertSame('biometric_kyc', $result['kyc_mode']);

        $attempts = $context['account']->fresh()->metadata['nium_submit_kyc_attempts'];
        $attempt = collect($attempts)->sole();
        $this->assertTrue($attempt['manual_admin_action']);
        $this->assertStringStartsWith('origin-wallet-manual-', $attempt['manual_reference_id']);
        $this->assertNotSame($calls->payload['entityReferenceId'], $attempt['manual_reference_id']);
        $this->assertSame($attempt['manual_reference_id'], $calls->externalReference);
        $this->assertSame($biometricUrl, $attempt['biometric_url']);
        $this->assertSame(substr(hash('sha256', $biometricUrl), 0, 16), $attempt['biometric_url_fingerprint']);
        $this->assertStringNotContainsString($biometricUrl, json_encode($context['account']->fresh()->toArray(), JSON_THROW_ON_ERROR));

        $serializedLogs = ApiRequestLog::query()->get()->toJson();
        $this->assertStringNotContainsString($biometricUrl, $serializedLogs);
        $this->assertDatabaseCount('user_provider_accounts', 1);
    }

    public function test_redirect_url_is_used_as_fallback(): void
    {
        $context = $this->context();
        $redirectUrl = 'https://verify.example.test/redirect-session-token';
        $this->mockResponse($context, [
            'redirectUrl' => $redirectUrl,
            'entityType' => 'applicant',
            'externalId' => $context['external_id'],
            'kycMode' => 'biometric_kyc',
            'kycStatus' => 'initiated',
            'referenceId' => 'nium-reference-redirect',
        ]);

        $result = app(NiumHkManualSubmitKycService::class)->submit($context['user']);

        $this->assertSame($redirectUrl, $result['biometric_url']);
    }

    public function test_success_without_url_is_preserved_without_fabricating_one(): void
    {
        $context = $this->context();
        $this->mockResponse($context, [
            'entityType' => 'applicant',
            'externalId' => $context['external_id'],
            'kycMode' => 'biometric_kyc',
            'kycStatus' => 'initiated',
            'referenceId' => 'nium-reference-without-url',
        ]);

        $result = app(NiumHkManualSubmitKycService::class)->submit($context['user']);

        $this->assertSame('accepted', $result['state']);
        $this->assertArrayNotHasKey('biometric_url', $result);
        $attempt = collect($context['account']->fresh()->metadata['nium_submit_kyc_attempts'])->sole();
        $this->assertArrayNotHasKey('biometric_url', $attempt);
    }

    private function context(): array
    {
        $provider = IntegrationProvider::query()->create(['code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        $user = User::factory()->create();
        $profile = KycProfile::query()->create([
            'user_id' => $user->id,
            'status' => 'approved',
            'applicant_type' => 'business',
            'legal_name' => 'HK Test Ltd',
            'address_line1' => '1 Test Road',
            'city' => 'Hong Kong',
            'country_code' => 'HK',
            'metadata' => ['nium_region' => 'HK', 'nium_kyc_type' => 'full'],
        ]);
        $person = $profile->relatedPersons()->create([
            'relationship_type' => 'applicant',
            'status' => 'approved',
            'legal_name' => 'Test Person',
            'ownership_percentage' => 100,
            'residence_country_code' => 'VN',
        ]);
        $person->documents()->create([
            'kyc_profile_id' => $profile->id,
            'type' => 'passport',
            'status' => 'approved',
            'document_number' => 'P123',
            'issuing_country_code' => 'HK',
            'expires_at' => '2099-12-31',
            'file_url' => 'private://passport',
        ]);
        $account = UserProviderAccount::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'external_customer_id' => 'ed61adf3-ad32-4a05-96a1-909318194002',
            'customer_id_verified_at' => now(),
            'reconciliation_status' => 'reconciled',
            'status' => 'pending',
            'metadata' => [],
        ]);
        $externalId = 'origin-wallet-applicant-'.$person->id;
        $providerReferenceId = '7205cfe0-bf31-415e-b07d-e911b7e9d58e';
        $account->forceFill(['metadata' => [
            'nium_entity_kyc_states' => [
                'ref_applicant' => [
                    'entity_type' => 'applicant',
                    'external_id' => $externalId,
                    'kyc_status' => 'kyc_required',
                    'provider_reference_id' => $providerReferenceId,
                    'updated_at' => now()->toISOString(),
                ],
            ],
        ]])->save();
        WebhookEvent::query()->create([
            'provider_id' => $provider->id,
            'event_id' => (string) Str::uuid(),
            'event_type' => 'CUSTOMER_STATUS_WEBHOOK',
            'external_resource_id' => $account->external_customer_id,
            'payload' => [
                'customerHashId' => $account->external_customer_id,
                'status' => 'pending',
                'subStatus' => 'awaiting_kyc',
            ],
            'processing_status' => 'processed',
            'processed_at' => now(),
        ]);

        return compact('provider', 'user', 'profile', 'person', 'account', 'externalId') + [
            'external_id' => $externalId,
            'provider_reference_id' => $providerReferenceId,
        ];
    }

    private function mockResponse(array $context, array $body): object
    {
        $calls = new class
        {
            public int $count = 0;

            public string $path = '';

            public array $payload = [];

            public string $externalReference = '';
        };

        $this->mock(NiumService::class, function (MockInterface $mock) use ($calls, $context, $body): void {
            $mock->shouldReceive('clientId')->andReturn('client-id');
            $mock->shouldReceive('path')->once()->andReturnUsing(function (string $template, array $values) use ($context): string {
                $this->assertSame($context['account']->external_customer_id, $values['customer']);

                return "/api/v5/client/{$values['client']}/customer/{$values['customer']}/submitKyc";
            });
            $mock->shouldReceive('post')->once()->andReturnUsing(function (...$arguments) use ($calls, $context, $body): Response {
                $calls->count++;
                $calls->path = $arguments[0];
                $calls->payload = $arguments[1];
                $calls->externalReference = $arguments[5];
                ApiRequestLog::query()->create([
                    'provider_id' => $context['provider']->id,
                    'user_id' => $context['user']->id,
                    'operation' => 'submit_kyc',
                    'external_reference' => $arguments[5],
                    'request_method' => 'POST',
                    'request_url' => '/safe/submitKyc',
                    'response_status' => 200,
                    'response_body' => app(NiumSafeValueProjector::class)->apiResponseBody($body, 200),
                    'is_success' => true,
                ]);

                return new Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode($body, JSON_THROW_ON_ERROR)));
            });
        });

        return $calls;
    }

    private function issueTokenFor(User $user): string
    {
        $plainText = Str::random(48);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'test',
            'token_hash' => hash('sha256', $plainText),
            'expires_at' => now()->addDay(),
        ]);

        return $plainText;
    }
}
