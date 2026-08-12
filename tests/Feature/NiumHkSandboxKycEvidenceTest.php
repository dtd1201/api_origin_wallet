<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use App\Services\Nium\NiumHkApplicantSubmitKycEvidenceReconciler;
use App\Services\Nium\NiumHkSandboxKycSimulationRunner;
use App\Services\Nium\NiumProviderAccountStateService;
use App\Services\Nium\NiumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class NiumHkSandboxKycEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private const APPLICANT_REFERENCE = 'c620e0e9-ab0a-43bd-aa10-44f573db723a';

    private const STAKEHOLDER_REFERENCE = '7609d9d1-9d37-4e08-9197-602d792f7a2e';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.nium.base_url' => 'https://gatewaysandbox.nium.com',
            'services.nium.client_name' => 'Origin Wallet HK',
            'services.nium.client_id' => 'safe-client-id',
            'services.nium.auth.mode' => 'header',
            'services.nium.auth.header_name' => 'x-api-key',
            'services.nium.auth.header_value' => 'safe-sandbox-api-key',
            'services.nium.webhook.static_header_name' => 'x-partner-key',
            'services.nium.webhook.static_header_value' => 'safe-partner-key',
            'services.nium.customer_onboarding_simulation_endpoint' => '/api/v5/simulations/onboard/{customerHashId}/transition',
        ]);
        $this->seedLockedEvidence();
    }

    public function test_applicant_log_104_reconciliation_exact_evidence_passes_without_terminal_kyc_state(): void
    {
        $this->setApplicantAttemptState('response_review');
        $result = app(NiumHkApplicantSubmitKycEvidenceReconciler::class)->reconcile();
        $attempt = $this->attempt(self::APPLICANT_REFERENCE);

        $this->assertSame('HOLD_PROVIDER_ACCEPTED_200_SANDBOX_REVIEW', $result['terminal']);
        $this->assertSame('provider_accepted_200_sandbox_review', $attempt['state']);
        $this->assertSame(104, $attempt['submit_kyc_log_id']);
        $this->assertSame(8, $attempt['webhook_id']);
        $this->assertNotContains($attempt['state'], ['initiated', 'submitted', 'verified']);
    }

    public function test_applicant_reconciliation_reconstructs_completely_missing_attempt_from_locked_evidence(): void
    {
        $logCount = ApiRequestLog::query()->count();
        $account = UserProviderAccount::query()->findOrFail(7);
        $metadata = $account->metadata;
        unset($metadata['nium_submit_kyc_attempts'][$this->attemptKey(self::APPLICANT_REFERENCE)]);
        $account->forceFill(['metadata' => $metadata])->save();

        $result = app(NiumHkApplicantSubmitKycEvidenceReconciler::class)->reconcile();
        $attempt = $this->attempt(self::APPLICANT_REFERENCE);

        $this->assertSame('HOLD_PROVIDER_ACCEPTED_200_SANDBOX_REVIEW', $result['terminal']);
        $this->assertSame('provider_accepted_200_sandbox_review', $attempt['state']);
        $this->assertSame(104, $attempt['submit_kyc_log_id']);
        $this->assertSame(8, $attempt['webhook_id']);
        $this->assertSame($logCount, ApiRequestLog::query()->count());
    }

    public function test_exact_already_reconciled_attempt_is_idempotent(): void
    {
        $this->setApplicantAttemptState('response_review');
        app(NiumHkApplicantSubmitKycEvidenceReconciler::class)->reconcile();
        $before = UserProviderAccount::query()->findOrFail(7)->getRawOriginal('metadata');

        $result = app(NiumHkApplicantSubmitKycEvidenceReconciler::class)->reconcile();

        $this->assertSame('ALREADY_RECONCILED_PROVIDER_ACCEPTED_200_SANDBOX_REVIEW', $result['terminal']);
        $this->assertSame($before, UserProviderAccount::query()->findOrFail(7)->getRawOriginal('metadata'));
    }

    public function test_conflicting_existing_applicant_attempt_fails_closed(): void
    {
        $this->setApplicantAttemptState('initiated');

        $this->expectException(RuntimeException::class);
        app(NiumHkApplicantSubmitKycEvidenceReconciler::class)->reconcile();
    }

    public function test_wrong_applicant_log_reference_prevents_reconciliation(): void
    {
        $this->setApplicantAttemptState('response_review');
        ApiRequestLog::query()->findOrFail(104)->forceFill(['external_reference' => 'wrong-reference'])->save();

        $this->expectException(RuntimeException::class);
        app(NiumHkApplicantSubmitKycEvidenceReconciler::class)->reconcile();
    }

    public function test_later_matching_log_does_not_replace_locked_log_104_for_reconciliation(): void
    {
        $this->setApplicantAttemptState('response_review');
        ApiRequestLog::query()->findOrFail(104)->delete();
        $this->submitLog(106, self::APPLICANT_REFERENCE);

        $this->expectException(RuntimeException::class);
        app(NiumHkApplicantSubmitKycEvidenceReconciler::class)->reconcile();
    }

    public function test_wrong_applicant_webhook_prevents_reconciliation(): void
    {
        $this->setApplicantAttemptState('response_review');
        $webhook = WebhookEvent::query()->findOrFail(8);
        $webhook->forceFill(['payload' => [...$webhook->payload, 'entityType' => 'individual_stakeholder']])->save();

        $this->expectException(RuntimeException::class);
        app(NiumHkApplicantSubmitKycEvidenceReconciler::class)->reconcile();
    }

    public function test_wrong_webhook_id_prevents_reconstruction(): void
    {
        $this->removeApplicantAttempt();
        WebhookEvent::query()->findOrFail(8)->delete();
        $this->applicantWebhook(10);

        $this->expectException(RuntimeException::class);
        app(NiumHkApplicantSubmitKycEvidenceReconciler::class)->reconcile();
    }

    #[DataProvider('invalidApplicantWebhookEvidence')]
    public function test_wrong_applicant_webhook_reference_entity_mode_or_status_prevents_reconstruction(
        string $field,
        string $value,
    ): void {
        $this->removeApplicantAttempt();
        $webhook = WebhookEvent::query()->findOrFail(8);
        $webhook->forceFill(['payload' => [...$webhook->payload, $field => $value]])->save();

        $this->expectException(RuntimeException::class);
        app(NiumHkApplicantSubmitKycEvidenceReconciler::class)->reconcile();
    }

    public function test_authenticated_entity_webhook_preserves_attempts_and_refreshes_provider_projection(): void
    {
        $beforeFour = UserProviderAccount::query()->findOrFail(4)->getRawOriginal();
        $account = UserProviderAccount::query()->findOrFail(7);
        $this->stateService()->recordVerifiedNotificationDetails($account, [
            'template' => 'CUSTOMER_ENTITY_KYC_STATUS',
            'externalId' => 'origin-wallet-person-14',
            'referenceId' => self::STAKEHOLDER_REFERENCE,
            'entityType' => 'individual_stakeholder',
            'kycMode' => 'biometric_kyc',
            'kycStatus' => 'kyc_required',
        ], 'nium_webhook_notification:customer_entity_kyc_status');

        $metadata = UserProviderAccount::query()->findOrFail(7)->metadata;
        $this->assertArrayHasKey($this->attemptKey(self::APPLICANT_REFERENCE), $metadata['nium_submit_kyc_attempts']);
        $this->assertArrayHasKey($this->attemptKey(self::STAKEHOLDER_REFERENCE), $metadata['nium_submit_kyc_attempts']);
        $this->assertSame('nium_pending_awaiting_kyc', $metadata['integration_status']);
        $this->assertSame(
            'kyc_required',
            $metadata['nium_entity_kyc_states'][$this->attemptKey(self::STAKEHOLDER_REFERENCE)]['kyc_status'],
        );
        $this->assertSame($beforeFour, UserProviderAccount::query()->findOrFail(4)->getRawOriginal());
    }

    public function test_customer_status_webhook_preserves_local_attempt_and_simulation_claim(): void
    {
        $account = UserProviderAccount::query()->findOrFail(7);
        $metadata = $account->metadata;
        $metadata['nium_sandbox_simulation_submit_kyc_attempt'] = [
            'state' => 'submitting',
            'updated_at' => now()->toISOString(),
        ];
        $account->forceFill(['metadata' => $metadata])->save();

        $this->stateService()->applyRestrictiveNotification($account, [
            'status' => 'pending',
            'subStatus' => 'awaiting_kyc',
        ], 'nium_webhook_notification:customer_status_webhook');

        $metadata = UserProviderAccount::query()->findOrFail(7)->metadata;
        $this->assertArrayHasKey('nium_submit_kyc_attempts', $metadata);
        $this->assertSame('submitting', $metadata['nium_sandbox_simulation_submit_kyc_attempt']['state']);
    }

    public function test_stale_account_snapshot_cannot_erase_new_local_attempt_or_override_provider_fields(): void
    {
        $stale = UserProviderAccount::query()->findOrFail(7);
        $fresh = UserProviderAccount::query()->findOrFail(7);
        $metadata = $fresh->metadata;
        $newKey = 'ref_'.str_repeat('f', 16);
        $metadata['nium_submit_kyc_attempts'][$newKey] = [
            'state' => 'response_review',
            'kyc_mode' => 'biometric_kyc',
            'provider_http_status' => 200,
            'updated_at' => now()->toISOString(),
        ];
        $metadata['integration_status'] = 'untrusted_local_override';
        $metadata['arbitrary_unknown'] = 'must-not-survive';
        $fresh->forceFill(['metadata' => $metadata])->save();

        $this->stateService()->applyAuthenticatedState($stale, [
            'customerHashId' => 'customer-safe-id',
            'status' => 'pending',
            'subStatus' => 'awaiting_kyc',
        ], 'nium_webhook_notification:customer_status_webhook');

        $metadata = UserProviderAccount::query()->findOrFail(7)->metadata;
        $this->assertSame('response_review', $metadata['nium_submit_kyc_attempts'][$newKey]['state']);
        $this->assertSame('nium_pending_awaiting_kyc', $metadata['integration_status']);
        $this->assertArrayNotHasKey('arbitrary_unknown', $metadata);
    }

    public static function invalidApplicantWebhookEvidence(): array
    {
        return [
            'reference' => ['referenceId', self::STAKEHOLDER_REFERENCE],
            'entity' => ['entityType', 'individual_stakeholder'],
            'mode' => ['kycMode', 'manual_kyc'],
            'status' => ['kycStatus', 'initiated'],
        ];
    }

    public function test_simulation_fails_outside_sandbox_before_http(): void
    {
        config()->set('services.nium.base_url', 'https://gateway.nium.com');
        $this->assertSimulationFailsBeforeHttp();
    }

    public function test_missing_client_name_is_not_required_for_simulation(): void
    {
        config()->set('services.nium.client_name');
        Http::fake(['*' => Http::response(['message' => 'KYC submitted successfully.'], 200)]);

        $result = app(NiumHkSandboxKycSimulationRunner::class)->run();

        $this->assertSame('PASS_SIMULATION_HTTP_200_WAIT_FOR_WEBHOOK', $result['terminal']);
        Http::assertSent(fn ($request): bool => ! $request->hasHeader('x-client-name'));
    }

    public function test_valid_client_name_uses_existing_auth_request_id_and_json_transport(): void
    {
        Http::fake(['*' => Http::response(['message' => 'KYC submitted successfully.'], 200)]);

        $result = app(NiumHkSandboxKycSimulationRunner::class)->run();

        $this->assertSame('PASS_SIMULATION_HTTP_200_WAIT_FOR_WEBHOOK', $result['terminal']);
        Http::assertSent(function ($request): bool {
            $requestId = $request->header('x-request-id')[0] ?? null;

            return $request->method() === 'POST'
                && $request->url() === 'https://gatewaysandbox.nium.com/api/v5/simulations/onboard/customer-safe-id/transition'
                && ! $request->hasHeader('x-client-name')
                && $request->hasHeader('x-api-key', 'safe-sandbox-api-key')
                && is_string($requestId)
                && preg_match('/^[0-9a-f-]{36}$/i', $requestId) === 1
                && str_starts_with((string) $request->header('Content-Type')[0], 'application/json')
                && $request->data() === ['nextAction' => 'submit_kyc'];
        });

        $log = ApiRequestLog::query()->where('operation', 'onboarding_simulation_submit_kyc')->sole();
        $serialized = json_encode($log->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('safe-sandbox-api-key', $serialized);
    }

    public function test_simulation_requires_both_entity_http_200_evidence(): void
    {
        ApiRequestLog::query()->where('external_reference', self::STAKEHOLDER_REFERENCE)->delete();
        $this->assertSimulationFailsBeforeHttp();
    }

    public function test_unrelated_logs_do_not_satisfy_stakeholder_evidence(): void
    {
        ApiRequestLog::query()->where('external_reference', self::STAKEHOLDER_REFERENCE)->delete();
        ApiRequestLog::query()->forceCreate([
            'provider_id' => 1,
            'user_id' => 4,
            'operation' => 'submit_kyc',
            'external_reference' => self::STAKEHOLDER_REFERENCE,
            'request_method' => 'POST',
            'request_url' => '/safe/unrelated',
            'response_status' => 200,
            'transport_outcome' => 'response_received',
            'is_success' => true,
        ]);

        $this->assertSimulationFailsBeforeHttp();
    }

    public function test_simulation_fails_when_latest_customer_webhook_is_not_awaiting_kyc(): void
    {
        WebhookEvent::query()->forceCreate([
            'provider_id' => 1,
            'event_id' => 'customer-status-latest',
            'event_type' => 'CUSTOMER_STATUS_WEBHOOK',
            'external_resource_id' => 'customer-safe-id',
            'payload' => ['status' => 'pending', 'subStatus' => 'under_review'],
            'processing_status' => 'processed',
            'processed_at' => now(),
        ]);

        $this->assertSimulationFailsBeforeHttp();
    }

    public function test_simulation_is_one_shot_and_account_four_is_unchanged(): void
    {
        $before = UserProviderAccount::query()->findOrFail(4)->getRawOriginal();
        $customerState = UserProviderAccount::query()->findOrFail(7)->only(['provider_status', 'provider_sub_status']);
        $calls = $this->mockSimulationResponse(200, ['message' => 'KYC submitted successfully.']);

        $result = app(NiumHkSandboxKycSimulationRunner::class)->run();

        $this->assertSame('PASS_SIMULATION_HTTP_200_WAIT_FOR_WEBHOOK', $result['terminal']);
        $this->assertSame(1, $result['simulation_post_count']);
        $this->assertSame(['POST'], $calls->methods);
        $this->assertSame($before, UserProviderAccount::query()->findOrFail(4)->getRawOriginal());
        $this->assertSame(
            $customerState,
            UserProviderAccount::query()->findOrFail(7)->only(['provider_status', 'provider_sub_status']),
        );

        $this->mock(NiumService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('postOnboardingSimulation'));
        $this->expectException(RuntimeException::class);
        app(NiumHkSandboxKycSimulationRunner::class)->run();
    }

    public function test_http_200_with_wrong_message_holds_for_response_review(): void
    {
        $calls = $this->mockSimulationResponse(200, ['message' => 'Different sandbox message.']);

        $result = app(NiumHkSandboxKycSimulationRunner::class)->run();

        $this->assertSame('HOLD_RESPONSE_REVIEW', $result['terminal']);
        $this->assertSame(['POST'], $calls->methods);
    }

    public function test_http_200_with_malformed_body_holds_for_response_review(): void
    {
        $calls = $this->mockSimulationResponse(200, 'not-json');

        $result = app(NiumHkSandboxKycSimulationRunner::class)->run();

        $this->assertSame('HOLD_RESPONSE_REVIEW', $result['terminal']);
        $this->assertSame(['POST'], $calls->methods);
    }

    public function test_provider_rejection_has_no_retry(): void
    {
        $calls = $this->mockSimulationResponse(400, ['message' => 'Rejected']);

        $result = app(NiumHkSandboxKycSimulationRunner::class)->run();

        $this->assertSame('STOP_SIMULATION_REJECTED_NO_RETRY', $result['terminal']);
        $this->assertSame(['POST'], $calls->methods);
    }

    public function test_unrelated_simulation_log_cannot_satisfy_pass_postcondition(): void
    {
        $this->mock(NiumService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('path')->andReturn('/safe/simulation');
            $mock->shouldReceive('postOnboardingSimulation')->once()->andReturnUsing(function (): Response {
                $this->simulationLog(userId: 4, externalReference: 'different-customer');

                return new Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                    'message' => 'KYC submitted successfully.',
                ], JSON_THROW_ON_ERROR)));
            });
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('postcondition failed closed');
        app(NiumHkSandboxKycSimulationRunner::class)->run();
    }

    public function test_more_than_one_scoped_simulation_post_fails_closed(): void
    {
        $this->mock(NiumService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('path')->andReturn('/safe/simulation');
            $mock->shouldReceive('postOnboardingSimulation')->once()->andReturnUsing(function (): Response {
                $this->simulationLog();
                $this->simulationLog();

                return new Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                    'message' => 'KYC submitted successfully.',
                ], JSON_THROW_ON_ERROR)));
            });
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('postcondition failed closed');
        app(NiumHkSandboxKycSimulationRunner::class)->run();
    }

    public function test_simulation_transport_failure_has_no_retry(): void
    {
        $calls = new class { public array $methods = []; };
        $this->mock(NiumService::class, function (MockInterface $mock) use ($calls): void {
            $mock->shouldReceive('path')->andReturn('/safe/simulation');
            $mock->shouldReceive('postOnboardingSimulation')->once()->andReturnUsing(function () use ($calls): never {
                $calls->methods[] = 'POST';
                throw new ConnectionException('ambiguous');
            });
        });

        $result = app(NiumHkSandboxKycSimulationRunner::class)->run();

        $this->assertSame('STOP_SIMULATION_OUTCOME_UNKNOWN_NO_RETRY', $result['terminal']);
        $this->assertSame(['POST'], $calls->methods);
        $this->assertSame(0, $result['simulation_post_count']);
    }

    private function seedLockedEvidence(): void
    {
        IntegrationProvider::query()->forceCreate(['id' => 1, 'code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        User::factory()->create(['id' => 4]);
        User::factory()->create(['id' => 9]);
        UserProviderAccount::query()->forceCreate(['id' => 4, 'user_id' => 4, 'provider_id' => 1]);
        UserProviderAccount::query()->forceCreate([
            'id' => 7,
            'user_id' => 9,
            'provider_id' => 1,
            'external_customer_id' => 'customer-safe-id',
            'provider_status' => 'pending',
            'provider_sub_status' => 'awaiting_kyc',
            'reconciliation_status' => 'reconciled',
            'metadata' => [
                'nium_submit_kyc_attempts' => [
                    $this->attemptKey(self::APPLICANT_REFERENCE) => [
                        'state' => 'provider_accepted_200_sandbox_review',
                        'kyc_mode' => 'biometric_kyc',
                        'provider_http_status' => 200,
                    ],
                    $this->attemptKey(self::STAKEHOLDER_REFERENCE) => [
                        'state' => 'response_review',
                        'kyc_mode' => 'biometric_kyc',
                        'provider_http_status' => 200,
                    ],
                ],
            ],
        ]);
        $this->submitLog(104, self::APPLICANT_REFERENCE);
        $this->submitLog(105, self::STAKEHOLDER_REFERENCE);
        WebhookEvent::query()->forceCreate([
            'id' => 8,
            'provider_id' => 1,
            'event_id' => 'applicant-placeholder-8',
            'event_type' => 'CUSTOMER_ENTITY_KYC_STATUS',
            'external_resource_id' => 'customer-safe-id',
            'payload' => [
                'entityType' => 'applicant',
                'externalId' => 'origin-wallet-person-13',
                'referenceId' => self::APPLICANT_REFERENCE,
                'kycMode' => 'biometric_kyc',
                'kycStatus' => '${kycStatus}',
            ],
            'processing_status' => 'processed',
            'processed_at' => now(),
        ]);
        WebhookEvent::query()->forceCreate([
            'id' => 9,
            'provider_id' => 1,
            'event_id' => 'customer-awaiting-kyc',
            'event_type' => 'CUSTOMER_STATUS_WEBHOOK',
            'external_resource_id' => 'customer-safe-id',
            'payload' => ['status' => 'pending', 'subStatus' => 'awaiting_kyc'],
            'processing_status' => 'processed',
            'processed_at' => now()->subSecond(),
        ]);
    }

    private function submitLog(int $id, string $referenceId): void
    {
        ApiRequestLog::query()->forceCreate([
            'id' => $id,
            'provider_id' => 1,
            'user_id' => 9,
            'operation' => 'submit_kyc',
            'external_reference' => $referenceId,
            'request_method' => 'POST',
            'request_url' => '/safe/submitKyc',
            'response_status' => 200,
            'transport_outcome' => 'response_received',
            'is_success' => true,
            'request_finished_at' => now()->subMinute(),
        ]);
    }

    private function mockSimulationResponse(int $status, array|string $body): object
    {
        $calls = new class { public array $methods = []; };
        $this->mock(NiumService::class, function (MockInterface $mock) use ($calls, $status, $body): void {
            $mock->shouldReceive('path')->andReturn('/safe/simulation');
            $mock->shouldReceive('postOnboardingSimulation')->once()->andReturnUsing(function () use ($calls, $status, $body): Response {
                $calls->methods[] = 'POST';
                $this->simulationLog(status: $status);

                $encoded = is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body;

                return new Response(new \GuzzleHttp\Psr7\Response($status, [], $encoded));
            });
        });

        return $calls;
    }

    private function assertSimulationFailsBeforeHttp(): void
    {
        $this->mock(NiumService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('postOnboardingSimulation'));

        try {
            app(NiumHkSandboxKycSimulationRunner::class)->run();
            $this->fail('Expected simulation preflight failure.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }

    private function simulationLog(
        int $status = 200,
        int $userId = 9,
        string $externalReference = 'customer-safe-id',
    ): void {
        ApiRequestLog::query()->create([
            'provider_id' => 1,
            'user_id' => $userId,
            'operation' => 'onboarding_simulation_submit_kyc',
            'external_reference' => $externalReference,
            'request_method' => 'POST',
            'request_url' => '/safe/simulation',
            'response_status' => $status,
            'transport_outcome' => 'response_received',
            'is_success' => $status === 200,
        ]);
    }

    private function attempt(string $referenceId): array
    {
        return UserProviderAccount::query()->findOrFail(7)
            ->metadata['nium_submit_kyc_attempts'][$this->attemptKey($referenceId)];
    }

    private function setApplicantAttemptState(string $state): void
    {
        $account = UserProviderAccount::query()->findOrFail(7);
        $metadata = $account->metadata;
        $metadata['nium_submit_kyc_attempts'][$this->attemptKey(self::APPLICANT_REFERENCE)]['state'] = $state;
        $account->forceFill(['metadata' => $metadata])->save();
    }

    private function removeApplicantAttempt(): void
    {
        $account = UserProviderAccount::query()->findOrFail(7);
        $metadata = $account->metadata;
        unset($metadata['nium_submit_kyc_attempts'][$this->attemptKey(self::APPLICANT_REFERENCE)]);
        $account->forceFill(['metadata' => $metadata])->save();
    }

    private function applicantWebhook(int $id): void
    {
        WebhookEvent::query()->forceCreate([
            'id' => $id,
            'provider_id' => 1,
            'event_id' => 'applicant-placeholder-'.$id,
            'event_type' => 'CUSTOMER_ENTITY_KYC_STATUS',
            'external_resource_id' => 'customer-safe-id',
            'payload' => [
                'entityType' => 'applicant',
                'externalId' => 'origin-wallet-person-13',
                'referenceId' => self::APPLICANT_REFERENCE,
                'kycMode' => 'biometric_kyc',
                'kycStatus' => '${kycStatus}',
            ],
            'processing_status' => 'processed',
            'processed_at' => now(),
        ]);
    }

    private function stateService(): NiumProviderAccountStateService
    {
        return app(NiumProviderAccountStateService::class);
    }

    private function attemptKey(string $referenceId): string
    {
        return 'ref_'.substr(hash('sha256', $referenceId), 0, 16);
    }
}
