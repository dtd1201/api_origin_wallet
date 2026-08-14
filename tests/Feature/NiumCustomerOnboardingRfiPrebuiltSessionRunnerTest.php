<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use App\Services\Nium\NiumCustomerOnboardingRfiPrebuiltSessionRunner;
use App\Services\Nium\NiumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class NiumCustomerOnboardingRfiPrebuiltSessionRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.nium.kyc_form_session_endpoint' => '/api/v1/client/{clientHashId}/sessions']);
        $this->seedEvidence();
        $this->mock(NiumService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('post'));
    }

    public function test_no_authoritative_rfi_trigger_holds_without_claim_or_http(): void
    {
        WebhookEvent::query()->delete();

        try {
            $this->runner()->audit();
            $this->fail('Expected missing RFI evidence to hold.');
        } catch (RuntimeException $exception) {
            $this->assertSame('HOLD_RFI_TRIGGER_NOT_PROVEN', $exception->getMessage());
        }

        $this->assertArrayNotHasKey('nium_customer_onboarding_rfi_prebuilt_session', $this->account()->metadata);
        $this->assertSame(0, ApiRequestLog::query()->where('operation', 'customer_onboarding_rfi_session_create')->count());
    }

    public function test_unprojected_rfi_notification_does_not_prove_current_account_state(): void
    {
        $this->account()->forceFill(['provider_sub_status' => 'under_review', 'rfi_status' => null])->save();

        try {
            $this->runner()->audit();
            $this->fail('Expected unprojected RFI notification to hold.');
        } catch (RuntimeException $exception) {
            $this->assertSame('HOLD_RFI_TRIGGER_NOT_PROVEN', $exception->getMessage());
        }

        $this->assertArrayNotHasKey('nium_customer_onboarding_rfi_prebuilt_session', $this->account()->metadata);
    }

    public function test_authoritative_trigger_reaches_offline_audit_with_exact_contract(): void
    {
        $before = $this->immutableEvidence();
        $result = $this->runner()->audit();

        $this->assertSame('READY_FOR_SEPARATE_HUMAN_APPROVAL', $result['terminal']);
        $this->assertSame([
            'featureType' => 'customer_onboarding_rfi',
            'integrationType' => 'standalone',
            'customerHashId' => 'customer-safe-id',
            'onBehalf' => false,
            'expiry' => $result['payload']['expiry'],
            'rollingDurationMinutes' => 120,
        ], $result['payload']);
        foreach (['walletHashId', 'authCode', 'entityType', 'kycMode', 'isResident', 'passport', 'fileIds'] as $field) {
            $this->assertArrayNotHasKey($field, $result['payload']);
        }
        $this->assertSame('HOLD_RFI', $result['readiness']);
        $this->assertArrayNotHasKey('sessionId', $result);
        $this->assertSame($before, $this->immutableEvidence());
    }

    public function test_claim_before_maximum_one_post_replay_block_and_session_is_not_completion(): void
    {
        $before = $this->immutableEvidence();
        $this->mock(NiumService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('clientId')->once()->andReturn('safe-client-id');
            $mock->shouldReceive('path')->once()->andReturn('/api/v1/client/safe-client-id/sessions');
            $mock->shouldReceive('post')->once()->andReturnUsing(function (string $path, array $payload, User $user, $related, string $operation): Response {
                $this->assertSame('/api/v1/client/safe-client-id/sessions', $path);
                $this->assertSame('customer_onboarding_rfi', $payload['featureType']);
                $this->assertSame(9, $user->id);
                $this->assertSame('customer_onboarding_rfi_session_create', $operation);
                $this->assertSame('submitting', $this->claim()['state']);
                $this->createRfiLog(120, 200, true);

                return new Response(new \GuzzleHttp\Psr7\Response(200, [], '{"sessionId":"secret-rfi-session"}'));
            });
        });

        $result = $this->runner()->run(true);

        $this->assertSame('PASS_RFI_SESSION_CREATED', $result['terminal']);
        $this->assertSame('HOLD_RFI', $result['readiness']);
        $this->assertSame('secret-rfi-session', $result['sessionId']);
        $this->assertSame(1, $result['rfi_session_post_count']);
        $this->assertSame($before, $this->immutableEvidence());
        $this->assertSafeClaim();
        $this->assertReplayBlocked(1);
    }

    public function test_readiness_reports_rfi_cleared_hold_rfi_and_other_hold_without_claiming_van_eligibility(): void
    {
        foreach ([null, ''] as $subStatus) {
            WebhookEvent::query()->delete();
            $this->addStatus('clear', $subStatus);
            $this->assertSame('RFI_CLEARED', $this->runner()->readiness());
        }

        WebhookEvent::query()->delete();
        $this->addStatus('clear', 'rfi_requested');
        $this->assertSame('HOLD_RFI', $this->runner()->readiness());

        WebhookEvent::query()->delete();
        $this->addStatus('pending', 'under_review');
        $this->assertSame('HOLD_RFI_NOT_CLEAR', $this->runner()->readiness());
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

        $this->assertSame('STOP_RFI_SESSION_OUTCOME_UNKNOWN_NO_RETRY', $this->runner()->run(true)['terminal']);
        $this->assertReplayBlocked(0);
    }

    public function test_human_approval_is_required_and_existing_session_history_blocks(): void
    {
        try {
            $this->runner()->run();
            $this->fail('Expected approval to be required.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Separate human approval is required for Customer Onboarding RFI.', $exception->getMessage());
        }

        $this->createRfiLog(120, 500, false);
        try {
            $this->runner()->audit();
            $this->fail('Expected RFI session history to block regeneration.');
        } catch (RuntimeException $exception) {
            $this->assertSame('HOLD_ONBOARDING_RFI_SESSION_HISTORY_EXISTS', $exception->getMessage());
        }
    }

    private function mockResponse(int $status, string $body): void
    {
        $this->mock(NiumService::class, function (MockInterface $mock) use ($status, $body): void {
            $mock->shouldReceive('clientId')->andReturn('safe-client-id');
            $mock->shouldReceive('path')->andReturn('/safe/sessions');
            $mock->shouldReceive('post')->once()->andReturnUsing(function () use ($status, $body): Response {
                $this->assertSame('submitting', $this->claim()['state']);
                $this->createRfiLog(120, $status, $status < 400);

                return new Response(new \GuzzleHttp\Psr7\Response($status, [], $body));
            });
        });
    }

    private function assertReplayBlocked(int $posts): void
    {
        try {
            $this->runner()->run(true);
            $this->fail('Expected RFI session replay to be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('HOLD_ONBOARDING_RFI_SESSION_ALREADY_CLAIMED', $exception->getMessage());
        }
        $this->assertSame($posts, ApiRequestLog::query()->where('operation', 'customer_onboarding_rfi_session_create')->count());
    }

    private function assertSafeClaim(): void
    {
        $claim = $this->claim();
        $this->assertSame([
            'generation', 'state', 'feature_type', 'created_at', 'expiry_at', 'provider_http_status',
            'transport_outcome', 'session_id_fingerprint', 'updated_at',
        ], array_keys($claim));
        $serialized = json_encode($claim, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret-rfi-session', $serialized);
        $this->assertStringNotContainsString('sessionId', $serialized);
        $this->assertArrayNotHasKey('sessionId', $claim);
    }

    private function immutableEvidence(): array
    {
        return [
            'logs' => ApiRequestLog::query()->whereIn('id', [104, 106, 113, 114, 115, 116, 117, 118, 119])
                ->orderBy('id')->get()->map->getRawOriginal()->all(),
            'account_4' => UserProviderAccount::query()->findOrFail(4)->getRawOriginal(),
            'kyc_claims' => array_intersect_key($this->account()->metadata, array_flip([
                'nium_kyc_prebuilt_form_session',
                'nium_kyc_prebuilt_form_session_generation_2',
                'nium_stakeholder_submit_kyc_retry_generation_7',
                'nium_stakeholder_submit_kyc_retry_generation_8',
            ])),
        ];
    }

    private function resetExecutionEvidence(): void
    {
        ApiRequestLog::query()->where('operation', 'customer_onboarding_rfi_session_create')->delete();
        $account = $this->account();
        $metadata = $account->metadata;
        unset($metadata['nium_customer_onboarding_rfi_prebuilt_session']);
        $account->forceFill(['metadata' => $metadata])->save();
    }

    private function account(): UserProviderAccount
    {
        return UserProviderAccount::query()->findOrFail(7);
    }

    private function claim(): array
    {
        return $this->account()->metadata['nium_customer_onboarding_rfi_prebuilt_session'];
    }

    private function runner(): NiumCustomerOnboardingRfiPrebuiltSessionRunner
    {
        return app(NiumCustomerOnboardingRfiPrebuiltSessionRunner::class);
    }

    private function seedEvidence(): void
    {
        IntegrationProvider::query()->forceCreate(['id' => 1, 'code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        User::factory()->create(['id' => 4]);
        User::factory()->create(['id' => 9]);
        UserProviderAccount::query()->forceCreate(['id' => 4, 'user_id' => 4, 'provider_id' => 1]);
        UserProviderAccount::query()->forceCreate([
            'id' => 7, 'user_id' => 9, 'provider_id' => 1, 'external_customer_id' => 'customer-safe-id',
            'provider_status' => 'clear', 'provider_sub_status' => 'rfi_requested', 'rfi_status' => 'requested',
            'metadata' => [
                'nium_kyc_prebuilt_form_session' => ['state' => 'created', 'expiry_at' => now()->subHour()->toISOString()],
                'nium_kyc_prebuilt_form_session_generation_2' => ['generation' => 2, 'state' => 'created'],
                'nium_stakeholder_submit_kyc_retry_generation_7' => ['generation' => 7, 'state' => 'rejected'],
                'nium_stakeholder_submit_kyc_retry_generation_8' => ['generation' => 8, 'state' => 'rejected'],
            ],
        ]);
        $this->addStatus('clear', 'rfi_requested');
        foreach ([104, 106, 113, 114, 115, 116, 117, 118, 119] as $id) {
            ApiRequestLog::query()->forceCreate([
                'id' => $id, 'provider_id' => 1, 'user_id' => 9,
                'operation' => $id === 117 ? 'kyc_form_session_create' : 'submit_kyc',
                'request_method' => 'POST', 'request_url' => '/immutable', 'response_status' => 200,
                'transport_outcome' => 'response_received', 'is_success' => true, 'response_body' => ['safe' => true],
            ]);
        }
    }

    private function addStatus(string $status, ?string $subStatus): void
    {
        WebhookEvent::query()->forceCreate([
            'provider_id' => 1, 'event_id' => uniqid('customer-status-', true),
            'event_type' => 'CUSTOMER_STATUS_WEBHOOK', 'external_resource_id' => 'customer-safe-id',
            'payload' => ['status' => $status, 'subStatus' => $subStatus],
            'processing_status' => 'processed', 'processed_at' => now(),
        ]);
    }

    private function createRfiLog(int $id, int $status, bool $success): void
    {
        ApiRequestLog::query()->forceCreate([
            'id' => $id, 'provider_id' => 1, 'user_id' => 9,
            'operation' => 'customer_onboarding_rfi_session_create', 'request_method' => 'POST',
            'request_url' => '/safe/sessions', 'response_status' => $status,
            'transport_outcome' => 'response_received', 'is_success' => $success, 'response_body' => ['safe' => true],
        ]);
    }
}
