<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use App\Services\Nium\NiumHkKycPrebuiltFormSessionRunner;
use App\Services\Nium\NiumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class NiumHkKycPrebuiltFormSessionRunnerTest extends TestCase
{
    use RefreshDatabase;

    private const STAKEHOLDER_REFERENCE = '7609d9d1-9d37-4e08-9197-602d792f7a2e';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.nium.kyc_form_session_endpoint' => '/api/v1/client/{clientHashId}/sessions']);
        $this->seedEvidence();
        $this->mock(NiumService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('post'));
    }

    public function test_offline_audit_has_exact_safe_contract_and_is_immutable(): void
    {
        $before = $this->immutableEvidence();
        $account = UserProviderAccount::query()->findOrFail(7)->getRawOriginal();

        $result = $this->runner()->audit();

        $this->assertSame('READY_FOR_SEPARATE_HUMAN_APPROVAL', $result['terminal']);
        $this->assertSame('kyc_form', $result['feature_type']);
        $this->assertSame('standalone', $result['integration_type']);
        $this->assertSame(120, $result['rolling_duration_minutes']);
        $this->assertFalse($result['on_behalf']);
        $this->assertTrue($result['expiry_future']);
        $this->assertTrue($result['customer_hash_id_present']);
        $this->assertSame('pending', $result['latest_customer_status']);
        $this->assertSame('awaiting_kyc', $result['latest_customer_substatus']);
        $this->assertSame(0, $result['session_post_count']);
        $this->assertSame(5, $result['submit_kyc_post_count']);
        $this->assertSame(0, $result['db_write_count']);
        $this->assertSame($before, $this->immutableEvidence());
        $this->assertSame($account, UserProviderAccount::query()->findOrFail(7)->getRawOriginal());
    }

    public function test_exact_endpoint_payload_claim_before_post_and_success(): void
    {
        $before = $this->immutableEvidence();
        $this->mock(NiumService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('clientId')->andReturn('safe-client-id');
            $mock->shouldReceive('path')->once()->with('/api/v1/client/{clientHashId}/sessions', ['client' => 'safe-client-id'])
                ->andReturn('/api/v1/client/safe-client-id/sessions');
            $mock->shouldReceive('post')->once()->andReturnUsing(function (string $path, array $payload, User $user, $related, string $operation): Response {
                $this->assertSame('/api/v1/client/safe-client-id/sessions', $path);
                $this->assertSame('kyc_form_session_create', $operation);
                $this->assertSame(9, $user->id);
                $this->assertSame('submitting', $this->claimState());
                $this->assertSame('kyc_form', $payload['featureType']);
                $this->assertSame('standalone', $payload['integrationType']);
                $this->assertSame(120, $payload['rollingDurationMinutes']);
                $this->assertFalse($payload['onBehalf']);
                $this->assertSame('customer-safe-id', $payload['customerHashId']);
                $this->assertTrue(now()->lt($payload['expiry']));
                foreach (['domain', 'email', 'entityType', 'kycMode', 'isResident', 'proofOfIdentityDocument', 'proofOfAddressDocument'] as $field) {
                    $this->assertArrayNotHasKey($field, $payload);
                }
                $this->createSessionLog(117, 200, true, ['sessionId' => 'sandbox-session-safe']);
                return new Response(new \GuzzleHttp\Psr7\Response(200, [], '{"sessionId":"sandbox-session-safe"}'));
            });
        });

        $result = $this->runner()->run(true);
        $this->assertSame('PASS_SESSION_CREATED', $result['terminal']);
        $this->assertSame('sandbox-session-safe', $result['sessionId']);
        $this->assertTrue($result['session_id_present']);
        $this->assertSame(1, $result['session_post_count']);
        $this->assertSame($before, $this->immutableEvidence());
        $this->assertReplayBlocked();
    }

    public function test_rejection_server_error_and_malformed_response_are_one_shot(): void
    {
        foreach ([[400, []], [500, []], [200, []]] as [$status, $body]) {
            $this->mockResponse($status, $body);
            $result = $this->runner()->run(true);
            $this->assertContains($result['terminal'], ['STOP_SESSION_REJECTED_NO_RETRY', 'HOLD_SESSION_RESPONSE_REVIEW']);
            $this->assertReplayBlocked();
            $this->resetExecutionEvidence();
        }
    }

    public function test_transport_unknown_is_one_shot(): void
    {
        $this->mock(NiumService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('clientId')->andReturn('safe-client-id');
            $mock->shouldReceive('path')->andReturn('/safe/sessions');
            $mock->shouldReceive('post')->once()->andReturnUsing(function (): never {
                $this->assertSame('submitting', $this->claimState());
                throw new ConnectionException('ambiguous');
            });
        });
        $this->assertSame('STOP_SESSION_OUTCOME_UNKNOWN_NO_RETRY', $this->runner()->run(true)['terminal']);
        $this->assertReplayBlocked(0);
    }

    public function test_g6_claim_customer_id_or_compatible_status_preflight_fail_closed(): void
    {
        $account = UserProviderAccount::query()->findOrFail(7);
        $metadata = $account->metadata;
        $metadata['nium_stakeholder_submit_kyc_retry_generation_6'] = ['state' => 'submitting'];
        $account->forceFill(['metadata' => $metadata])->save();
        $this->assertAuditHolds();
    }

    public function test_existing_session_claim_blocks(): void
    {
        $account = UserProviderAccount::query()->findOrFail(7);
        $metadata = $account->metadata;
        $metadata['nium_kyc_prebuilt_form_session'] = ['state' => 'submitting'];
        $account->forceFill(['metadata' => $metadata])->save();
        $this->assertAuditHolds('HOLD_KYC_PREBUILT_FORM_SESSION_ALREADY_CLAIMED');
    }

    private function mockResponse(int $status, array $body): void
    {
        $this->mock(NiumService::class, function (MockInterface $mock) use ($status, $body): void {
            $mock->shouldReceive('clientId')->andReturn('safe-client-id');
            $mock->shouldReceive('path')->andReturn('/safe/sessions');
            $mock->shouldReceive('post')->once()->andReturnUsing(function () use ($status, $body): Response {
                $this->assertSame('submitting', $this->claimState());
                $this->createSessionLog(117, $status, $status < 400, $body);
                return new Response(new \GuzzleHttp\Psr7\Response($status, [], json_encode($body, JSON_THROW_ON_ERROR)));
            });
        });
    }

    private function assertReplayBlocked(int $posts = 1): void
    {
        try {
            $this->runner()->run(true);
            $this->fail('Expected session claim to block replay.');
        } catch (RuntimeException $exception) {
            $this->assertSame('HOLD_KYC_PREBUILT_FORM_SESSION_ALREADY_CLAIMED', $exception->getMessage());
        }
        $this->assertSame($posts, ApiRequestLog::query()->where('operation', 'kyc_form_session_create')->count());
        $this->assertSame([106, 113, 114, 115, 116], ApiRequestLog::query()->where('operation', 'submit_kyc')
            ->where('external_reference', self::STAKEHOLDER_REFERENCE)->orderBy('id')->pluck('id')->all());
        $this->assertSame(0, ApiRequestLog::query()->whereIn('operation', [
            'onboarding_simulation_submit_kyc', 'file_create', 'file_details', 'van', 'payout',
        ])->count());
    }

    private function resetExecutionEvidence(): void
    {
        ApiRequestLog::query()->where('operation', 'kyc_form_session_create')->delete();
        $account = UserProviderAccount::query()->findOrFail(7);
        $metadata = $account->metadata;
        unset($metadata['nium_kyc_prebuilt_form_session']);
        $account->forceFill(['metadata' => $metadata])->save();
    }

    private function claimState(): ?string
    {
        return UserProviderAccount::query()->findOrFail(7)->metadata['nium_kyc_prebuilt_form_session']['state'] ?? null;
    }

    private function assertAuditHolds(?string $message = null): void
    {
        try {
            $this->runner()->audit();
            $this->fail('Expected offline audit hold.');
        } catch (RuntimeException $exception) {
            if ($message !== null) {
                $this->assertSame($message, $exception->getMessage());
            }
            $this->addToAssertionCount(1);
        }
    }

    private function runner(): NiumHkKycPrebuiltFormSessionRunner
    {
        return app(NiumHkKycPrebuiltFormSessionRunner::class);
    }

    private function immutableEvidence(): array
    {
        return [
            'logs' => ApiRequestLog::query()->whereIn('id', [104, 106, 113, 114, 115, 116])->orderBy('id')->get()->map->getRawOriginal()->all(),
            'account_4' => UserProviderAccount::query()->findOrFail(4)->getRawOriginal(),
        ];
    }

    private function seedEvidence(): void
    {
        IntegrationProvider::query()->forceCreate(['id' => 1, 'code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        User::factory()->create(['id' => 4]);
        User::factory()->create(['id' => 9]);
        UserProviderAccount::query()->forceCreate(['id' => 4, 'user_id' => 4, 'provider_id' => 1]);
        UserProviderAccount::query()->forceCreate(['id' => 7, 'user_id' => 9, 'provider_id' => 1,
            'external_customer_id' => 'customer-safe-id', 'external_account_id' => 'wallet-safe-id',
            'reconciliation_status' => 'reconciled', 'metadata' => []]);
        WebhookEvent::query()->forceCreate(['id' => 6, 'provider_id' => 1, 'event_id' => 'entity-kyc',
            'event_type' => 'CUSTOMER_ENTITY_KYC_STATUS', 'external_resource_id' => self::STAKEHOLDER_REFERENCE,
            'payload' => ['entityType' => 'individual_stakeholder', 'kycStatus' => 'kyc_required'],
            'processing_status' => 'processed', 'processed_at' => now()]);
        WebhookEvent::query()->forceCreate(['provider_id' => 1, 'event_id' => 'customer-status',
            'event_type' => 'CUSTOMER_STATUS_WEBHOOK', 'external_resource_id' => 'customer-safe-id',
            'payload' => ['status' => 'pending', 'subStatus' => 'awaiting_kyc'],
            'processing_status' => 'processed', 'processed_at' => now()]);
        $this->createLog(104, 'applicant-reference', 200, true, []);
        foreach ([106, 113, 114, 115, 116] as $id) {
            $this->createLog($id, self::STAKEHOLDER_REFERENCE, 400, false, ['error_code' => 'invalid_input']);
        }
    }

    private function createLog(int $id, string $reference, int $status, bool $success, array $body): void
    {
        ApiRequestLog::query()->forceCreate(['id' => $id, 'provider_id' => 1, 'user_id' => 9,
            'operation' => 'submit_kyc', 'external_reference' => $reference, 'request_method' => 'POST',
            'request_url' => '/safe/submitKyc', 'response_status' => $status,
            'transport_outcome' => 'response_received', 'is_success' => $success, 'response_body' => $body]);
    }

    private function createSessionLog(int $id, int $status, bool $success, array $body): void
    {
        ApiRequestLog::query()->forceCreate(['id' => $id, 'provider_id' => 1, 'user_id' => 9,
            'operation' => 'kyc_form_session_create', 'external_reference' => self::STAKEHOLDER_REFERENCE,
            'request_method' => 'POST', 'request_url' => '/safe/sessions', 'response_status' => $status,
            'transport_outcome' => 'response_received', 'is_success' => $success, 'response_body' => $body]);
    }
}
