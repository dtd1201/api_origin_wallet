<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\KycDocument;
use App\Models\KycProfile;
use App\Models\KycRelatedPerson;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use App\Services\Nium\NiumEvidencePersistenceException;
use App\Services\Nium\NiumHkStakeholderSubmitKycGenerationEightOneShotRunner;
use App\Services\Nium\NiumHkSubmitKycPayloadFactory;
use App\Services\Nium\NiumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class NiumHkStakeholderSubmitKycGenerationEightOneShotRunnerTest extends TestCase
{
    use RefreshDatabase;

    private const REFERENCE = '7609d9d1-9d37-4e08-9197-602d792f7a2e';
    private const IDENTITY = 'factual-passport-value-never-logged';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.nium.customer_submit_kyc_endpoint' => '/api/v5/client/{clientHashId}/customer/{customerHashId}/submitKyc']);
        $this->seedEvidence();
        $this->seedSession117();
        $this->mock(NiumService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('post'));
    }

    public function test_exact_g8_payload_and_historical_distinctions(): void
    {
        $payload = $this->payload();

        $this->assertSame('individual_stakeholder', $payload['entityType']);
        $this->assertSame('MANUAL_KYC', $payload['kycMode']);
        $this->assertSame('HK', $payload['region']);
        $this->assertArrayNotHasKey('isResident', $payload);
        $this->assertSame(self::REFERENCE, $payload['entityReferenceId']);
        $this->assertTrue(array_is_list($payload['proofOfIdentityDocument']));
        $this->assertCount(1, $payload['proofOfIdentityDocument']);
        $this->assertSame(
            ['type', 'fileIds', 'identificationNumber', 'expiryDate', 'issuanceCountry'],
            array_keys($payload['proofOfIdentityDocument'][0]),
        );
        $this->assertSame('passport', $payload['proofOfIdentityDocument'][0]['type']);
        $this->assertSame(['30000000-0000-4000-8000-000000000027'], $payload['proofOfIdentityDocument'][0]['fileIds']);
        $this->assertSame(self::IDENTITY, $payload['proofOfIdentityDocument'][0]['identificationNumber']);
        $this->assertSame('VN', $payload['proofOfIdentityDocument'][0]['issuanceCountry']);
        $this->assertFalse(array_is_list($payload['proofOfAddressDocument']));
        $this->assertSame(['type', 'fileIds'], array_keys($payload['proofOfAddressDocument']));
        $this->assertSame('proof_of_address', $payload['proofOfAddressDocument']['type']);
        $this->assertSame(['30000000-0000-4000-8000-000000000028'], $payload['proofOfAddressDocument']['fileIds']);

        $g8 = [$payload['entityType'], $payload['kycMode'], array_key_exists('isResident', $payload)];
        $this->assertNotSame(['INDIVIDUAL_STAKEHOLDER', 'MANUAL_KYC', false], $g8);
        $this->assertNotSame(['individual_stakeholder', 'manual_kyc', false], $g8);
        $this->assertNotSame(['INDIVIDUAL_STAKEHOLDER', 'manual_kyc', false], $g8);
    }

    public function test_offline_audit_reports_session_117_conflict_without_writes(): void
    {
        $this->seedSession117();
        $before = $this->immutableEvidence();

        $result = $this->runner()->audit();

        $this->assertSame('HOLD_PREBUILT_SESSION_CONFLICT', $result['terminal']);
        $this->assertTrue($result['prebuilt_session_117_present']);
        $this->assertTrue($result['prebuilt_session_claim_present']);
        $this->assertSame('created', $result['prebuilt_session_state']);
        $this->assertTrue($result['session_id_present']);
        $this->assertFalse($result['session_expired_locally_proven']);
        $this->assertFalse($result['provider_completion_known']);
        $this->assertFalse($result['direct_submit_coexistence_provider_confirmed']);
        $this->assertSame(0, $result['g8_post_count']);
        $this->assertNull($result['g8_claim_state']);
        $this->assertSame($before, $this->immutableEvidence());
    }

    public function test_session_117_blocks_before_claim_and_http(): void
    {
        $this->seedSession117();

        try {
            $this->runner()->run(true);
            $this->fail('Expected pre-built session conflict.');
        } catch (RuntimeException $exception) {
            $this->assertSame('HOLD_PREBUILT_SESSION_CONFLICT', $exception->getMessage());
        }

        $this->assertArrayNotHasKey('nium_stakeholder_submit_kyc_retry_generation_8', UserProviderAccount::findOrFail(7)->metadata);
        $this->assertSame(6, $this->stakeholderPosts());
    }

    public function test_both_human_approvals_are_required(): void
    {
        foreach ([[false, false], [true, false]] as [$approval, $override]) {
            try {
                $this->runner()->run($approval, $override);
                $this->fail('Expected missing human approval to block G8.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString($approval ? 'PREBUILT_SESSION' : 'human approval', $exception->getMessage());
            }
            $this->assertArrayNotHasKey(
                'nium_stakeholder_submit_kyc_retry_generation_8',
                UserProviderAccount::findOrFail(7)->metadata,
            );
        }
    }

    #[DataProvider('generationSevenEvidenceMutationProvider')]
    public function test_exact_log_118_provider_evidence_is_required_before_claim(string $mutation): void
    {
        $log = ApiRequestLog::find(118);
        match ($mutation) {
            'missing' => $log?->delete(),
            'wrong_status' => $log?->forceFill(['response_status' => 200, 'is_success' => true])->save(),
            'wrong_transport' => $log?->forceFill(['transport_outcome' => 'ambiguous'])->save(),
            'wrong_reference' => $log?->forceFill(['external_reference' => 'wrong-reference'])->save(),
            'wrong_error' => $log?->forceFill(['response_body' => ['error_code' => 'invalid_input']])->save(),
            'extra_log' => $this->createLog(119, self::REFERENCE, 400, false, ['error_code' => 'invalid_input']),
        };

        try {
            $this->runner()->run(true, true);
            $this->fail("Expected {$mutation} #118 evidence to block G8.");
        } catch (RuntimeException) {
            $this->assertArrayNotHasKey(
                'nium_stakeholder_submit_kyc_retry_generation_8',
                UserProviderAccount::findOrFail(7)->metadata,
            );
        }
    }

    public static function generationSevenEvidenceMutationProvider(): array
    {
        return array_combine(
            ['missing', 'wrong status', 'wrong transport', 'wrong reference', 'wrong error', 'extra log'],
            array_map(static fn (string $mutation): array => [$mutation], [
                'missing', 'wrong_status', 'wrong_transport', 'wrong_reference', 'wrong_error', 'extra_log',
            ]),
        );
    }

    public function test_expired_session_override_requires_valid_local_evidence(): void
    {
        foreach ([
            'missing_log', 'wrong_operation', 'wrong_status', 'wrong_transport',
            'missing_claim', 'invalid_expiry', 'future_expiry',
        ] as $mutation) {
            $this->mutateSessionEvidence($mutation);

            try {
                $this->runner()->run(true, true);
                $this->fail("Expected invalid override evidence for {$mutation}.");
            } catch (RuntimeException $exception) {
                $this->assertStringStartsWith('HOLD_', $exception->getMessage());
            }
            $this->assertArrayNotHasKey(
                'nium_stakeholder_submit_kyc_retry_generation_8',
                UserProviderAccount::findOrFail(7)->metadata,
            );

            $this->seedSession117();
        }
    }

    public function test_customer_completion_evidence_blocks_before_claim_and_http(): void
    {
        WebhookEvent::findOrFail(7)->forceFill([
            'payload' => ['status' => 'clear', 'subStatus' => ''],
        ])->save();

        $this->assertCompletionEvidenceBlocksG7();
    }

    public function test_earlier_completion_evidence_blocks_even_when_latest_status_is_pending(): void
    {
        WebhookEvent::query()->forceCreate([
            'id' => 8, 'provider_id' => 1, 'event_id' => 'earlier-clear',
            'event_type' => 'CUSTOMER_STATUS_WEBHOOK', 'external_resource_id' => 'customer-safe-id',
            'payload' => ['status' => 'clear', 'subStatus' => null],
            'processing_status' => 'processed', 'processed_at' => now()->subMinute(),
        ]);

        $this->assertCompletionEvidenceBlocksG7();
    }

    public function test_entity_completion_evidence_is_not_authoritative_for_customer_completion(): void
    {
        WebhookEvent::query()->forceCreate([
            'id' => 8, 'provider_id' => 1, 'event_id' => 'entity-clear',
            'event_type' => 'CUSTOMER_ENTITY_KYC_STATUS', 'external_resource_id' => self::REFERENCE,
            'payload' => ['status' => 'clear', 'subStatus' => null],
            'processing_status' => 'processed', 'processed_at' => now()->addSecond(),
        ]);
        $this->mockResponse(200, [
            'kycStatus' => 'initiated', 'kycMode' => 'MANUAL_KYC',
            'entityType' => 'individual_stakeholder', 'referenceId' => self::REFERENCE,
        ]);

        $result = $this->runner()->run(true, true);

        $this->assertSame('PASS_KYC_INITIATED', $result['terminal']);
        $this->assertSame('NO_AUTOMATIC_G9', $result['next_generation']);
        $this->assertSame(1, $result['g8_post_count']);
    }

    public function test_ambiguous_historical_claim_and_invalid_g7_claim_block_before_g8_claim(): void
    {
        foreach (['historical_unknown', 'g7_invalid'] as $mutation) {
            $account = UserProviderAccount::findOrFail(7);
            $metadata = $this->historicalClaims();
            if ($mutation === 'historical_unknown') {
                $metadata['nium_stakeholder_submit_kyc_retry_generation_4']['state'] = 'unknown';
            } else {
                $metadata['nium_stakeholder_submit_kyc_retry_generation_7']['provider_http_status'] = 200;
            }
            $account->forceFill(['metadata' => $metadata])->save();

            try {
                $this->runner()->run(true, true);
                $this->fail("Expected {$mutation} to block G8.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString(
                    $mutation === 'historical_unknown' ? 'ambiguous or inconsistent' : 'generation #7 rejected claim',
                    $exception->getMessage(),
                );
            }
            $this->assertArrayNotHasKey(
                'nium_stakeholder_submit_kyc_retry_generation_8',
                UserProviderAccount::findOrFail(7)->metadata,
            );
        }
    }

    public function test_claim_precedes_single_post_and_uses_new_generation_key(): void
    {
        $before = $this->immutableEvidence();
        $this->mockResponse(200, [
            'kycStatus' => 'initiated', 'kycMode' => 'MANUAL_KYC',
            'entityType' => 'individual_stakeholder', 'referenceId' => self::REFERENCE,
        ], function (array $payload): void {
            $metadata = UserProviderAccount::findOrFail(7)->metadata;
            $this->assertSame('submitting', $metadata['nium_stakeholder_submit_kyc_retry_generation_8']['state']);
            $this->assertSame(8, $metadata['nium_stakeholder_submit_kyc_retry_generation_8']['generation']);
            $this->assertSame(117, $metadata['nium_stakeholder_submit_kyc_retry_generation_8']['prebuilt_session_log_id']);
            $this->assertSame(
                'human_verified_provider_ui_expired',
                $metadata['nium_stakeholder_submit_kyc_retry_generation_8']['expired_prebuilt_session_override'],
            );
            $this->assertSame('rejected', $metadata['nium_stakeholder_submit_kyc_retry_generation_7']['state']);
            $this->assertSame('individual_stakeholder', $payload['entityType']);
            $this->assertSame('MANUAL_KYC', $payload['kycMode']);
            $this->assertArrayNotHasKey('isResident', $payload);
        });

        $result = $this->runner()->run(true, true);

        $this->assertSame('PASS_KYC_INITIATED', $result['terminal']);
        $this->assertSame(1, $result['g8_post_count']);
        $this->assertSame(0, $result['applicant_submit_kyc_post_count_change']);
        $this->assertSame($before, $this->immutableEvidence());
        $this->assertReplayBlocked(1);
    }

    public function test_locked_claim_rebuilds_payload_and_persists_its_exact_fingerprint(): void
    {
        $runner = $this->runner();
        $preflight = new \ReflectionMethod($runner, 'preflight');
        $claim = new \ReflectionMethod($runner, 'claim');
        $preliminary = $preflight->invoke($runner, true, true);
        $preliminary['payload']['kycMode'] = 'pre-lock-payload-must-not-survive';

        $context = $claim->invoke($runner, $preliminary);

        $this->assertSame('MANUAL_KYC', $context['payload']['kycMode']);
        $this->assertSame('individual_stakeholder', $context['payload']['entityType']);
        $this->assertArrayNotHasKey('isResident', $context['payload']);
        $fingerprint = substr(hash('sha256', json_encode($context['payload'], JSON_THROW_ON_ERROR)), 0, 16);
        $this->assertSame($fingerprint, $context['payload_fingerprint']);
        $this->assertSame(
            $fingerprint,
            UserProviderAccount::findOrFail(7)->metadata['nium_stakeholder_submit_kyc_retry_generation_8']['contract_fingerprint'],
        );
    }

    public function test_4xx_5xx_and_malformed_response_permanently_block_replay(): void
    {
        foreach ([[400, []], [500, []], [200, []]] as [$status, $body]) {
            $this->mockResponse($status, $body);
            $result = $this->runner()->run(true, true);
            $this->assertSame(match ($status) {
                400 => 'STOP_KYC_REJECTED_NO_RETRY',
                500 => 'STOP_KYC_OUTCOME_UNKNOWN_NO_RETRY',
                default => 'HOLD_RESPONSE_REVIEW',
            }, $result['terminal']);
            $this->assertSame(match ($status) {
                400 => 'rejected',
                500 => 'unknown',
                default => 'response_review',
            }, UserProviderAccount::findOrFail(7)->metadata['nium_stakeholder_submit_kyc_retry_generation_8']['state']);
            $this->assertReplayBlocked(1);
            $this->resetG7();
        }
    }

    #[DataProvider('atomicMutationProvider')]
    public function test_locked_claim_revalidates_preliminary_state_before_http(string $mutation): void
    {
        $runner = $this->runner();
        $preflight = new \ReflectionMethod($runner, 'preflight');
        $claim = new \ReflectionMethod($runner, 'claim');
        $preliminary = $preflight->invoke($runner, true, true);

        $this->applyAtomicMutation($mutation);

        try {
            $claim->invoke($runner, $preliminary);
            $this->fail("Expected locked revalidation failure for {$mutation}.");
        } catch (\ReflectionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->assertInstanceOf(RuntimeException::class, $exception);
        }

        $claimMetadata = UserProviderAccount::findOrFail(7)->metadata['nium_stakeholder_submit_kyc_retry_generation_8'] ?? null;
        if ($mutation === 'concurrent_g8_claim') {
            $this->assertSame('submitting', $claimMetadata['state'] ?? null);
            $this->assertSame('concurrent', $claimMetadata['owner'] ?? null);
        } else {
            $this->assertNull($claimMetadata);
        }
        $this->assertSame(6 + ($mutation === 'extra_history' ? 1 : 0), $this->stakeholderPosts());
    }

    public static function atomicMutationProvider(): array
    {
        return [
            'account customer identifier' => ['account_customer'],
            'person identity metadata' => ['person_identity'],
            'document relationship' => ['document_relationship'],
            'document availability' => ['document_availability'],
            'extra stakeholder history' => ['extra_history'],
            'stakeholder webhook mapping' => ['webhook_mapping'],
            'concurrent G8 claim' => ['concurrent_g8_claim'],
            'pre-built conflict' => ['prebuilt_conflict'],
        ];
    }

    public function test_connection_timeout_and_evidence_persistence_ambiguity_block_replay(): void
    {
        foreach ([
            new ConnectionException('timeout'),
            new ConnectionException('ambiguous'),
            new NiumEvidencePersistenceException(['operation' => 'submit_kyc', 'outcome' => 'persistence_uncertain']),
        ] as $throwable) {
            $this->mockThrowable($throwable);
            $result = $this->runner()->run(true, true);
            $this->assertSame('STOP_KYC_OUTCOME_UNKNOWN_NO_RETRY', $result['terminal']);
            $this->assertReplayBlocked(0);
            $this->resetG7();
        }
    }

    public function test_account_person_documents_applicant_and_account_4_are_locked(): void
    {
        $before = $this->immutableEvidence();
        $result = $this->runner()->audit();

        $this->assertSame('HOLD_PREBUILT_SESSION_CONFLICT', $result['terminal']);
        $this->assertSame(27, $result['identity_document_id']);
        $this->assertSame(28, $result['poa_document_id']);
        $this->assertTrue($result['account_4_immutable']);
        $this->assertSame($before, $this->immutableEvidence());

        KycRelatedPerson::query()->forceCreate([
            'id' => 13, 'kyc_profile_id' => 9, 'relationship_type' => 'director', 'status' => 'approved',
            'legal_name' => 'Wrong Person', 'metadata' => [],
        ]);
        KycDocument::findOrFail(27)->forceFill(['kyc_related_person_id' => 13])->save();
        $this->expectException(RuntimeException::class);
        $this->runner()->audit();
    }

    public function test_no_simulation_or_non_nium_operations_are_created(): void
    {
        $this->runner()->audit();
        $this->assertSame(0, ApiRequestLog::query()->whereIn('operation', [
            'onboarding_simulation_submit_kyc', 'file_create', 'file_details', 'van', 'beneficiary', 'payout',
        ])->count());
    }

    private function mockResponse(int $status, array $body, ?callable $assertPayload = null): void
    {
        $this->mock(NiumService::class, function (MockInterface $mock) use ($status, $body, $assertPayload): void {
            $mock->shouldReceive('clientId')->andReturn('safe-client');
            $mock->shouldReceive('path')->andReturn('/safe/submitKyc');
            $mock->shouldReceive('post')->once()->andReturnUsing(function (string $path, array $payload, User $user, $related, string $operation, string $reference) use ($status, $body, $assertPayload): Response {
                $this->assertSame('/safe/submitKyc', $path);
                $this->assertSame(9, $user->id);
                $this->assertSame('submit_kyc', $operation);
                $this->assertSame(self::REFERENCE, $reference);
                $assertPayload?->__invoke($payload);
                $this->createLog(119, self::REFERENCE, $status, $status < 400, $body);
                return new Response(new \GuzzleHttp\Psr7\Response($status, [], json_encode($body, JSON_THROW_ON_ERROR)));
            });
        });
    }

    private function mockThrowable(\Throwable $throwable): void
    {
        $this->mock(NiumService::class, function (MockInterface $mock) use ($throwable): void {
            $mock->shouldReceive('clientId')->andReturn('safe-client');
            $mock->shouldReceive('path')->andReturn('/safe/submitKyc');
            $mock->shouldReceive('post')->once()->andReturnUsing(function () use ($throwable): never {
                $this->assertSame('submitting', UserProviderAccount::findOrFail(7)->metadata['nium_stakeholder_submit_kyc_retry_generation_8']['state']);
                throw $throwable;
            });
        });
    }

    private function assertReplayBlocked(int $posts): void
    {
        try {
            $this->runner()->run(true, true);
            $this->fail('Expected G8 replay block.');
        } catch (RuntimeException $exception) {
            $this->assertSame('HOLD_GENERATION_8_ALREADY_CLAIMED', $exception->getMessage());
        }
        $this->assertSame(6 + $posts, $this->stakeholderPosts());
        $this->assertSame(1, ApiRequestLog::query()->whereKey(104)->count());
    }

    private function assertCompletionEvidenceBlocksG7(): void
    {
        try {
            $this->runner()->run(true, true);
            $this->fail('Expected authoritative customer completion evidence to block G8.');
        } catch (RuntimeException $exception) {
            $this->assertSame('HOLD_KYC_COMPLETION_EVIDENCE_PRESENT', $exception->getMessage());
        }

        $this->assertArrayNotHasKey(
            'nium_stakeholder_submit_kyc_retry_generation_8',
            UserProviderAccount::findOrFail(7)->metadata,
        );
        $this->assertSame(6, $this->stakeholderPosts());
    }

    private function resetG7(): void
    {
        ApiRequestLog::query()->where('id', '>', 118)->delete();
        $account = UserProviderAccount::findOrFail(7);
        $metadata = $account->metadata;
        unset($metadata['nium_stakeholder_submit_kyc_retry_generation_8']);
        $account->forceFill(['metadata' => $metadata])->save();
    }

    private function applyAtomicMutation(string $mutation): void
    {
        if ($mutation === 'account_customer') {
            UserProviderAccount::findOrFail(7)->forceFill(['external_customer_id' => 'changed-customer'])->save();
            return;
        }
        if ($mutation === 'person_identity') {
            $person = KycRelatedPerson::findOrFail(14);
            $person->forceFill(['metadata' => [
                ...$person->metadata,
                'nium_biometric_identity' => [
                    ...$person->metadata['nium_biometric_identity'],
                    'expiry_date' => '2000-01-01',
                ],
            ]])->save();
            return;
        }
        if ($mutation === 'document_relationship') {
            KycRelatedPerson::query()->forceCreate([
                'id' => 13, 'kyc_profile_id' => 9, 'relationship_type' => 'director', 'status' => 'approved',
                'legal_name' => 'Wrong Person', 'metadata' => [],
            ]);
            KycDocument::findOrFail(27)->forceFill(['kyc_related_person_id' => 13])->save();
            return;
        }
        if ($mutation === 'document_availability') {
            $document = KycDocument::findOrFail(27);
            $document->forceFill(['metadata' => [...$document->metadata, 'nium_file_state' => 'PROCESSING']])->save();
            return;
        }
        if ($mutation === 'extra_history') {
            $this->createLog(119, self::REFERENCE, 400, false, ['error_code' => 'invalid_input']);
            return;
        }
        if ($mutation === 'webhook_mapping') {
            $event = WebhookEvent::findOrFail(6);
            $event->forceFill(['payload' => [...$event->payload, 'externalId' => 'wrong-person']])->save();
            return;
        }

        $account = UserProviderAccount::findOrFail(7);
        $metadata = $account->metadata;
        if ($mutation === 'concurrent_g8_claim') {
            $metadata['nium_stakeholder_submit_kyc_retry_generation_8'] = ['state' => 'submitting', 'owner' => 'concurrent'];
        } elseif ($mutation === 'prebuilt_conflict') {
            $metadata['nium_kyc_prebuilt_form_session'] = ['state' => 'created'];
        }
        $account->forceFill(['metadata' => $metadata])->save();
    }

    private function payload(): array
    {
        return app(NiumHkSubmitKycPayloadFactory::class)->buildManualGenerationEight(
            KycRelatedPerson::findOrFail(14), self::REFERENCE,
            KycDocument::findOrFail(27), KycDocument::findOrFail(28),
        );
    }

    private function runner(): NiumHkStakeholderSubmitKycGenerationEightOneShotRunner
    {
        return app(NiumHkStakeholderSubmitKycGenerationEightOneShotRunner::class);
    }

    private function stakeholderPosts(): int
    {
        return ApiRequestLog::query()->where('operation', 'submit_kyc')->where('external_reference', self::REFERENCE)->count();
    }

    private function immutableEvidence(): array
    {
        return [
            'logs' => ApiRequestLog::query()->whereIn('id', [104, 106, 113, 114, 115, 116, 117, 118])->orderBy('id')->get()->map->getRawOriginal()->all(),
            'account_4' => UserProviderAccount::findOrFail(4)->getRawOriginal(),
            'documents' => KycDocument::query()->whereIn('id', [27, 28])->orderBy('id')->get()->map->getRawOriginal()->all(),
        ];
    }

    private function seedSession117(): void
    {
        $account = UserProviderAccount::findOrFail(7);
        $metadata = $account->metadata;
        $metadata['nium_kyc_prebuilt_form_session'] = [
            'state' => 'created', 'created_at' => now()->subHour()->toISOString(),
            'expiry_at' => now()->subMinute()->toISOString(), 'session_id_fingerprint' => '1234567890abcdef',
        ];
        $account->forceFill(['metadata' => $metadata])->save();
        ApiRequestLog::query()->whereKey(117)->delete();
        ApiRequestLog::query()->forceCreate([
            'id' => 117, 'provider_id' => 1, 'user_id' => 9, 'operation' => 'kyc_form_session_create',
            'external_reference' => self::REFERENCE, 'request_method' => 'POST', 'request_url' => '/safe/sessions',
            'response_status' => 200, 'transport_outcome' => 'response_received', 'is_success' => true,
            'response_body' => ['sessionId' => 'session-redacted-in-production'],
        ]);
    }

    private function mutateSessionEvidence(string $mutation): void
    {
        if ($mutation === 'missing_log') {
            ApiRequestLog::query()->whereKey(117)->delete();
            return;
        }
        if (in_array($mutation, ['wrong_operation', 'wrong_status', 'wrong_transport'], true)) {
            $log = ApiRequestLog::findOrFail(117);
            $log->forceFill(match ($mutation) {
                'wrong_operation' => ['operation' => 'wrong_operation'],
                'wrong_status' => ['response_status' => 400, 'is_success' => false],
                default => ['transport_outcome' => 'ambiguous'],
            })->save();
            return;
        }

        $account = UserProviderAccount::findOrFail(7);
        $metadata = $account->metadata;
        if ($mutation === 'missing_claim') {
            unset($metadata['nium_kyc_prebuilt_form_session']);
        } else {
            $metadata['nium_kyc_prebuilt_form_session']['expiry_at'] = $mutation === 'invalid_expiry'
                ? 'not-a-date'
                : now()->addHour()->toISOString();
        }
        $account->forceFill(['metadata' => $metadata])->save();
    }

    private function seedEvidence(): void
    {
        IntegrationProvider::query()->forceCreate(['id' => 1, 'code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        User::factory()->create(['id' => 4]);
        User::factory()->create(['id' => 9]);
        KycProfile::query()->forceCreate([
            'id' => 9, 'user_id' => 9, 'status' => 'approved', 'applicant_type' => 'business',
            'legal_name' => 'Safe Company', 'address_line1' => 'Safe Address', 'city' => 'Hong Kong', 'country_code' => 'HK',
        ]);
        KycRelatedPerson::query()->forceCreate([
            'id' => 14, 'kyc_profile_id' => 9, 'relationship_type' => 'beneficial_owner', 'status' => 'approved',
            'legal_name' => 'Safe Person', 'metadata' => ['nium_biometric_identity' => [
                'type' => 'passport', 'identification_number' => self::IDENTITY, 'issuance_country' => 'VN',
                'expiry_date' => '2099-12-31', 'factual' => true, 'synthetic' => false,
                'source' => 'operator_verified_factual_identity_v1',
            ]],
        ]);
        foreach ([[27, 'passport'], [28, 'bank_statement']] as [$id, $type]) {
            KycDocument::query()->forceCreate([
                'id' => $id, 'kyc_profile_id' => 9, 'kyc_related_person_id' => 14, 'type' => $type,
                'status' => 'approved', 'file_url' => "private://factual/{$id}", 'metadata' => [
                    'document_purpose' => $type, 'factual' => true, 'synthetic' => false,
                    'nium_file_id' => '30000000-0000-4000-8000-0000000000'.$id, 'nium_file_state' => 'AVAILABLE',
                ],
            ]);
        }
        UserProviderAccount::query()->forceCreate(['id' => 4, 'user_id' => 4, 'provider_id' => 1]);
        UserProviderAccount::query()->forceCreate([
            'id' => 7, 'user_id' => 9, 'provider_id' => 1, 'external_customer_id' => 'customer-safe-id',
            'external_account_id' => 'wallet-safe-id', 'reconciliation_status' => 'reconciled',
            'metadata' => $this->historicalClaims(),
        ]);
        WebhookEvent::query()->forceCreate([
            'id' => 6, 'provider_id' => 1, 'event_id' => 'stakeholder', 'event_type' => 'CUSTOMER_ENTITY_KYC_STATUS',
            'external_resource_id' => self::REFERENCE, 'payload' => [
                'referenceId' => self::REFERENCE, 'externalId' => 'origin-wallet-person-14',
                'entityType' => 'individual_stakeholder', 'kycStatus' => 'kyc_required', 'kycMode' => 'none',
            ], 'processing_status' => 'processed', 'processed_at' => now(),
        ]);
        WebhookEvent::query()->forceCreate([
            'id' => 7, 'provider_id' => 1, 'event_id' => 'customer-status', 'event_type' => 'CUSTOMER_STATUS_WEBHOOK',
            'external_resource_id' => 'customer-safe-id', 'payload' => [
                'status' => 'pending', 'subStatus' => 'awaiting_kyc',
            ], 'processing_status' => 'processed', 'processed_at' => now(),
        ]);
        $this->createLog(104, 'applicant-reference', 200, true, []);
        foreach ([106, 113, 114, 115, 116] as $id) {
            $this->createLog($id, self::REFERENCE, 400, false, ['error_code' => 'invalid_input']);
        }
        $this->createLog(118, self::REFERENCE, 400, false, [
            'error_code' => 'invalid_input',
            'error_field' => 'entityType',
            'error_field_fingerprint' => 'b4753588f3f6ef2b',
            'error_description_fingerprint' => '0ae1eaf6418f92d1',
        ]);
    }

    private function createLog(int $id, string $reference, int $status, bool $success, array $body): void
    {
        ApiRequestLog::query()->forceCreate([
            'id' => $id, 'provider_id' => 1, 'user_id' => 9, 'operation' => 'submit_kyc',
            'external_reference' => $reference, 'request_method' => 'POST', 'request_url' => '/safe/submitKyc',
            'response_status' => $status, 'transport_outcome' => 'response_received', 'is_success' => $success,
            'response_body' => $body,
        ]);
    }

    private function historicalClaims(): array
    {
        return [
            'nium_submit_kyc_attempts' => [
                'ref_'.substr(hash('sha256', self::REFERENCE), 0, 16) => ['state' => 'rejected'],
            ],
            'nium_stakeholder_submit_kyc_retry_generation_2' => [
                'state' => 'rejected', 'previous_log_id' => 106, 'previous_http_status' => 400,
            ],
            'nium_stakeholder_submit_kyc_retry_generation_3' => [
                'state' => 'rejected', 'previous_log_id' => 113, 'previous_http_status' => 400,
            ],
            'nium_stakeholder_submit_kyc_retry_generation_4' => [
                'state' => 'rejected', 'previous_log_id' => 114, 'previous_http_status' => 400,
            ],
            'nium_stakeholder_submit_kyc_retry_generation_5' => [
                'state' => 'rejected', 'previous_log_id' => 115, 'previous_http_status' => 400,
            ],
            'nium_stakeholder_submit_kyc_retry_generation_7' => [
                'state' => 'rejected', 'generation' => 7, 'provider_http_status' => 400,
                'transport_outcome' => 'response_received', 'contract_fingerprint' => '1234567890abcdef',
            ],
        ];
    }
}
