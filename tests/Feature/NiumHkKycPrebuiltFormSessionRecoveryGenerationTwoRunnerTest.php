<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use App\Services\Nium\NiumHkKycPrebuiltFormSessionRecoveryGenerationTwoRunner;
use App\Services\Nium\NiumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class NiumHkKycPrebuiltFormSessionRecoveryGenerationTwoRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.nium.kyc_form_session_endpoint' => '/api/v1/client/{clientHashId}/sessions']);
        $this->seedEvidence();
        $this->mock(NiumService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('post'));
    }

    public function test_offline_audit_preserves_117_and_exact_contract(): void
    {
        $before = $this->immutableEvidence();
        $result = $this->runner()->audit();

        $this->assertSame('READY_FOR_SEPARATE_HUMAN_APPROVAL', $result['terminal']);
        $this->assertSame('kyc_form', $result['payload']['featureType']);
        $this->assertSame('standalone', $result['payload']['integrationType']);
        $this->assertSame('customer-safe-id', $result['payload']['customerHashId']);
        $this->assertFalse($result['payload']['onBehalf']);
        $this->assertSame(120, $result['payload']['rollingDurationMinutes']);
        $this->assertTrue(now()->lt($result['payload']['expiry']));
        $this->assertSame(6, count($result['payload']));
        $this->assertSame($before, $this->immutableEvidence());
    }

    public function test_claim_before_maximum_one_post_safe_evidence_and_replay_block(): void
    {
        $before = $this->immutableEvidence();
        $this->mock(NiumService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('clientId')->once()->andReturn('safe-client-id');
            $mock->shouldReceive('path')->once()
                ->with('/api/v1/client/{clientHashId}/sessions', ['clientHashId' => 'safe-client-id'])
                ->andReturn('/api/v1/client/safe-client-id/sessions');
            $mock->shouldReceive('post')->once()->andReturnUsing(function (string $path, array $payload, User $user, $related, string $operation): Response {
                $this->assertSame('/api/v1/client/safe-client-id/sessions', $path);
                $this->assertSame(9, $user->id);
                $this->assertSame('kyc_form_session_create', $operation);
                $this->assertSame('submitting', $this->claim()['state']);
                $this->assertSame(6, count($payload));
                $this->createSessionLog(120, 200, true);

                return new Response(new \GuzzleHttp\Psr7\Response(200, [], '{"sessionId":"secret-session-id"}'));
            });
        });

        $result = $this->runner()->run(true);

        $this->assertSame('PASS_SESSION_CREATED', $result['terminal']);
        $this->assertSame(1, $result['session_post_count']);
        $this->assertSame('secret-session-id', $result['sessionId']);
        $this->assertSame(substr(hash('sha256', 'secret-session-id'), 0, 16), $result['session_id_fingerprint']);
        $this->assertSame([
            'generation', 'state', 'created_at', 'expiry_at', 'previous_session_log_id', 'recovery_reason',
            'provider_http_status', 'transport_outcome', 'session_id_fingerprint',
        ], array_keys($this->claim()));
        $this->assertStringNotContainsString('secret-session-id', json_encode($this->claim(), JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('sessionId', $this->claim());
        $this->assertSame($before, $this->immutableEvidence());
        $this->assertReplayBlocked(1);
    }

    public function test_offline_audit_never_exposes_runtime_session_id(): void
    {
        $result = $this->runner()->audit();

        $this->assertArrayNotHasKey('sessionId', $result);
        $this->assertFalse($result['session_id_present'] ?? false);
    }

    public function test_separate_human_approval_is_required_before_preflight_or_http(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Separate human approval is required for Generation #2 recovery.');

        $this->runner()->run();
    }

    public function test_historical_session_must_be_expired(): void
    {
        $this->mutateHistoricalClaim('expiry_at', now()->addMinute()->toISOString());
        $this->assertPreflightBlocked('HOLD_HISTORICAL_SESSION_NOT_EXPIRED');
    }

    public function test_pending_awaiting_kyc_is_required_and_any_completion_evidence_blocks(): void
    {
        $this->addStatus('pending', 'under_review');
        $this->assertPreflightBlocked('HOLD_CUSTOMER_NOT_PENDING_AWAITING_KYC');
    }

    public function test_earlier_authoritative_completion_evidence_blocks_recovery(): void
    {
        WebhookEvent::query()->delete();
        $this->addStatus('clear', null, now()->subMinute());
        $this->addStatus('pending', 'awaiting_kyc', now());
        $this->assertPreflightBlocked('HOLD_KYC_COMPLETION_EVIDENCE_PRESENT');
    }

    public function test_newer_session_and_generation_two_claim_block(): void
    {
        $this->createSessionLog(120, 500, false);
        $this->assertPreflightBlocked('HOLD_NEWER_PREBUILT_SESSION_EXISTS', 2);
    }

    public function test_generation_two_claim_absence_is_required(): void
    {
        $account = UserProviderAccount::query()->findOrFail(7);
        $metadata = $account->metadata;
        $metadata['nium_kyc_prebuilt_form_session_generation_2'] = ['state' => 'submitting'];
        $account->forceFill(['metadata' => $metadata])->save();
        $this->assertPreflightBlocked('HOLD_PREBUILT_RECOVERY_G2_ALREADY_CLAIMED');
    }

    public function test_117_account_4_and_direct_submit_118_119_are_immutable(): void
    {
        $before = $this->immutableEvidence();
        $this->runner()->audit();
        $this->assertSame($before, $this->immutableEvidence());

        ApiRequestLog::query()->findOrFail(117)->forceFill(['response_status' => 500])->save();
        $this->assertPreflightBlocked('HOLD_HISTORICAL_SESSION_LOG_INVALID');
    }

    public function test_4xx_5xx_and_malformed_response_are_one_shot(): void
    {
        foreach ([[400, '{}'], [500, '{}'], [200, '{}']] as [$status, $body]) {
            $this->mockResponse($status, $body);
            $this->runner()->run(true);
            $this->assertReplayBlocked(1);
            $this->resetExecutionEvidence();
        }
    }

    public function test_timeout_is_one_shot_without_retry(): void
    {
        $this->mock(NiumService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('clientId')->andReturn('safe-client-id');
            $mock->shouldReceive('path')->andReturn('/safe/sessions');
            $mock->shouldReceive('post')->once()->andThrow(new ConnectionException('timeout'));
        });

        $this->assertSame('STOP_SESSION_OUTCOME_UNKNOWN_NO_RETRY', $this->runner()->run(true)['terminal']);
        $this->assertReplayBlocked(0);
    }

    private function mockResponse(int $status, string $body): void
    {
        $this->mock(NiumService::class, function (MockInterface $mock) use ($status, $body): void {
            $mock->shouldReceive('clientId')->andReturn('safe-client-id');
            $mock->shouldReceive('path')->andReturn('/safe/sessions');
            $mock->shouldReceive('post')->once()->andReturnUsing(function () use ($status, $body): Response {
                $this->assertSame('submitting', $this->claim()['state']);
                $this->createSessionLog(120, $status, $status < 400);

                return new Response(new \GuzzleHttp\Psr7\Response($status, [], $body));
            });
        });
    }

    private function assertReplayBlocked(int $newPosts): void
    {
        try {
            $this->runner()->run(true);
            $this->fail('Expected replay to be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('HOLD_PREBUILT_RECOVERY_G2_ALREADY_CLAIMED', $exception->getMessage());
        }
        $this->assertSame(1 + $newPosts, ApiRequestLog::query()->where('operation', 'kyc_form_session_create')->count());
    }

    private function assertPreflightBlocked(string $message, int $sessionLogs = 1): void
    {
        try {
            $this->runner()->audit();
            $this->fail('Expected preflight to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
        $this->assertSame($sessionLogs, ApiRequestLog::query()->where('operation', 'kyc_form_session_create')->count());
    }

    private function immutableEvidence(): array
    {
        return [
            ApiRequestLog::query()->findOrFail(117)->getRawOriginal(),
            UserProviderAccount::query()->findOrFail(7)->metadata['nium_kyc_prebuilt_form_session'],
            UserProviderAccount::query()->findOrFail(4)->getRawOriginal(),
            ApiRequestLog::query()->whereIn('id', [118, 119])->orderBy('id')->get()->map->getRawOriginal()->all(),
        ];
    }

    private function claim(): array
    {
        return UserProviderAccount::query()->findOrFail(7)->metadata['nium_kyc_prebuilt_form_session_generation_2'];
    }

    private function mutateHistoricalClaim(string $key, mixed $value): void
    {
        $account = UserProviderAccount::query()->findOrFail(7);
        $metadata = $account->metadata;
        $metadata['nium_kyc_prebuilt_form_session'][$key] = $value;
        $account->forceFill(['metadata' => $metadata])->save();
    }

    private function resetExecutionEvidence(): void
    {
        ApiRequestLog::query()->where('id', '>', 119)->delete();
        $account = UserProviderAccount::query()->findOrFail(7);
        $metadata = $account->metadata;
        unset($metadata['nium_kyc_prebuilt_form_session_generation_2']);
        $account->forceFill(['metadata' => $metadata])->save();
    }

    private function runner(): NiumHkKycPrebuiltFormSessionRecoveryGenerationTwoRunner
    {
        return app(NiumHkKycPrebuiltFormSessionRecoveryGenerationTwoRunner::class);
    }

    private function seedEvidence(): void
    {
        IntegrationProvider::query()->forceCreate(['id' => 1, 'code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        User::factory()->create(['id' => 4]);
        User::factory()->create(['id' => 9]);
        UserProviderAccount::query()->forceCreate(['id' => 4, 'user_id' => 4, 'provider_id' => 1]);
        UserProviderAccount::query()->forceCreate([
            'id' => 7, 'user_id' => 9, 'provider_id' => 1, 'external_customer_id' => 'customer-safe-id',
            'metadata' => ['nium_kyc_prebuilt_form_session' => [
                'state' => 'created', 'created_at' => now()->subHours(4)->toISOString(),
                'expiry_at' => now()->subHours(2)->toISOString(), 'session_id_fingerprint' => '0123456789abcdef',
                'provider_http_status' => 200,
            ]],
        ]);
        $this->addStatus('pending', 'awaiting_kyc');
        $this->createSessionLog(117, 200, true);
        $this->createDirectSubmitLog(118);
        $this->createDirectSubmitLog(119);
    }

    private function addStatus(string $status, ?string $subStatus, mixed $processedAt = null): void
    {
        WebhookEvent::query()->forceCreate([
            'provider_id' => 1, 'event_id' => uniqid('status-', true), 'event_type' => 'CUSTOMER_STATUS_WEBHOOK',
            'external_resource_id' => 'customer-safe-id', 'payload' => ['status' => $status, 'subStatus' => $subStatus],
            'processing_status' => 'processed', 'processed_at' => $processedAt ?? now(),
        ]);
    }

    private function createSessionLog(int $id, int $status, bool $success): void
    {
        ApiRequestLog::query()->forceCreate([
            'id' => $id, 'provider_id' => 1, 'user_id' => 9, 'operation' => 'kyc_form_session_create',
            'request_method' => 'POST', 'request_url' => '/safe/sessions', 'response_status' => $status,
            'transport_outcome' => 'response_received', 'is_success' => $success, 'response_body' => ['safe' => true],
        ]);
    }

    private function createDirectSubmitLog(int $id): void
    {
        ApiRequestLog::query()->forceCreate([
            'id' => $id, 'provider_id' => 1, 'user_id' => 9, 'operation' => 'submit_kyc',
            'request_method' => 'POST', 'request_url' => '/safe/submitKyc', 'response_status' => 400,
            'transport_outcome' => 'response_received', 'is_success' => false, 'response_body' => ['error_code' => 'invalid_input'],
        ]);
    }
}
