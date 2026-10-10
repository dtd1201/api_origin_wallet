<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Models\IntegrationProvider;
use App\Models\KycProfile;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use App\Services\Nium\NiumCurrentBiometricKyc;
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
            'referenceId' => $context['provider_reference_id'],
        ]);

        $result = app(NiumHkManualSubmitKycService::class)->submit($context['user']);

        $this->assertDatabaseMissing('webhook_events', ['event_type' => 'CUSTOMER_ENTITY_KYC_STATUS']);
        $this->assertSame(1, $calls->count);
        $this->assertStringContainsString('/submitKyc', $calls->path);
        $this->assertSame('applicant', $calls->payload['entityType']);
        $this->assertSame('biometric_kyc', $calls->payload['kycMode']);
        $this->assertSame($context['provider_reference_id'], $calls->payload['entityReferenceId']);
        $this->assertStringStartsNotWith('origin-wallet-manual-', $calls->payload['entityReferenceId']);
        $this->assertSame($biometricUrl, $result['biometric_url']);
        $this->assertSame($context['provider_reference_id'], $result['provider_reference_id']);
        $this->assertSame('accepted', $result['state']);
        $this->assertSame('applicant', $result['entity_type']);
        $this->assertSame($context['external_id'], $result['external_id']);
        $this->assertSame('biometric_kyc', $result['kyc_mode']);

        $attempts = $context['account']->fresh()->metadata['nium_submit_kyc_attempts'];
        $attempt = collect($attempts)->sole();
        $this->assertTrue($attempt['manual_admin_action']);
        $this->assertStringStartsWith('origin-wallet-manual-', $attempt['manual_reference_id']);
        $this->assertNotSame($calls->payload['entityReferenceId'], $calls->externalReference);
        $this->assertSame($attempt['manual_reference_id'], $calls->externalReference);
        $this->assertSame($biometricUrl, $attempt['biometric_url']);
        $this->assertSame(substr(hash('sha256', $biometricUrl), 0, 16), $attempt['biometric_url_fingerprint']);
        $this->assertStringNotContainsString($biometricUrl, json_encode($context['account']->fresh()->toArray(), JSON_THROW_ON_ERROR));

        $serializedLogs = ApiRequestLog::query()->get()->toJson();
        $this->assertStringNotContainsString($biometricUrl, $serializedLogs);
        $this->assertDatabaseCount('user_provider_accounts', 1);
    }

    public function test_manual_submit_recovers_initiated_applicant_url_without_submit_or_customer_creation(): void
    {
        $context = $this->context();
        $context['account']->forceFill(['metadata' => []])->save();
        $biometricUrl = 'https://idv.nium.test/prod/jumio/start?referenceNumber=recovered';
        $calls = new class
        {
            public int $getCount = 0;
        };
        config()->set(
            'services.nium.customer_get_endpoint',
            '/api/v5/client/{clientHashId}/customer/{customerHashId}',
        );
        $this->mock(NiumService::class, function (MockInterface $mock) use ($calls, $context, $biometricUrl): void {
            $mock->shouldReceive('clientId')->once()->andReturn('client-id');
            $mock->shouldReceive('path')->once()->andReturnUsing(function (string $template, array $values) use ($context): string {
                $this->assertSame($context['account']->external_customer_id, $values['customer']);

                return "/api/v5/client/{$values['client']}/customer/{$values['customer']}";
            });
            $mock->shouldReceive('get')->once()->andReturnUsing(function () use ($calls, $context, $biometricUrl): Response {
                $calls->getCount++;

                return new Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                    'customerHashId' => $context['account']->external_customer_id,
                    'status' => 'pending',
                    'subStatus' => 'awaiting_kyc',
                    'applicant' => [
                        'entityType' => 'applicant',
                        'externalId' => $context['external_id'],
                        'referenceId' => $context['provider_reference_id'],
                        'kycMode' => 'biometric_kyc',
                        'kycStatus' => 'initiated',
                        'biometricUrl' => $biometricUrl,
                    ],
                ], JSON_THROW_ON_ERROR)));
            });
            $mock->shouldNotReceive('post');
        });

        $result = app(NiumHkManualSubmitKycService::class)->submit($context['user']);

        $this->assertSame(1, $calls->getCount);
        $this->assertSame('accepted', $result['state']);
        $this->assertSame('initiated', $result['kyc_status']);
        $this->assertSame($context['external_id'], $result['external_id']);
        $this->assertSame($context['provider_reference_id'], $result['provider_reference_id']);
        $this->assertSame($biometricUrl, $result['biometric_url']);
        $this->assertSame(substr(hash('sha256', $biometricUrl), 0, 16), $result['biometric_url_fingerprint']);
        $this->assertStringStartsWith('origin-wallet-manual-', $result['manual_reference_id']);
        $this->assertDatabaseCount('api_request_logs', 0);
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

    public function test_retry_submits_existing_applicant_once_and_preserves_the_previous_attempt(): void
    {
        $context = $this->context();
        $oldAttempt = [
            'state' => 'accepted',
            'kyc_status' => 'retry',
            'kyc_mode' => 'biometric_kyc',
            'entity_type' => 'applicant',
            'external_id' => $context['external_id'],
            'manual_reference_id' => 'origin-wallet-manual-old-attempt',
            'provider_reference_id' => $context['provider_reference_id'],
            'biometric_url' => 'https://verify.example.test/old-session',
            'updated_at' => now()->subMinute()->toISOString(),
        ];
        $metadata = $context['account']->metadata;
        $metadata['nium_entity_kyc_states']['ref_applicant']['kyc_status'] = 'retry';
        $metadata['nium_submit_kyc_attempts']['manual_old'] = $oldAttempt;
        $context['account']->forceFill(['metadata' => $metadata])->save();

        $newUrl = 'https://verify.example.test/new-retry-session';
        $calls = $this->mockResponse($context, [
            'biometricUrl' => $newUrl,
            'entityType' => 'applicant',
            'externalId' => $context['external_id'],
            'kycMode' => 'biometric_kyc',
            'kycStatus' => 'initiated',
            'referenceId' => $context['provider_reference_id'],
        ]);

        $first = app(NiumHkManualSubmitKycService::class)->submit($context['user'], 'admin-request-1');
        $second = app(NiumHkManualSubmitKycService::class)->submit($context['user'], 'admin-request-1');

        $this->assertSame(1, $calls->count);
        $this->assertSame($first, $second);
        $this->assertSame('applicant', $calls->payload['entityType']);
        $this->assertSame('biometric_kyc', $calls->payload['kycMode']);
        $this->assertSame($context['provider_reference_id'], $calls->payload['entityReferenceId']);
        $this->assertSame('passport', $calls->payload['proofOfIdentityDocument'][0]['type']);
        $this->assertSame('P123', $calls->payload['proofOfIdentityDocument'][0]['identificationNumber']);
        $this->assertSame($newUrl, $first['biometric_url']);
        $this->assertNotSame($oldAttempt['manual_reference_id'], $first['manual_reference_id']);

        $attempts = $context['account']->fresh()->metadata['nium_submit_kyc_attempts'];
        $this->assertCount(2, $attempts);
        $this->assertSame($oldAttempt, $attempts['manual_old']);
        $this->assertSame($newUrl, app(NiumCurrentBiometricKyc::class)->forUser($context['user'])['url']);
        $this->assertDatabaseCount('user_provider_accounts', 1);
    }

    public function test_retry_rejects_ambiguous_applicant_entities(): void
    {
        $context = $this->context();
        $metadata = $context['account']->metadata;
        $metadata['nium_entity_kyc_states']['ref_applicant']['kyc_status'] = 'retry';
        $metadata['nium_entity_kyc_states']['ref_ambiguous'] = [
            ...$metadata['nium_entity_kyc_states']['ref_applicant'],
            'provider_reference_id' => (string) Str::uuid(),
        ];
        $context['account']->forceFill(['metadata' => $metadata])->save();

        $this->mock(NiumService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('post'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No unique eligible Nium applicant entity');

        app(NiumHkManualSubmitKycService::class)->submit($context['user']);
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

    public function test_failed_manual_submit_attempt_is_recorded_with_internal_reference(): void
    {
        $context = $this->context();
        $calls = $this->mockResponse($context, [
            'errorCode' => 'invalid_input',
            'errors' => [['code' => 'invalid_input', 'field' => 'entityReferenceId']],
        ], 400);

        $result = app(NiumHkManualSubmitKycService::class)->submit($context['user']);

        $this->assertSame($context['provider_reference_id'], $calls->payload['entityReferenceId']);
        $this->assertStringStartsWith('origin-wallet-manual-', $calls->externalReference);
        $this->assertSame('rejected', $result['state']);
        $this->assertSame(400, $result['provider_http_status']);

        $attempt = collect($context['account']->fresh()->metadata['nium_submit_kyc_attempts'])->sole();
        $this->assertSame('rejected', $attempt['state']);
        $this->assertSame(400, $attempt['provider_http_status']);
        $this->assertSame($calls->externalReference, $attempt['manual_reference_id']);
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
                    'kyc_mode' => 'biometric_kyc',
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

    private function mockResponse(array $context, array $body, int $status = 200): object
    {
        $needsCustomerGet = $status >= 200 && $status < 300
            && ! filled($body['biometricUrl'] ?? null)
            && ! filled($body['redirectUrl'] ?? null);
        $calls = new class
        {
            public int $count = 0;

            public string $path = '';

            public array $payload = [];

            public string $externalReference = '';
        };

        $this->mock(NiumService::class, function (MockInterface $mock) use ($calls, $context, $body, $status, $needsCustomerGet): void {
            $mock->shouldReceive('clientId')->times($needsCustomerGet ? 2 : 1)->andReturn('client-id');
            $mock->shouldReceive('path')->times($needsCustomerGet ? 2 : 1)->andReturnUsing(function (string $template, array $values) use ($context): string {
                $this->assertSame($context['account']->external_customer_id, $values['customer']);

                return str_contains($template, 'submitKyc')
                    ? "/api/v5/client/{$values['client']}/customer/{$values['customer']}/submitKyc"
                    : "/api/v5/client/{$values['client']}/customer/{$values['customer']}";
            });
            $mock->shouldReceive('post')->once()->andReturnUsing(function (...$arguments) use ($calls, $context, $body, $status): Response {
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
                    'response_status' => $status,
                    'response_body' => app(NiumSafeValueProjector::class)->apiResponseBody($body, $status),
                    'is_success' => $status >= 200 && $status < 300,
                ]);

                return new Response(new \GuzzleHttp\Psr7\Response($status, [], json_encode($body, JSON_THROW_ON_ERROR)));
            });
            if ($needsCustomerGet) {
                $mock->shouldReceive('get')->once()->andReturn(new Response(
                    new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                        'customerHashId' => $context['account']->external_customer_id,
                        'status' => 'pending',
                        'subStatus' => 'awaiting_kyc',
                        'applicant' => [
                            'externalId' => 'origin-wallet-applicant-other',
                            'referenceId' => 'other-reference',
                            'biometricUrl' => 'https://verify.example.test/other-applicant',
                        ],
                    ], JSON_THROW_ON_ERROR)),
                ));
            }
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
