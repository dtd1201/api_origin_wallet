<?php

namespace Tests\Feature;

use App\Jobs\Nium\SubmitNiumHkEntityKycJob;
use App\Jobs\Nium\ContinueNiumCustomerOnboardingJob;
use App\Models\IntegrationProvider;
use App\Models\KycProfile;
use App\Models\KycProviderSubmission;
use App\Models\KycRelatedPerson;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use App\Services\Compliance\ComplianceEvidenceService;
use App\Services\Nium\NiumHkSubmitKycService;
use App\Services\Nium\NiumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class NiumHkProductionSubmitKycTest extends TestCase
{
    use RefreshDatabase;

    public function test_applicant_and_historical_external_ids_submit_exact_provider_evidence_once(): void
    {
        foreach (['origin-wallet-applicant-%d', 'origin-wallet-person-%d'] as $format) {
            $context = $this->context('applicant', $format);
            $calls = $this->mockResponse($context, $this->validResponse($context));
            $this->assertSame('accepted', app(NiumHkSubmitKycService::class)->submit($context['event']));
            $this->assertSame(1, $calls->count);
            $this->assertSame('applicant', $calls->payload['entityType']);
            $this->assertSame($context['reference'], $calls->payload['entityReferenceId']);
        }
    }

    public function test_stakeholder_uses_exact_entity_type_and_reference(): void
    {
        $context = $this->context('individual_stakeholder', 'origin-wallet-stakeholder-%d');
        $calls = $this->mockResponse($context, $this->validResponse($context));
        $this->assertSame('accepted', app(NiumHkSubmitKycService::class)->submit($context['event']));
        $this->assertSame('individual_stakeholder', $calls->payload['entityType']);
        $this->assertSame($context['reference'], $calls->payload['entityReferenceId']);
    }

    public function test_hk_authorized_representative_and_two_owners_produce_three_eligible_entities(): void
    {
        $context = $this->context();
        $context['person']->forceFill(['relationship_type' => 'authorized_representative', 'ownership_percentage' => null])->save();
        $owners = collect([[60, 'P456'], [40, 'P789']])->map(function ($item) use ($context) {
            $owner = $context['profile']->relatedPersons()->create(['relationship_type' => 'beneficial_owner', 'status' => 'approved', 'legal_name' => 'Owner '.$item[0], 'ownership_percentage' => $item[0], 'residence_country_code' => 'VN']);
            $owner->documents()->create(['kyc_profile_id' => $context['profile']->id, 'type' => 'passport', 'status' => 'approved', 'document_number' => $item[1], 'issuing_country_code' => 'VN', 'expires_at' => '2099-12-31', 'file_url' => 'private://passport-'.$item[0]]);
            return $owner;
        });
        $entityIds = ['origin-wallet-applicant-'.$context['person']->id, 'origin-wallet-stakeholder-'.$owners[0]->id, 'origin-wallet-stakeholder-'.$owners[1]->id];
        $calls = new class { public array $payloads = []; };
        $this->mock(NiumService::class, function (MockInterface $mock) use ($calls): void {
            $mock->shouldReceive('clientId')->andReturn('client');
            $mock->shouldReceive('path')->andReturn('/submitKyc');
            $mock->shouldReceive('post')->times(3)->andReturnUsing(function (...$arguments) use ($calls): Response {
                $payload = $arguments[1]; $calls->payloads[] = $payload;
                return new Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                    'kycStatus' => 'initiated', 'kycMode' => 'biometric_kyc',
                    'entityType' => $payload['entityType'], 'referenceId' => fake()->uuid(),
                    'externalId' => $payload['entityReferenceId'], 'redirectUrl' => 'https://redirect.test/session',
                ], JSON_THROW_ON_ERROR)));
            });
        });
        $service = app(NiumHkSubmitKycService::class);
        $event = $this->event($context, 'origin-wallet-applicant-'.$context['person']->id, $context['reference']);
        $result = $service->submitAwaitingKyc($event);
        $service->submitAwaitingKyc($event->fresh());
        $this->assertCount(3, $calls->payloads);
        $this->assertSame($entityIds, array_column($calls->payloads, 'entityReferenceId'));
        $this->assertSame(['applicant', 'individual_stakeholder', 'individual_stakeholder'], array_column($calls->payloads, 'entityType'));
        $this->assertCount(3, $result);
        $attempts = (array) $context['account']->fresh()->metadata['nium_submit_kyc_attempts'];
        $this->assertCount(3, $attempts);
        $this->assertNotSame(array_keys($attempts)[0], array_keys($attempts)[1]);
    }

    public function test_downstream_entity_status_reconciles_existing_attempt_without_posting(): void
    {
        $context = $this->context();
        $this->mockResponse($context, $this->validResponse($context));
        $service = app(NiumHkSubmitKycService::class);
        $service->submit($context['event']);
        foreach (['submitted', 'verified', 'failed'] as $status) {
            $event = $this->event($context, $context['external_id'], $context['reference']);
            $event->forceFill(['payload' => [...$event->payload, 'kycStatus' => $status, 'referenceId' => 'provider-ref-1']])->save();
            $service->reconcileEntityWebhook($event->fresh());
        }
        $attempt = collect((array) $context['account']->fresh()->metadata['nium_submit_kyc_attempts'])->first();
        $this->assertSame('failed', $attempt['entity_kyc_status']);
        $this->assertSame('provider-ref-1', $attempt['provider_reference_id']);
    }

    public function test_duplicate_event_and_direct_reinvocation_are_harmless_no_ops(): void
    {
        $context = $this->context();
        $calls = $this->mockResponse($context, $this->validResponse($context));
        $service = app(NiumHkSubmitKycService::class);
        $this->assertSame('accepted', $service->submit($context['event']));
        $this->assertSame('already_processed', $service->submit($context['event']->fresh()));
        $duplicate = $this->event($context, $context['external_id'], $context['reference']);
        $this->assertSame('already_processed', $service->submit($duplicate));
        $this->assertSame(1, $calls->count);
    }

    #[DataProvider('invalidExternalIds')]
    public function test_invalid_external_id_mapping_never_posts(string $entityType, string $externalId): void
    {
        $context = $this->context($entityType);
        $event = $this->event($context, sprintf($externalId, $context['person']->id), $context['reference']);
        $this->mockNoPost();
        $this->expectException(RuntimeException::class);
        app(NiumHkSubmitKycService::class)->submit($event);
    }

    public static function invalidExternalIds(): array
    {
        return [
            ['individual_stakeholder', 'origin-wallet-applicant-%d'],
            ['applicant', 'origin-wallet-stakeholder-%d'],
            ['applicant', 'origin-wallet-applicant-999999'],
            ['applicant', 'malformed-%d'],
        ];
    }

    #[DataProvider('unsafeAccountFields')]
    public function test_unsafe_provider_account_never_posts(string $field, mixed $value): void
    {
        $context = $this->context();
        $context['account']->forceFill([$field => $value])->save();
        $this->mockNoPost();
        $this->expectException(RuntimeException::class);
        app(NiumHkSubmitKycService::class)->submit($context['event']);
    }

    public static function unsafeAccountFields(): array
    {
        return [
            ['external_customer_id', null], ['customer_id_verified_at', null],
            ['reconciliation_status', 'pending'], ['security_conflict_at', '2026-01-01 00:00:00'],
            ['security_conflict_reason', 'identifier_mismatch'],
        ];
    }

    #[DataProvider('providerOutcomes')]
    public function test_provider_outcomes_are_terminal(string $expected, int|string $status, array $body): void
    {
        $context = $this->context();
        $calls = $status === 'connection'
            ? $this->mockConnectionFailure()
            : $this->mockResponse($context, $body === [] ? $this->validResponse($context) : $body, $status);
        $service = app(NiumHkSubmitKycService::class);
        $this->assertSame($expected, $service->submit($context['event']));
        $this->assertSame('already_processed', $service->submit($context['event']->fresh()));
        $this->assertSame(1, $calls->count);
    }

    public static function providerOutcomes(): array
    {
        return [
            'accepted' => ['accepted', 200, []],
            'rejected' => ['rejected', 400, ['error' => 'invalid']],
            'unknown' => ['unknown', 'connection', []],
            'wrong entity' => ['response_review', 200, ['entityType' => 'individual_stakeholder']],
            'malformed success' => ['response_review', 200, ['kycStatus' => 'initiated']],
        ];
    }

    public function test_redirect_url_is_only_fingerprinted_in_new_service_persistence(): void
    {
        $context = $this->context();
        $secret = 'https://redirect.example.test/unique-secret-token';
        $body = $this->validResponse($context, ['redirectUrl' => $secret]);
        $this->mockResponse($context, $body);
        app(NiumHkSubmitKycService::class)->submit($context['event']);
        $serialized = json_encode($context['account']->fresh()->metadata, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($secret, $serialized);
        $this->assertStringContainsString(substr(hash('sha256', $secret), 0, 16), $serialized);
    }

    public function test_job_has_one_try_and_duplicate_execution_is_safe(): void
    {
        $context = $this->context();
        $calls = $this->mockResponse($context, $this->validResponse($context));
        $job = new SubmitNiumHkEntityKycJob($context['event']->id);
        $this->assertSame(1, $job->tries);
        $job->handle(app(NiumHkSubmitKycService::class));
        $job->handle(app(NiumHkSubmitKycService::class));
        $this->assertSame(1, $calls->count);
    }

    public function test_submission_timestamp_is_set_once(): void
    {
        $context = $this->context();
        $submission = KycProviderSubmission::query()->create([
            'user_id' => $context['user']->id, 'provider_id' => $context['provider']->id,
            'kyc_profile_id' => $context['profile']->id, 'status' => 'approved',
        ]);
        $service = app(ComplianceEvidenceService::class);
        $first = $service->markNiumSubmissionSubmitted($submission, $context['account']->id)->submitted_at;
        $this->travel(5)->minutes();
        $second = $service->markNiumSubmissionSubmitted($submission->fresh(), $context['account']->id)->submitted_at;
        $this->assertTrue($first->equalTo($second));
    }

    public function test_continue_job_repairs_submitted_without_timestamp_and_preserves_it(): void
    {
        $context = $this->context();
        $submission = KycProviderSubmission::query()->create([
            'user_id' => $context['user']->id, 'provider_id' => $context['provider']->id,
            'kyc_profile_id' => $context['profile']->id, 'provider_account_id' => $context['account']->id,
            'status' => 'submitted', 'submitted_at' => null,
        ]);
        $job = new ContinueNiumCustomerOnboardingJob($context['user']->id, $context['provider']->id);
        $job->handle(app(\App\Services\Nium\NiumCustomerDocumentPreparationService::class), app(\App\Services\Nium\NiumCustomerOnboardingService::class), app(ComplianceEvidenceService::class));
        $first = $submission->fresh()->submitted_at;
        $this->assertNotNull($first);
        $job->handle(app(\App\Services\Nium\NiumCustomerDocumentPreparationService::class), app(\App\Services\Nium\NiumCustomerOnboardingService::class), app(ComplianceEvidenceService::class));
        $this->assertTrue($first->equalTo($submission->fresh()->submitted_at));
    }

    private function context(string $entityType = 'applicant', string $externalFormat = 'origin-wallet-applicant-%d'): array
    {
        $provider = IntegrationProvider::query()->firstOrCreate(
            ['code' => 'nium'],
            ['name' => 'Nium', 'status' => 'active'],
        );
        $user = User::factory()->create();
        $profile = KycProfile::query()->create([
            'user_id' => $user->id, 'status' => 'approved', 'applicant_type' => 'business',
            'legal_name' => 'HK Test Ltd', 'address_line1' => '1 Test Road', 'city' => 'Hong Kong',
            'country_code' => 'HK', 'metadata' => ['nium_region' => 'HK', 'nium_kyc_type' => 'full'],
        ]);
        $person = $profile->relatedPersons()->create([
            'relationship_type' => $entityType === 'applicant' ? 'applicant' : 'beneficial_owner',
            'status' => 'approved', 'legal_name' => 'Test Person', 'ownership_percentage' => 100,
            'residence_country_code' => 'VN',
        ]);
        $person->documents()->create([
            'kyc_profile_id' => $profile->id, 'type' => 'passport', 'status' => 'approved',
            'document_number' => 'P123', 'issuing_country_code' => 'HK', 'expires_at' => '2099-12-31',
            'file_url' => 'private://passport',
        ]);
        $account = UserProviderAccount::query()->create([
            'user_id' => $user->id, 'provider_id' => $provider->id, 'external_customer_id' => 'customer-'.$user->id,
            'customer_id_verified_at' => now(), 'reconciliation_status' => 'reconciled',
            'status' => 'pending', 'provider_status' => 'pending', 'provider_sub_status' => 'awaiting_kyc',
        ]);
        $reference = fake()->uuid();
        $externalId = sprintf($externalFormat, $person->id);
        $context = compact('provider', 'user', 'profile', 'person', 'account', 'reference', 'externalId', 'entityType');
        $context['external_id'] = $externalId;
        $context['event'] = $this->event($context, $externalId, $reference);
        return $context;
    }

    private function event(array $context, string $externalId, string $reference): WebhookEvent
    {
        return WebhookEvent::query()->create([
            'provider_id' => $context['provider']->id, 'event_id' => fake()->uuid(),
            'event_type' => 'CUSTOMER_ENTITY_KYC_STATUS', 'external_resource_id' => 'customer-123',
            'payload' => ['customerHashId' => $context['account']->external_customer_id, 'externalId' => $externalId,
                'entityType' => $context['entityType'], 'referenceId' => $reference, 'kycStatus' => 'kyc_required'],
            'processing_status' => 'processed', 'processed_at' => now(),
        ]);
    }

    private function validResponse(array $context, array $overrides = []): array
    {
        return array_merge(['kycStatus' => 'initiated', 'kycMode' => 'biometric_kyc',
            'entityType' => $context['entityType'], 'referenceId' => $context['reference'],
            'externalId' => $context['external_id'], 'redirectUrl' => 'https://redirect.example.test/session'], $overrides);
    }

    private function mockResponse(array $context, array $body, int $status = 200): object
    {
        $calls = new class { public int $count = 0; public array $payload = []; };
        $this->mock(NiumService::class, function (MockInterface $mock) use ($calls, $body, $status): void {
            $mock->shouldReceive('clientId')->andReturn('client');
            $mock->shouldReceive('path')->andReturn('/submitKyc');
            $mock->shouldReceive('post')->once()->andReturnUsing(function (...$arguments) use ($calls, $body, $status): Response {
                $calls->count++; $calls->payload = $arguments[1];
                return new Response(new \GuzzleHttp\Psr7\Response($status, [], json_encode($body, JSON_THROW_ON_ERROR)));
            });
        });
        return $calls;
    }

    private function mockConnectionFailure(): object
    {
        $calls = new class { public int $count = 0; };
        $this->mock(NiumService::class, function (MockInterface $mock) use ($calls): void {
            $mock->shouldReceive('clientId')->andReturn('client'); $mock->shouldReceive('path')->andReturn('/submitKyc');
            $mock->shouldReceive('post')->once()->andReturnUsing(function () use ($calls): never {
                $calls->count++; throw new ConnectionException('uncertain');
            });
        });
        return $calls;
    }

    private function mockNoPost(): void
    {
        $this->mock(NiumService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('post'));
    }
}
