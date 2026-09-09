<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\AuditLog;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumService;
use App\Services\Nium\NiumStagingUiFixtureRebindService;
use App\Services\Nium\NiumWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class NiumStagingUiFixtureRebindTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_CUSTOMER_ID = 'b4e39b04-08dc-4f03-810a-b96b60950ee1';

    private const OLD_EXTERNAL_REFERENCE = 'old-customer-external-reference';

    private const NEW_EXTERNAL_REFERENCE = 'approved-diagnostic-external-reference';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn (): string => 'staging');
        config()->set('services.nium.base_url', 'https://gateway.sandbox.nium.test');
        config()->set('services.nium.client_id', 'client-test');
        config()->set('services.nium.customer_get_endpoint', '/api/v5/client/{clientHashId}/customer/{customerHashId}');
        config()->set('services.nium.auth', [
            'mode' => 'header',
            'header_name' => 'x-api-key',
            'header_value' => 'test-key',
        ]);
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'test-partner-key');
        $this->seedFixture();
    }

    public function test_successful_rebind_uses_authenticated_get_state_service_and_fingerprinted_audit(): void
    {
        $this->fakeCustomerGet();

        $this->artisan('nium:rebind-staging-ui-fixture', $this->commandOptions())
            ->assertSuccessful();

        $account = UserProviderAccount::query()->findOrFail(7);
        $this->assertSame(self::NEW_EXTERNAL_REFERENCE, $account->external_reference);
        $this->assertSame(NiumStagingUiFixtureRebindService::CUSTOMER_HASH_ID, $account->external_customer_id);
        $this->assertSame(NiumStagingUiFixtureRebindService::WALLET_HASH_ID, $account->external_account_id);
        $this->assertClearPostcondition($account);

        $evidence = ApiRequestLog::query()
            ->where('operation', NiumStagingUiFixtureRebindService::EVIDENCE_OPERATION)
            ->sole();
        $audit = AuditLog::query()
            ->where('action', NiumStagingUiFixtureRebindService::AUDIT_ACTION)
            ->sole();
        $this->assertSame('pending', $audit->old_data['provider_status']);
        $this->assertSame('clear', $audit->new_data['provider_status']);
        $this->assertArrayHasKey('external_reference_fingerprint', $audit->old_data);
        $this->assertArrayHasKey('external_reference_fingerprint', $audit->new_data);
        $this->assertStringNotContainsString(self::OLD_EXTERNAL_REFERENCE, json_encode($audit->toArray()));
        $this->assertStringNotContainsString(self::NEW_EXTERNAL_REFERENCE, json_encode($audit->toArray()));
        $this->assertSame(NiumStagingUiFixtureRebindService::APPROVAL, $audit->new_data['approval_marker']);
        $this->assertSame('operator@example.com ticket NIUM-UI-7', $audit->new_data['operator_context']);
        $this->assertSame($evidence->id, $audit->new_data['authenticated_get_evidence']['api_request_log_id']);
        $this->assertSame('staging_ui_flow_test_fixture_rebind', $audit->new_data['reason']);
    }

    public function test_production_environment_is_rejected_without_http(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        Http::fake();

        $this->artisan('nium:rebind-staging-ui-fixture', $this->commandOptions())
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_wrong_authenticated_identifiers_are_rejected(): void
    {
        $this->fakeCustomerGet(['wallets' => [['walletHashId' => 'wrong-wallet-id']]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not match');

        $this->service()->rebind(
            NiumStagingUiFixtureRebindService::APPROVAL,
            'ticket NIUM-UI-7',
        );
    }

    public function test_non_clear_authenticated_response_is_rejected(): void
    {
        $this->fakeCustomerGet(['status' => 'pending', 'subStatus' => 'under_review']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not match');

        $this->service()->rebind(
            NiumStagingUiFixtureRebindService::APPROVAL,
            'ticket NIUM-UI-7',
        );
    }

    public function test_identifier_ownership_collision_is_rejected(): void
    {
        User::factory()->create(['id' => 10]);
        UserProviderAccount::query()->forceCreate([
            'id' => 8,
            'user_id' => 10,
            'provider_id' => 7,
            'external_reference' => self::NEW_EXTERNAL_REFERENCE,
            'status' => 'pending',
        ]);
        $this->fakeCustomerGet();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('owned by another');

        $this->service()->rebind(
            NiumStagingUiFixtureRebindService::APPROVAL,
            'ticket NIUM-UI-7',
        );
    }

    public function test_missing_wallets_are_rejected(): void
    {
        $this->fakeCustomerGet([], ['wallets']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not match');

        $this->service()->rebind(NiumStagingUiFixtureRebindService::APPROVAL, 'missing wallets ticket');
    }

    public function test_empty_wallets_are_rejected(): void
    {
        $this->fakeCustomerGet(['wallets' => []]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not match');

        $this->service()->rebind(NiumStagingUiFixtureRebindService::APPROVAL, 'empty wallets ticket');
    }

    public function test_wrong_nested_wallet_identifier_is_rejected(): void
    {
        $this->fakeCustomerGet(['wallets' => [['walletHashId' => 'wrong-nested-wallet']]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not match');

        $this->service()->rebind(NiumStagingUiFixtureRebindService::APPROVAL, 'wrong wallet ticket');
    }

    public function test_locked_fixture_state_change_during_get_is_rejected_without_mutation(): void
    {
        $this->fakeCustomerGet([], [], function (): void {
            UserProviderAccount::query()->findOrFail(7)->update([
                'external_customer_id' => 'changed-before-transaction',
                'status' => 'under_review',
            ]);
        });

        try {
            $this->service()->rebind(NiumStagingUiFixtureRebindService::APPROVAL, 'changed state ticket');
            $this->fail('Expected locked fixture state guard failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('pre-mutation state guard failed', $exception->getMessage());
        }

        $account = UserProviderAccount::query()->findOrFail(7);
        $this->assertSame(self::OLD_EXTERNAL_REFERENCE, $account->external_reference);
        $this->assertSame('changed-before-transaction', $account->external_customer_id);
        $this->assertSame('under_review', $account->status);
        $this->assertDatabaseMissing('audit_logs', ['action' => NiumStagingUiFixtureRebindService::AUDIT_ACTION]);
    }

    public function test_stale_evidence_log_is_not_accepted_for_current_execution(): void
    {
        ApiRequestLog::query()->create([
            'provider_id' => 7,
            'user_id' => 9,
            'operation' => NiumStagingUiFixtureRebindService::EVIDENCE_OPERATION,
            'request_method' => 'GET',
            'request_url' => 'https://gateway.sandbox.nium.test/stale-evidence',
            'response_status' => 200,
            'response_body' => [],
            'transport_outcome' => 'response_received',
            'is_success' => true,
        ]);
        $mock = Mockery::mock(NiumService::class);
        $mock->shouldReceive('clientId')->once()->andReturn('client-test');
        $mock->shouldReceive('path')->once()->andReturn('/authenticated-customer-get');
        $mock->shouldReceive('get')->once()->andReturn(new Response(new \GuzzleHttp\Psr7\Response(
            200,
            [],
            json_encode($this->realCustomerPayload(), JSON_THROW_ON_ERROR),
        )));
        $this->app->instance(NiumService::class, $mock);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not match');

        $this->service()->rebind(NiumStagingUiFixtureRebindService::APPROVAL, 'stale evidence ticket');
    }

    public function test_duplicate_execution_is_rejected_without_second_get(): void
    {
        $this->fakeCustomerGet();
        $this->service()->rebind(NiumStagingUiFixtureRebindService::APPROVAL, 'first ticket');

        try {
            $this->service()->rebind(NiumStagingUiFixtureRebindService::APPROVAL, 'second ticket');
            $this->fail('Expected duplicate remediation execution to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already executed', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_transaction_rolls_back_when_clear_postcondition_fails(): void
    {
        $this->fakeCustomerGet(['complianceStatus' => 'failed']);

        try {
            $this->service()->rebind(NiumStagingUiFixtureRebindService::APPROVAL, 'rollback ticket');
            $this->fail('Expected the remediation postcondition to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('postcondition failed', $exception->getMessage());
        }

        $account = UserProviderAccount::query()->findOrFail(7);
        $this->assertSame(self::OLD_EXTERNAL_REFERENCE, $account->external_reference);
        $this->assertSame(NiumStagingUiFixtureRebindService::CUSTOMER_HASH_ID, $account->external_customer_id);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => NiumStagingUiFixtureRebindService::AUDIT_ACTION,
        ]);
    }

    public function test_old_customer_webhook_cannot_alter_or_quarantine_rebound_account(): void
    {
        $this->fakeCustomerGet();
        $account = $this->service()->rebind(NiumStagingUiFixtureRebindService::APPROVAL, 'old webhook ticket');
        $before = $account->fresh()->only([
            'external_reference',
            'external_customer_id',
            'external_account_id',
            'status',
            'provider_status',
            'provider_sub_status',
            'reconciliation_status',
            'security_conflict_at',
            'security_conflict_reason',
        ]);

        try {
            app(NiumWebhookService::class)->handleWebhook(
                IntegrationProvider::query()->findOrFail(7),
                $this->webhookRequest([
                    'customerHashId' => self::OLD_CUSTOMER_ID,
                    'externalId' => self::OLD_EXTERNAL_REFERENCE,
                    'status' => 'pending',
                    'subStatus' => 'under_review',
                ], 'old-customer-webhook-request'),
            );
            $this->fail('Expected old customer webhook mapping to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('webhook_processing_failed', $exception->getMessage());
        }

        $this->assertSame($before, UserProviderAccount::query()->findOrFail(7)->only(array_keys($before)));
        $this->assertDatabaseHas('webhook_events', [
            'event_id' => 'old-customer-webhook-request',
            'processing_status' => 'failed',
        ]);
    }

    public function test_matching_new_customer_webhook_keeps_account_active_and_clear(): void
    {
        $this->fakeCustomerGet();
        $this->service()->rebind(NiumStagingUiFixtureRebindService::APPROVAL, 'new webhook ticket');

        app(NiumWebhookService::class)->handleWebhook(
            IntegrationProvider::query()->findOrFail(7),
            $this->webhookRequest([
                'customerHashId' => NiumStagingUiFixtureRebindService::CUSTOMER_HASH_ID,
                'walletHashId' => NiumStagingUiFixtureRebindService::WALLET_HASH_ID,
                'externalId' => self::NEW_EXTERNAL_REFERENCE,
                'status' => 'clear',
                'subStatus' => null,
            ], 'new-customer-webhook-request'),
        );

        $this->assertClearPostcondition(UserProviderAccount::query()->findOrFail(7));
    }

    private function seedFixture(): void
    {
        IntegrationProvider::query()->forceCreate([
            'id' => 7,
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);
        User::factory()->create([
            'id' => 9,
            'status' => 'active',
            'kyc_status' => 'verified',
        ]);
        UserProviderAccount::query()->forceCreate([
            'id' => 7,
            'user_id' => 9,
            'provider_id' => 7,
            'external_customer_id' => NiumStagingUiFixtureRebindService::CUSTOMER_HASH_ID,
            'external_account_id' => NiumStagingUiFixtureRebindService::WALLET_HASH_ID,
            'external_reference' => self::OLD_EXTERNAL_REFERENCE,
            'status' => 'blocked',
            'provider_status' => 'pending',
            'provider_sub_status' => 'under_review',
            'reconciliation_status' => 'quarantined',
            'reconciliation_error' => 'verified_identifier_mismatch',
            'security_conflict_at' => now()->subHour(),
            'security_conflict_reason' => 'external_reference_mismatch',
        ]);
    }

    private function fakeCustomerGet(array $overrides = [], array $remove = [], ?callable $duringRequest = null): void
    {
        $payload = array_replace($this->realCustomerPayload(), $overrides);

        foreach ($remove as $key) {
            unset($payload[$key]);
        }

        Http::fake(function (HttpRequest $request) use ($duringRequest, $payload) {
            $this->assertSame('GET', $request->method());
            $this->assertSame(
                'https://gateway.sandbox.nium.test/api/v5/client/client-test/customer/'.NiumStagingUiFixtureRebindService::CUSTOMER_HASH_ID,
                $request->url(),
            );
            $this->assertSame('test-key', $request->header('x-api-key')[0] ?? null);
            if ($duringRequest !== null) {
                $duringRequest();
            }

            return Http::response($payload, 200);
        });
    }

    private function realCustomerPayload(): array
    {
        return [
            'wallets' => [[
                'walletHashId' => NiumStagingUiFixtureRebindService::WALLET_HASH_ID,
            ]],
            'customerHashId' => NiumStagingUiFixtureRebindService::CUSTOMER_HASH_ID,
            'status' => 'clear',
            'subStatus' => null,
            'externalId' => self::NEW_EXTERNAL_REFERENCE,
        ];
    }

    private function webhookRequest(array $payload, string $requestId): Request
    {
        $payload = [
            'template' => 'CUSTOMER_STATUS_WEBHOOK',
            'clientHashId' => 'client-test',
            ...$payload,
        ];

        return Request::create('/api/webhooks/providers/nium', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PARTNER_KEY' => 'test-partner-key',
            'HTTP_X_REQUEST_ID' => $requestId,
        ], content: json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function assertClearPostcondition(UserProviderAccount $account): void
    {
        $this->assertSame('active', $account->status);
        $this->assertSame('clear', $account->provider_status);
        $this->assertNull($account->provider_sub_status);
        $this->assertSame('reconciled', $account->reconciliation_status);
        $this->assertNull($account->reconciliation_error);
        $this->assertNull($account->security_conflict_at);
        $this->assertNull($account->security_conflict_reason);
    }

    private function commandOptions(): array
    {
        return [
            '--approve' => NiumStagingUiFixtureRebindService::APPROVAL,
            '--operator' => 'operator@example.com ticket NIUM-UI-7',
        ];
    }

    private function service(): NiumStagingUiFixtureRebindService
    {
        return app(NiumStagingUiFixtureRebindService::class);
    }
}
