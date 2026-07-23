<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\IntegrationProvider;
use App\Models\KycDocument;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Integrations\ProviderOnboardingManager;
use App\Services\Nium\NiumCustomerDocumentPreparationService;
use App\Services\Nium\NiumCustomerOnboardingService;
use App\Services\Nium\NiumProviderAccountStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Fixtures\RedirectOnboardingProvider;
use Tests\TestCase;

class NiumCustomerOnboardingV5Test extends TestCase
{
    use RefreshDatabase;

    private const INDIVIDUAL_FILE_ID = '11111111-1111-4111-8111-111111111111';

    private const SECOND_FILE_ID = '22222222-2222-4222-8222-222222222222';

    private const BUSINESS_FILE_ID = '33333333-3333-4333-8333-333333333333';

    private const APPLICANT_FILE_ID = '44444444-4444-4444-8444-444444444444';

    private const STAKEHOLDER_FILE_ID = '55555555-5555-4555-8555-555555555555';

    private const REPLACEMENT_FILE_ID = '66666666-6666-4666-8666-666666666666';

    private const DUPLICATE_WINNER_FILE_ID = '77777777-7777-4777-8777-777777777777';

    private const MULTI_DOCUMENT_FILE_ID = '88888888-8888-4888-8888-888888888888';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('kyc_private');
        config()->set('services.kyc.documents_disk', 'kyc_private');
        config()->set('services.nium.base_url', 'https://gateway.nium.test');
        config()->set('services.nium.client_id', 'b23b124c-9cc8-4550-b66f-ed8250ff8a5e');
        config()->set('services.nium.auth.mode', 'header');
        config()->set('services.nium.auth.header_name', 'x-api-key');
        config()->set('services.nium.auth.header_value', 'sandbox-api-key');
        config()->set('services.nium.file_base_url', 'https://document-storage-sandbox.nium.test');
        config()->set('services.nium.file_create_endpoint', '/api/v1/client/{clientHashId}/files');
        config()->set('services.nium.file_details_endpoint', '/api/v1/client/{clientHashId}/files/{fileId}');
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'verified-partner-key');
    }

    public function test_v5_customer_creation_stores_only_ids_and_state_from_authenticated_nium_response(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $createResponse = $this->fixture('customer-v5-create-response.json');
        $transactionLevel = DB::transactionLevel();

        Http::fake(function (Request $request) use ($createResponse, $transactionLevel) {
            $this->assertSame($transactionLevel, DB::transactionLevel());

            if ($request->method() === 'GET') {
                return Http::response(['customers' => []], 200);
            }

            return Http::response([
                ...$createResponse,
                'externalId' => $request->data()['externalId'],
            ], 200);
        });

        $response = $this->withToken($this->issueTokenFor($user))
            ->postJson("/api/user/users/{$user->id}/provider-accounts/nium/link");

        $response->assertOk()
            ->assertJsonPath('provider_account.external_customer_id', $createResponse['customerHashId'])
            ->assertJsonPath('provider_account.external_account_id', $createResponse['wallets'][0]['walletHashId'])
            ->assertJsonPath('provider_account.provider_status', 'clear')
            ->assertJsonPath('provider_account.provider_sub_status', null)
            ->assertJsonPath('provider_account.status', 'active');

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST') {
                return false;
            }

            $payload = $request->data();
            $serializedPayload = json_encode($payload, JSON_THROW_ON_ERROR);

            return $request->url() === 'https://gateway.nium.test/api/v5/client/b23b124c-9cc8-4550-b66f-ed8250ff8a5e/customers'
                && $request->hasHeader('x-api-key', 'sandbox-api-key')
                && $request->hasHeader('x-request-id')
                && Str::isUuid($payload['externalId'])
                && $payload['type'] === 'individual'
                && $payload['annualIncome'] === 'gb005'
                && $payload['incomeSourceType'] === 'salaried_employee'
                && $payload['expectedAccountUsage']['intendedUses'] === ['iu002', 'iu003']
                && $payload['natureOfBusiness']['industryCodes'] === ['is112']
                && $payload['documents'][0]['fileIds'] === [self::INDIVIDUAL_FILE_ID]
                && ! array_key_exists('customerHashId', $payload)
                && ! array_key_exists('walletHashId', $payload)
                && ! array_key_exists('status', $payload)
                && ! str_contains($serializedPayload, 'files.example.test')
                && ! str_contains($serializedPayload, 'kyc/')
                && ! str_contains($serializedPayload, 'storagePath');
        });
        Http::assertNotSent(fn (Request $request): bool => str_contains(
            $request->url(),
            'document-storage-sandbox.nium.test',
        ));

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'provider_account.nium_state_changed',
        ]);
    }

    public function test_frontend_supplied_status_and_provider_ids_are_rejected_and_cannot_overwrite_ids(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $account = $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => 'server-customer-hash',
            'external_account_id' => 'server-wallet-hash',
            'external_reference' => (string) Str::uuid(),
            'status' => 'submitted',
            'provider_status' => 'pending',
            'provider_ids_verified_at' => now(),
        ]);

        $this->withToken($this->issueTokenFor($user))
            ->postJson("/api/user/users/{$user->id}/provider-accounts/nium/complete", [
                'status' => 'active',
                'external_customer_id' => 'attacker-customer-hash',
                'external_account_id' => 'attacker-wallet-hash',
                'customerHashId' => 'attacker-customer-hash',
                'walletHashId' => 'attacker-wallet-hash',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Nium status and provider identifiers can only be updated from authenticated Nium responses.');

        $account->refresh();
        $this->assertSame('server-customer-hash', $account->external_customer_id);
        $this->assertSame('server-wallet-hash', $account->external_account_id);
        $this->assertSame('submitted', $account->status);
    }

    public function test_v5_customer_retrieval_refreshes_backend_controlled_state(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $responsePayload = $this->fixture('customer-v5-create-response.json');
        $responsePayload['externalId'] = (string) Str::uuid();
        $account = $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => $responsePayload['customerHashId'],
            'external_account_id' => $responsePayload['wallets'][0]['walletHashId'],
            'external_reference' => $responsePayload['externalId'],
            'status' => 'under_review',
            'provider_status' => 'pending',
            'provider_sub_status' => 'under_review',
            'provider_ids_verified_at' => now(),
        ]);

        Http::fake([
            'https://gateway.nium.test/api/v5/client/*/customer/*' => Http::response($responsePayload, 200),
        ]);

        $refreshed = app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);

        $this->assertSame($account->id, $refreshed->id);
        $this->assertSame('clear', $refreshed->provider_status);
        $this->assertNull($refreshed->provider_sub_status);
        $this->assertSame('active', $refreshed->status);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://gateway.nium.test/api/v5/client/b23b124c-9cc8-4550-b66f-ed8250ff8a5e/customer/2ba22977-eb3d-4db0-aa3f-7d8459ed6420');
    }

    public function test_verified_customer_status_webhooks_map_rfi_and_only_clear_empty_substatus_activates(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $account = $this->pendingAccount($user, $provider);
        $rfi = $this->fixture('customer-status-rfi-webhook.json');
        $rfi['externalId'] = $account->external_reference;
        $clear = $this->fixture('customer-status-clear-webhook.json');
        $clear['externalId'] = $account->external_reference;
        Http::fakeSequence()
            ->push($this->authoritativeCustomer($account, $rfi), 200)
            ->push($this->authoritativeCustomer($account, $clear), 200);

        $this->withHeaders([
            'x-partner-key' => 'verified-partner-key',
            'x-request-id' => 'customer-status-rfi-001',
        ])->postJson('/api/webhooks/providers/nium', $rfi)
            ->assertOk()
            ->assertJsonPath('event_id', 'customer-status-rfi-001');

        $account->refresh();
        $this->assertSame('clear', $account->provider_status);
        $this->assertSame('rfi_requested', $account->provider_sub_status);
        $this->assertSame('requested', $account->rfi_status);
        $this->assertSame('under_review', $account->status);
        $this->assertSame($rfi['customerHashId'], $account->external_customer_id);
        $this->assertSame($rfi['walletHashIds'][0], $account->external_account_id);

        $this->withHeaders([
            'x-partner-key' => 'verified-partner-key',
            'x-request-id' => 'customer-status-clear-001',
        ])->postJson('/api/webhooks/providers/nium', $clear)->assertOk();

        $account->refresh();
        $this->assertSame('clear', $account->provider_status);
        $this->assertNull($account->provider_sub_status);
        $this->assertSame('cleared', $account->rfi_status);
        $this->assertSame('active', $account->status);
    }

    public function test_duplicate_webhook_request_id_is_idempotent(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $account = $this->pendingAccount($user, $provider);
        $rfi = $this->fixture('customer-status-rfi-webhook.json');
        $rfi['externalId'] = $account->external_reference;
        Http::fake([
            '*' => Http::response($this->authoritativeCustomer($account, $rfi), 200),
        ]);

        $headers = [
            'x-partner-key' => 'verified-partner-key',
            'x-request-id' => 'duplicate-customer-state-001',
        ];

        $this->withHeaders($headers)->postJson('/api/webhooks/providers/nium', $rfi)->assertOk();

        $clear = $this->fixture('customer-status-clear-webhook.json');
        $clear['externalId'] = $account->external_reference;
        $this->withHeaders($headers)
            ->postJson('/api/webhooks/providers/nium', $clear)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $account->refresh();
        $this->assertSame('rfi_requested', $account->provider_sub_status);
        $this->assertSame('under_review', $account->status);
        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_customer_entity_kyc_webhook_persists_compliance_detail_without_activating(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $account = $this->pendingAccount($user, $provider, withAuthenticatedIds: true);
        $payload = $this->fixture('customer-entity-kyc-status-webhook.json');
        $payload['customerHashId'] = $account->external_customer_id;
        Http::fake([
            '*' => Http::response($this->authoritativeCustomer($account, $payload, 'pending'), 200),
        ]);

        $this->withHeaders([
            'x-partner-key' => 'verified-partner-key',
            'x-request-id' => 'entity-kyc-001',
        ])->postJson('/api/webhooks/providers/nium', $payload)->assertOk();

        $account->refresh();
        $this->assertSame('submitted', $account->status);
        $this->assertSame(
            'submitted',
            $account->metadata['nium_entity_kyc_states']['b80612ea-1822-4788-aa3d-f0b4585f6015']['kycStatus'],
        );
    }

    public function test_invalid_partner_key_is_forbidden_and_valid_partner_key_accepts_official_payload(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $account = $this->pendingAccount($user, $provider);
        $payload = $this->fixture('customer-status-rfi-webhook.json');
        $payload['externalId'] = $account->external_reference;
        Http::fake([
            '*' => Http::response($this->authoritativeCustomer($account, $payload), 200),
        ]);

        $this->withHeader('x-partner-key', 'invalid-key')
            ->postJson('/api/webhooks/providers/nium', $payload)
            ->assertForbidden();

        $this->withHeaders([
            'x-partner-key' => 'verified-partner-key',
            'x-request-id' => 'valid-partner-key-001',
        ])->postJson('/api/webhooks/providers/nium', $payload)
            ->assertOk();

        $this->assertDatabaseHas('webhook_events', [
            'event_id' => 'valid-partner-key-001',
            'event_type' => 'CUSTOMER_STATUS_WEBHOOK',
            'processing_status' => 'processed',
        ]);
    }

    public function test_verified_registration_and_compliance_webhooks_persist_wallet_and_compliance_state(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $account = $this->pendingAccount($user, $provider);
        $account->update([
            'external_customer_id' => '2ba22977-eb3d-4db0-aa3f-7d8459ed6420',
        ]);
        $registration = $this->fixture('customer-registration-webhook.json');
        $compliance = $this->fixture('customer-compliance-status-webhook.json');
        Http::fakeSequence()
            ->push($this->authoritativeCustomer($account, $registration, 'pending'), 200)
            ->push($this->authoritativeCustomer($account, $compliance, 'clear'), 200);

        $this->withHeaders([
            'x-partner-key' => 'verified-partner-key',
            'x-request-id' => 'customer-registration-001',
        ])->postJson(
            '/api/webhooks/providers/nium',
            $registration,
        )->assertOk();

        $account->refresh();
        $this->assertSame('235a58d9-9a83-4e98-9711-a5fa1dcfecda', $account->external_account_id);
        $this->assertNotNull($account->provider_ids_verified_at);
        $this->assertSame('submitted', $account->status);

        $this->withHeaders([
            'x-partner-key' => 'verified-partner-key',
            'x-request-id' => 'customer-compliance-001',
        ])->postJson(
            '/api/webhooks/providers/nium',
            $compliance,
        )->assertOk();

        $account->refresh();
        $this->assertSame('completed', $account->compliance_status);
        $this->assertSame('clear', $account->provider_status);
        $this->assertSame('active', $account->status);
    }

    public function test_verified_odd_webhook_persists_due_diligence_state_without_disabling_clear_customer(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $account = $this->pendingAccount($user, $provider, withAuthenticatedIds: true);
        $account->update([
            'status' => 'active',
            'provider_status' => 'clear',
        ]);
        $payload = $this->fixture('customer-odd-status-webhook.json');
        $payload['externalId'] = $account->external_reference;
        Http::fake([
            '*' => Http::response($this->authoritativeCustomer($account, $payload, 'clear'), 200),
        ]);

        $this->withHeaders([
            'x-partner-key' => 'verified-partner-key',
            'x-request-id' => 'customer-odd-001',
        ])->postJson('/api/webhooks/providers/nium', $payload)->assertOk();

        $account->refresh();
        $this->assertSame('odd_due', $account->odd_status);
        $this->assertSame('clear', $account->provider_status);
        $this->assertSame('active', $account->status);
    }

    public function test_beneficiary_balance_and_payout_are_blocked_until_nium_customer_and_wallet_are_eligible(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $this->pendingAccount($user, $provider);
        $token = $this->issueTokenFor($user);

        $this->withToken($token)
            ->postJson("/api/user/users/{$user->id}/beneficiaries", [
                'provider_id' => $provider->id,
                'beneficiary_type' => 'personal',
                'full_name' => 'Jane Beneficiary',
                'country_code' => 'GB',
                'currency' => 'GBP',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Nium customer and wallet are not eligible yet (pending).');

        $this->withToken($token)
            ->postJson("/api/user/users/{$user->id}/providers/nium/sync/balances")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Nium customer and wallet are not eligible yet (pending).');

        $this->withToken($token)
            ->postJson("/api/user/users/{$user->id}/transfers", [
                'provider_id' => $provider->id,
                'transfer_type' => 'payout',
                'source_currency' => 'USD',
                'target_currency' => 'GBP',
                'source_amount' => 100,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Nium customer and wallet are not eligible yet (pending).');
    }

    public function test_existing_non_nium_onboarding_provider_behavior_is_unchanged(): void
    {
        config()->set('integrations.providers.hosted_provider.onboarding', RedirectOnboardingProvider::class);
        config()->set('services.hosted_provider.base_url', 'https://api.hosted-provider.test');

        $provider = IntegrationProvider::query()->create([
            'code' => 'HOSTED_PROVIDER',
            'name' => 'Hosted Provider',
            'status' => 'active',
        ]);
        $user = User::factory()->create(['kyc_status' => 'verified']);
        $user->profile()->create(['user_type' => 'individual']);
        $user->kycProviderSubmissions()->create([
            'provider_id' => $provider->id,
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $manager = app(ProviderOnboardingManager::class);
        $started = $manager->linkUser($provider, $user->load('profile'));
        $this->assertSame('redirect_to_provider', $started->nextAction);

        $completed = $manager->completeUserOnboarding($provider, $user->fresh('profile'), [
            'status' => 'active',
            'external_customer_id' => 'hosted-customer-id',
            'external_account_id' => 'hosted-account-id',
        ]);

        $this->assertSame('active', $completed->providerAccount->status);
        $this->assertSame('hosted-customer-id', $completed->providerAccount->external_customer_id);
        $this->assertSame('hosted-account-id', $completed->providerAccount->external_account_id);
    }

    public function test_nium_onboarding_logs_use_an_allowlist_and_never_store_pii_or_credentials(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $createResponse = $this->fixture('customer-v5-create-response.json');

        Http::fake(function (Request $request) use ($createResponse) {
            if ($request->method() === 'GET') {
                return Http::response(['customers' => []]);
            }

            return Http::response([
                ...$createResponse,
                'externalId' => $request->data()['externalId'],
                'authenticationCode' => 'secret-authentication-code',
                'identityDocument' => ['documentNumber' => 'PR123456'],
            ]);
        });

        app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);

        $serialized = ApiRequestLog::query()->get()
            ->map(fn (ApiRequestLog $log): string => json_encode($log->toArray(), JSON_THROW_ON_ERROR))
            ->implode('\n');

        foreach ([
            'John Doe', $user->email, $user->phone, 'john.doe@example.com', '1985-05-15',
            '456 Corporate Ave', 'PR123456', 'sandbox-api-key',
            'secret-authentication-code', 'x-partner-key',
            'safe-individual-file-bytes', "kyc/{$user->id}/passport-front.jpg", 'storagePath',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }

    public function test_webhook_cannot_activate_when_authoritative_get_customer_is_restrictive(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $account = $this->pendingAccount($user, $provider, withAuthenticatedIds: true);
        $account->update(['status' => 'active', 'provider_status' => 'clear']);
        $clearNotification = $this->fixture('customer-status-clear-webhook.json');
        $clearNotification['externalId'] = $account->external_reference;
        $authoritative = $this->authoritativeCustomer($account, $clearNotification);
        $authoritative['subStatus'] = 'rfi_requested';
        Http::fake(['*' => Http::response($authoritative)]);

        $this->withHeaders([
            'x-partner-key' => 'verified-partner-key',
            'x-request-id' => 'authoritative-rfi-001',
        ])->postJson('/api/webhooks/providers/nium', $clearNotification)->assertOk();

        $account->refresh();
        $this->assertSame('under_review', $account->status);
        $this->assertSame('rfi_requested', $account->provider_sub_status);
        $this->assertSame('reconciled', $account->reconciliation_status);
    }

    public function test_get_customer_failure_retains_event_and_restriction_for_retry(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $account = $this->pendingAccount($user, $provider, withAuthenticatedIds: true);
        $account->update(['status' => 'active', 'provider_status' => 'clear']);
        $rfi = $this->fixture('customer-status-rfi-webhook.json');
        $rfi['externalId'] = $account->external_reference;
        Http::fake(['*' => Http::response(['errors' => [['code' => 'temporary_unavailable']]], 503)]);

        $this->withHeaders([
            'x-partner-key' => 'verified-partner-key',
            'x-request-id' => 'reconcile-failure-001',
        ])->postJson('/api/webhooks/providers/nium', $rfi)->assertUnprocessable();

        $account->refresh();
        $this->assertSame('under_review', $account->status);
        $this->assertSame('failed', $account->reconciliation_status);
        $this->assertDatabaseHas('webhook_events', [
            'event_id' => 'reconcile-failure-001',
            'processing_status' => 'failed',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'provider_account.nium_reconciliation_failed',
        ]);
    }

    public function test_webhook_identifier_conflict_is_quarantined_without_overwriting_ids(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $account = $this->pendingAccount($user, $provider, withAuthenticatedIds: true);
        $payload = $this->fixture('customer-status-clear-webhook.json');
        $payload['externalId'] = $account->external_reference;
        $payload['customerHashId'] = 'different-authenticated-customer';
        Http::fake();

        $this->withHeaders([
            'x-partner-key' => 'verified-partner-key',
            'x-request-id' => 'identifier-conflict-001',
        ])->postJson('/api/webhooks/providers/nium', $payload)->assertUnprocessable();

        $account->refresh();
        $this->assertSame('2ba22977-eb3d-4db0-aa3f-7d8459ed6420', $account->external_customer_id);
        $this->assertSame('blocked', $account->status);
        $this->assertSame('quarantined', $account->reconciliation_status);
        $this->assertDatabaseHas('webhook_events', ['event_id' => 'identifier-conflict-001']);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'provider_account.nium_security_conflict',
        ]);
        Http::assertNothingSent();
    }

    public function test_lifecycle_webhook_requires_header_request_id_and_ignores_payload_event_id(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $account = $this->pendingAccount($user, $provider);
        $payload = $this->fixture('customer-status-rfi-webhook.json');
        $payload['externalId'] = $account->external_reference;
        $payload['eventId'] = 'payload-controlled-id';

        $this->withHeader('x-partner-key', 'verified-partner-key')
            ->postJson('/api/webhooks/providers/nium', $payload)
            ->assertUnprocessable();
        $this->assertDatabaseCount('webhook_events', 0);

        Http::fake(['*' => Http::response($this->authoritativeCustomer($account, $payload))]);
        $this->withHeaders([
            'x-partner-key' => 'verified-partner-key',
            'x-request-id' => 'canonical-header-id',
        ])->postJson('/api/webhooks/providers/nium', $payload)->assertOk();

        $this->assertDatabaseHas('webhook_events', ['event_id' => 'canonical-header-id']);
        $this->assertDatabaseMissing('webhook_events', ['event_id' => 'payload-controlled-id']);
    }

    public function test_mismatching_client_hash_id_is_rejected_before_any_mutation(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $account = $this->pendingAccount($user, $provider);
        $payload = $this->fixture('customer-status-rfi-webhook.json');
        $payload['externalId'] = $account->external_reference;
        $payload['clientHashId'] = 'different-client';

        $this->withHeaders([
            'x-partner-key' => 'verified-partner-key',
            'x-request-id' => 'wrong-client-001',
        ])->postJson('/api/webhooks/providers/nium', $payload)->assertForbidden();

        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertSame('submitted', $account->fresh()->status);
    }

    public function test_duplicate_external_id_recovery_requires_exact_customer_and_wallet(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $externalId = null;
        $listCalls = 0;

        Http::fake(function (Request $request) use (&$externalId, &$listCalls) {
            if ($request->method() === 'POST') {
                $externalId = $request->data()['externalId'];

                return Http::response(['errors' => [['code' => 'customer_exists']]], 409);
            }

            $listCalls++;

            return $listCalls === 1
                ? Http::response(['customers' => []])
                : Http::response(['customers' => [[
                    'externalId' => $externalId,
                    'customerHashId' => 'recovered-customer-id',
                    'status' => 'clear',
                    'subStatus' => '',
                ]]]);
        });

        $account = app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);

        $this->assertSame('recovered-customer-id', $account->external_customer_id);
        $this->assertNull($account->external_account_id);
        $this->assertNotNull($account->customer_id_verified_at);
        $this->assertNull($account->wallet_id_verified_at);
        $this->assertNotSame('active', $account->status);
        $this->assertSame('failed', $account->reconciliation_status);
        $this->assertSame($externalId, $account->external_reference);
        Http::assertSentCount(3);
    }

    public function test_legacy_combined_verification_timestamp_cannot_make_wallet_eligible(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => 'legacy-customer',
            'external_account_id' => 'legacy-wallet',
            'status' => 'active',
            'provider_status' => 'clear',
            'provider_ids_verified_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        app(NiumProviderAccountStateService::class)->assertEligible($user);
    }

    public function test_get_customer_identifier_conflict_is_quarantined_and_financially_ineligible(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $account = $this->pendingAccount($user, $provider, withAuthenticatedIds: true);
        Http::fake(['*' => Http::response([
            ...$this->authoritativeCustomer($account, [], 'clear'),
            'customerHashId' => 'conflicting-get-customer-id',
        ])]);

        $result = app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);

        $this->assertSame($account->external_customer_id, $result->external_customer_id);
        $this->assertSame('blocked', $result->status);
        $this->assertSame('quarantined', $result->reconciliation_status);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'provider_account.nium_security_conflict',
        ]);

        $this->expectException(RuntimeException::class);
        app(NiumProviderAccountStateService::class)->assertEligible($user);
    }

    public function test_duplicate_external_id_with_exact_complete_customer_recovers_to_active(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $externalId = null;
        $listCalls = 0;

        Http::fake(function (Request $request) use (&$externalId, &$listCalls) {
            if ($request->method() === 'POST') {
                $externalId = $request->data()['externalId'];

                return Http::response(['errors' => [['code' => 'duplicate_external_id']]], 409);
            }

            $listCalls++;

            return $listCalls === 1
                ? Http::response(['customers' => []])
                : Http::response(['customers' => [[
                    'externalId' => $externalId,
                    'customerHashId' => 'recovered-complete-customer',
                    'status' => 'clear',
                    'subStatus' => '',
                    'wallets' => [['walletHashId' => 'recovered-complete-wallet']],
                ]]]);
        });

        $account = app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);

        $this->assertSame('active', $account->status);
        $this->assertNotNull($account->customer_id_verified_at);
        $this->assertNotNull($account->wallet_id_verified_at);
        $this->assertSame($externalId, $account->external_reference);
    }

    public function test_repeated_onboarding_uses_one_account_external_id_and_one_create_request(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $createCount = 0;
        $externalId = null;

        Http::fake(function (Request $request) use (&$createCount, &$externalId) {
            if ($request->method() === 'POST') {
                $createCount++;
                $externalId = $request->data()['externalId'];

                return Http::response([
                    ...$this->fixture('customer-v5-create-response.json'),
                    'externalId' => $externalId,
                ]);
            }

            if (str_contains($request->url(), '/customers')) {
                return Http::response(['customers' => []]);
            }

            return Http::response([
                ...$this->fixture('customer-v5-create-response.json'),
                'externalId' => $externalId,
            ]);
        });

        $service = app(NiumCustomerOnboardingService::class);
        $first = $service->syncUser($provider, $user);
        $second = $service->syncUser($provider, $user);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($externalId, $second->external_reference);
        $this->assertSame(1, $createCount);
        $this->assertDatabaseCount('user_provider_accounts', 1);
    }

    public function test_missing_file_id_uploads_once_and_waits_without_creating_customer_or_leaking_file_data(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $document = $this->individualDocument($user);
        $rawBytes = 'safe-individual-file-bytes';
        $storagePath = '/remote/private/storage-path';
        $document->update(['metadata' => ['existing_key' => 'existing-value']]);
        $transactionLevel = DB::transactionLevel();

        Http::fake(function (Request $request) use ($storagePath, $transactionLevel) {
            $this->assertSame($transactionLevel, DB::transactionLevel());

            if (str_contains($request->url(), 'document-storage-sandbox.nium.test')) {
                return Http::response([
                    'id' => self::SECOND_FILE_ID,
                    'state' => 'PROCESSING',
                    'storagePath' => $storagePath,
                ], 201);
            }

            return Http::response(['customers' => []]);
        });

        $response = $this->withToken($this->issueTokenFor($user))
            ->postJson("/api/user/users/{$user->id}/provider-accounts/nium/link");

        $response->assertOk()
            ->assertJsonPath('onboarding.next_action', 'wait_for_document_processing')
            ->assertJsonPath('onboarding.metadata.pending_document_count', 1);

        $metadata = (array) $document->fresh()->metadata;
        $serializedLogs = ApiRequestLog::query()->get()
            ->map(fn (ApiRequestLog $log): string => json_encode($log->toArray(), JSON_THROW_ON_ERROR))
            ->implode('\n');

        $this->assertSame('existing-value', $metadata['existing_key']);
        $this->assertSame(self::SECOND_FILE_ID, $metadata['nium_file_id']);
        $this->assertSame('PROCESSING', $metadata['nium_file_state']);
        $this->assertNotEmpty($metadata['nium_uploaded_at']);
        $this->assertArrayNotHasKey('storagePath', $metadata);
        $this->assertStringNotContainsString($storagePath, $serializedLogs);
        $this->assertStringNotContainsString('sandbox-api-key', $serializedLogs);
        $this->assertStringNotContainsString($rawBytes, $serializedLogs);
        $this->assertStringNotContainsString((string) $document->file_path, $serializedLogs);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), 'document-storage-sandbox.nium.test'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), 'gateway.nium.test'));
    }

    public function test_processing_retry_only_fetches_details_then_available_retry_creates_customer_once(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $document = $this->individualDocument($user);
        $metadata = (array) $document->metadata;
        $metadata['nium_file_state'] = 'PROCESSING';
        unset($metadata['nium_available_at']);
        $document->update(['metadata' => $metadata]);
        $providerAccount = $this->pendingAccount($user, $provider);
        $providerAccount->update([
            'metadata' => [
                'integration_status' => 'custom_waiting_baseline',
                'unrelated_key' => 'preserve-me',
            ],
        ]);
        $externalReference = $providerAccount->external_reference;
        $accountMetadata = $providerAccount->metadata;
        $fileDetailCalls = 0;
        $customerCreateCalls = 0;
        $customerApiCalls = 0;
        $transactionLevel = DB::transactionLevel();

        Http::fake(function (Request $request) use (&$fileDetailCalls, &$customerCreateCalls, &$customerApiCalls, $transactionLevel) {
            $this->assertSame($transactionLevel, DB::transactionLevel());

            if (str_contains($request->url(), 'document-storage-sandbox.nium.test')) {
                $fileDetailCalls++;

                return Http::response([
                    'id' => self::INDIVIDUAL_FILE_ID,
                    'state' => $fileDetailCalls === 1 ? 'PROCESSING' : 'AVAILABLE',
                ]);
            }

            $customerApiCalls++;

            if ($request->method() === 'POST') {
                $customerCreateCalls++;

                return Http::response([
                    ...$this->fixture('customer-v5-create-response.json'),
                    'externalId' => $request->data()['externalId'],
                ]);
            }

            return Http::response(['customers' => []]);
        });

        $service = app(NiumCustomerOnboardingService::class);
        $first = $service->beginOnboarding($provider, $user);
        $providerAccount->refresh();

        $this->assertSame($externalReference, $providerAccount->external_reference);
        $this->assertSame($accountMetadata, $providerAccount->metadata);
        $this->assertNotSame('failed', $providerAccount->status);
        $this->assertNotSame('failed', $providerAccount->reconciliation_status);
        $this->assertSame('verified', $user->fresh()->kyc_status);
        $this->assertSame(
            'approved',
            $user->kycProviderSubmissions()->where('provider_id', $provider->id)->value('status'),
        );
        $this->assertSame(1, $fileDetailCalls);
        $this->assertSame(0, $customerCreateCalls);
        $this->assertSame(0, $customerApiCalls);

        $second = $service->beginOnboarding($provider, $user);

        $this->assertSame('wait_for_document_processing', $first->nextAction);
        $this->assertSame('provider_onboarding_completed', $second->nextAction);
        $this->assertSame(2, $fileDetailCalls);
        $this->assertSame(1, $customerCreateCalls);
        $this->assertSame(2, $customerApiCalls);
        $this->assertSame('AVAILABLE', $document->fresh()->metadata['nium_file_state']);
        $this->assertNotEmpty($document->fresh()->metadata['nium_available_at']);
        $this->assertSame(
            '2026-07-23T05:00:00.000000Z',
            $document->fresh()->metadata['nium_uploaded_at'],
        );
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), 'document-storage-sandbox.nium.test'));
    }

    public function test_mixed_documents_keep_available_id_and_upload_only_missing_document(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $available = $this->individualDocument($user);
        $availableMetadata = $available->metadata;
        $path = "kyc/{$user->id}/proof-of-address.pdf";
        Storage::disk('kyc_private')->put($path, 'safe-proof-of-address-bytes');
        $missing = $user->kycProfile->documents()->create([
            'type' => 'proof_of_address',
            'status' => 'approved',
            'file_url' => 'https://files.example.test/proof-of-address.pdf',
            'storage_disk' => 'kyc_private',
            'file_path' => $path,
            'original_name' => 'proof-of-address.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 27,
            'metadata' => ['review_source' => 'internal'],
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'document-storage-sandbox.nium.test')) {
                return Http::response([
                    'id' => self::SECOND_FILE_ID,
                    'state' => 'PROCESSING',
                ], 201);
            }

            return Http::response(['customers' => []]);
        });

        $result = app(NiumCustomerOnboardingService::class)->beginOnboarding($provider, $user);

        $this->assertSame('wait_for_document_processing', $result->nextAction);
        $this->assertSame($availableMetadata, $available->fresh()->metadata);
        $this->assertSame(self::SECOND_FILE_ID, $missing->fresh()->metadata['nium_file_id']);
        $this->assertSame('internal', $missing->fresh()->metadata['review_source']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), 'document-storage-sandbox.nium.test'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), 'gateway.nium.test'));
    }

    public function test_file_api_error_mismatching_file_id_and_invalid_state_block_customer_creation(): void
    {
        foreach (['api_error', 'id_mismatch', 'invalid_state'] as $scenario) {
            $provider = $this->provider();
            $user = $this->approvedIndividual($provider);
            $document = $this->individualDocument($user);
            $metadata = (array) $document->metadata;
            $metadata['nium_file_state'] = $scenario === 'invalid_state' ? 'FAILED' : 'PROCESSING';
            $document->update(['metadata' => $metadata]);

            Http::fake(function (Request $request) use ($scenario) {
                if (str_contains($request->url(), 'document-storage-sandbox.nium.test')) {
                    return $scenario === 'api_error'
                        ? Http::response(['message' => 'provider-private-error'], 503)
                        : Http::response([
                            'id' => self::SECOND_FILE_ID,
                            'state' => 'AVAILABLE',
                        ]);
                }

                return Http::response(['customers' => []]);
            });

            try {
                app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
                $this->fail("Expected {$scenario} to block Nium customer creation.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }

            $this->assertSame(
                $scenario === 'invalid_state' ? 'FAILED' : 'PROCESSING',
                $document->fresh()->metadata['nium_file_state'],
            );
            Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
                && str_contains($request->url(), 'gateway.nium.test'));
        }
    }

    public function test_missing_local_file_blocks_customer_creation_before_file_upload(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $document = $this->individualDocument($user);
        $document->update(['metadata' => ['existing_key' => 'existing-value']]);
        Storage::disk('kyc_private')->delete((string) $document->file_path);

        Http::fake(fn () => Http::response(['customers' => []]));

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
            $this->fail('Expected a missing local file to block Nium customer creation.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'The KYC document file is not available for Nium upload.',
                $exception->getMessage(),
            );
        }

        Http::assertNothingSent();
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
        $this->assertArrayNotHasKey('nium_file_id', (array) $document->fresh()->metadata);
    }

    public function test_customer_creation_retry_after_timeout_reuses_available_document_without_file_api_calls(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $customerCreateCalls = 0;

        Http::fake(function (Request $request) use (&$customerCreateCalls) {
            if ($request->method() === 'GET') {
                return Http::response(['customers' => []]);
            }

            $customerCreateCalls++;

            if ($customerCreateCalls === 1) {
                return Http::response(['message' => 'gateway timeout'], 504);
            }

            return Http::response([
                ...$this->fixture('customer-v5-create-response.json'),
                'externalId' => $request->data()['externalId'],
            ]);
        });

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
            $this->fail('Expected the first customer creation attempt to time out.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $account = app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);

        $this->assertSame('active', $account->status);
        $this->assertSame(2, $customerCreateCalls);
        $this->assertSame(self::INDIVIDUAL_FILE_ID, $this->individualDocument($user)->metadata['nium_file_id']);
        Http::assertNotSent(fn (Request $request): bool => str_contains(
            $request->url(),
            'document-storage-sandbox.nium.test',
        ));
    }

    public function test_corporate_payload_uses_available_business_applicant_and_stakeholder_file_ids(): void
    {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $createResponse = $this->fixture('customer-v5-create-response.json');

        Http::fake(function (Request $request) use ($createResponse) {
            if ($request->method() === 'GET') {
                return Http::response(['customers' => []]);
            }

            return Http::response([
                ...$createResponse,
                'externalId' => $request->data()['externalId'],
            ]);
        });

        $account = app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);

        $this->assertSame('active', $account->status);
        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), 'gateway.nium.test')) {
                return false;
            }

            $payload = $request->data();

            return $payload['type'] === 'corporate'
                && $payload['documents'][0]['fileIds'] === [self::BUSINESS_FILE_ID]
                && $payload['applicant']['documents'][0]['fileIds'] === [self::APPLICANT_FILE_ID]
                && $payload['stakeholders']['individual'][0]['documents'][0]['fileIds'] === [self::STAKEHOLDER_FILE_ID];
        });
        Http::assertNotSent(fn (Request $request): bool => str_contains(
            $request->url(),
            'document-storage-sandbox.nium.test',
        ));
    }

    public function test_shared_document_selection_ignores_rejected_superseded_and_older_duplicate_documents(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $profile = $user->kycProfile()->firstOrFail();
        $superseded = $profile->documents()->create([
            'type' => 'utility_bill',
            'status' => 'approved',
            'file_url' => 'https://files.example.test/superseded-utility.pdf',
            'storage_disk' => 'kyc_private',
            'file_path' => 'missing/superseded-utility.pdf',
            'original_name' => 'superseded-utility.pdf',
            'mime_type' => 'application/pdf',
            'document_number' => 'UTILITY-OLD',
        ]);
        $profile->documents()->create([
            'type' => 'proof_of_address',
            'status' => 'approved',
            'file_url' => 'https://files.example.test/current-address.pdf',
            'document_number' => 'ADDRESS-CURRENT',
            'metadata' => [
                ...$this->availableFileMetadata(self::REPLACEMENT_FILE_ID),
                'previous_document_id' => $superseded->id,
            ],
        ]);
        $profile->documents()->create([
            'type' => 'bank_statement',
            'status' => 'rejected',
            'file_url' => 'https://files.example.test/rejected-optional.pdf',
            'storage_disk' => 'kyc_private',
            'file_path' => 'missing/rejected-optional.pdf',
            'original_name' => 'rejected-optional.pdf',
            'mime_type' => 'application/pdf',
        ]);
        $profile->documents()->create([
            'type' => 'tax_document',
            'status' => 'approved',
            'file_url' => 'https://files.example.test/older-duplicate.pdf',
            'storage_disk' => 'kyc_private',
            'file_path' => 'missing/older-duplicate.pdf',
            'original_name' => 'older-duplicate.pdf',
            'mime_type' => 'application/pdf',
            'side' => 'front',
            'document_number' => 'TAX-DUPLICATE',
        ]);
        $profile->documents()->create([
            'type' => 'tax_document',
            'status' => 'approved',
            'file_url' => 'https://files.example.test/current-duplicate.pdf',
            'side' => 'front',
            'document_number' => 'TAX-DUPLICATE',
            'metadata' => $this->availableFileMetadata(self::DUPLICATE_WINNER_FILE_ID),
        ]);
        $createResponse = $this->fixture('customer-v5-create-response.json');
        $payloadFileIds = [];

        Http::fake(function (Request $request) use ($createResponse, &$payloadFileIds) {
            if ($request->method() === 'GET') {
                return Http::response(['customers' => []]);
            }

            $payloadFileIds = collect($request->data()['documents'])
                ->flatMap(fn (array $document): array => $document['fileIds'])
                ->all();

            return Http::response([
                ...$createResponse,
                'externalId' => $request->data()['externalId'],
            ]);
        });

        $account = app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);

        $this->assertSame('active', $account->status);
        $this->assertSame([
            self::INDIVIDUAL_FILE_ID,
            self::REPLACEMENT_FILE_ID,
            self::DUPLICATE_WINNER_FILE_ID,
        ], $payloadFileIds);
        Http::assertNotSent(fn (Request $request): bool => str_contains(
            $request->url(),
            'document-storage-sandbox.nium.test',
        ));
    }

    public function test_lock_contention_returns_waiting_without_http_or_provider_account_side_effects(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $document = $this->individualDocument($user);
        $metadata = (array) $document->metadata;
        $metadata['nium_file_state'] = 'PROCESSING';
        $document->update(['metadata' => $metadata]);
        $account = $this->pendingAccount($user, $provider);
        $account->update([
            'metadata' => [
                'integration_status' => 'preserve-this-status',
                'unrelated_key' => 'preserve-this-value',
            ],
        ]);
        $externalReference = $account->external_reference;
        $accountMetadata = $account->metadata;
        $lock = Cache::store('array')->lock(
            'provider:nium:kyc-document:'.$document->id,
            60,
        );
        $this->assertTrue($lock->get());
        Http::fake();

        try {
            $result = app(NiumCustomerOnboardingService::class)->beginOnboarding($provider, $user);
        } finally {
            $lock->release();
        }

        $account->refresh();
        $this->assertSame('wait_for_document_processing', $result->nextAction);
        $this->assertSame(1, $result->metadata['pending_document_count']);
        $this->assertSame($externalReference, $account->external_reference);
        $this->assertSame($accountMetadata, $account->metadata);
        $this->assertNotSame('failed', $account->status);
        $this->assertNotSame('failed', $account->reconciliation_status);
        Http::assertNothingSent();
    }

    public function test_file_api_exception_releases_document_lock_for_next_retry(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $document = $this->individualDocument($user);
        $metadata = (array) $document->metadata;
        $metadata['nium_file_state'] = 'PROCESSING';
        $document->update(['metadata' => $metadata]);
        $calls = 0;

        Http::fake(function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? Http::response(['message' => 'temporary file API failure'], 503)
                : Http::response([
                    'id' => self::INDIVIDUAL_FILE_ID,
                    'state' => 'PROCESSING',
                ]);
        });

        try {
            app(NiumCustomerDocumentPreparationService::class)->prepare($user);
            $this->fail('Expected the first file details request to fail.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $retry = app(NiumCustomerDocumentPreparationService::class)->prepare($user);

        $this->assertFalse($retry['ready']);
        $this->assertSame(1, $retry['pending_document_count']);
        $this->assertSame(2, $calls);
    }

    public function test_unsupported_cache_lock_store_fails_closed_without_http(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        config()->set('cache.default', 'unsupported_lock_store');
        config()->set('cache.stores.unsupported_lock_store', ['driver' => 'null']);
        Http::fake();

        try {
            app(NiumCustomerDocumentPreparationService::class)->prepare($user);
            $this->fail('Expected an unsupported cache lock store to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Nium document preparation requires a configured cache store with atomic lock support.',
                $exception->getMessage(),
            );
        }

        Http::assertNothingSent();
    }

    public function test_multiple_missing_documents_are_all_attempted_and_successful_upload_is_not_rolled_back(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $first = $this->individualDocument($user);
        $first->update(['metadata' => ['existing_key' => 'first-document']]);
        $secondPath = "kyc/{$user->id}/second-document.pdf";
        Storage::disk('kyc_private')->put($secondPath, 'safe-second-document-bytes');
        $second = $user->kycProfile->documents()->create([
            'type' => 'proof_of_address',
            'status' => 'approved',
            'file_url' => 'https://files.example.test/second-document.pdf',
            'storage_disk' => 'kyc_private',
            'file_path' => $secondPath,
            'original_name' => 'second-document.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 26,
            'metadata' => ['existing_key' => 'second-document'],
        ]);
        $uploads = [];
        $detailCalls = 0;

        Http::fake(function (Request $request) use (&$uploads, &$detailCalls) {
            if ($request->method() === 'GET') {
                $detailCalls++;

                return Http::response([
                    'id' => self::MULTI_DOCUMENT_FILE_ID,
                    'state' => 'AVAILABLE',
                ]);
            }

            $filePart = collect($request->data())->firstWhere('name', 'file');
            $fileName = (string) ($filePart['filename'] ?? '');
            $uploads[$fileName] = ($uploads[$fileName] ?? 0) + 1;

            if ($fileName === 'passport-front.jpg' && $uploads[$fileName] === 1) {
                return Http::response(['message' => 'temporary first document failure'], 503);
            }

            if ($fileName === 'passport-front.jpg') {
                return Http::response([
                    'id' => self::SECOND_FILE_ID,
                    'state' => 'PROCESSING',
                ], 201);
            }

            return Http::response([
                'id' => self::MULTI_DOCUMENT_FILE_ID,
                'state' => 'PROCESSING',
            ], 201);
        });

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
            $this->fail('Expected the first document upload to fail.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $secondAfterFailure = (array) $second->fresh()->metadata;
        $this->assertArrayNotHasKey('nium_file_id', (array) $first->fresh()->metadata);
        $this->assertSame(self::MULTI_DOCUMENT_FILE_ID, $secondAfterFailure['nium_file_id']);
        $this->assertSame('PROCESSING', $secondAfterFailure['nium_file_state']);
        $this->assertSame('second-document', $secondAfterFailure['existing_key']);

        $retry = app(NiumCustomerOnboardingService::class)->beginOnboarding($provider, $user);

        $this->assertSame('wait_for_document_processing', $retry->nextAction);
        $this->assertSame(2, $uploads['passport-front.jpg']);
        $this->assertSame(1, $uploads['second-document.pdf']);
        $this->assertSame(1, $detailCalls);
        $this->assertSame(
            $secondAfterFailure['nium_uploaded_at'],
            $second->fresh()->metadata['nium_uploaded_at'],
        );
        $this->assertSame(self::SECOND_FILE_ID, $first->fresh()->metadata['nium_file_id']);
        Http::assertNotSent(fn (Request $request): bool => str_contains(
            $request->url(),
            'gateway.nium.test',
        ));
    }

    public function test_all_documented_restrictive_states_remove_eligibility_immediately(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $account = $this->pendingAccount($user, $provider, withAuthenticatedIds: true);
        $service = app(NiumProviderAccountStateService::class);

        foreach ([
            ['clear', 'awaiting_kyc', 'under_review'],
            ['clear', 'rfi_requested', 'under_review'],
            ['clear', 'under_review', 'under_review'],
            ['suspended', null, 'blocked'],
            ['closed', null, 'blocked'],
            ['terminated', null, 'blocked'],
        ] as [$status, $subStatus, $expected]) {
            $account = $service->applyAuthenticatedState($account, [
                'customerHashId' => $account->external_customer_id,
                'walletHashId' => $account->external_account_id,
                'status' => $status,
                'subStatus' => $subStatus,
            ], 'test_authoritative_get');
            $this->assertSame($expected, $account->status, "Unexpected internal state for {$status}/{$subStatus}");

            try {
                $service->assertEligible($user);
                $this->fail("{$status}/{$subStatus} must not be eligible.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }

        $account = $service->applyAuthenticatedState($account, [
            'customerHashId' => $account->external_customer_id,
            'walletHashId' => $account->external_account_id,
            'status' => 'clear',
            'subStatus' => '',
        ], 'test_authoritative_get');
        $this->assertSame('active', $account->status);
        $this->assertSame($account->id, $service->assertEligible($user)->id);
    }

    public function test_stale_restrictive_notification_cannot_override_current_clear_get_customer_state(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $account = $this->pendingAccount($user, $provider, withAuthenticatedIds: true);
        $account->update(['status' => 'active', 'provider_status' => 'clear']);
        $rfiNotification = $this->fixture('customer-status-rfi-webhook.json');
        $rfiNotification['externalId'] = $account->external_reference;
        $current = $this->authoritativeCustomer($account, $rfiNotification, 'clear');
        $current['subStatus'] = '';
        Http::fake(['*' => Http::response($current)]);

        $this->withHeaders([
            'x-partner-key' => 'verified-partner-key',
            'x-request-id' => 'out-of-order-rfi-001',
        ])->postJson('/api/webhooks/providers/nium', $rfiNotification)->assertOk();

        $account->refresh();
        $this->assertSame('active', $account->status);
        $this->assertNull($account->provider_sub_status);
        $this->assertSame('reconciled', $account->reconciliation_status);
    }

    public function test_suspended_and_terminated_cannot_be_reactivated_by_delayed_clear_notifications(): void
    {
        $authoritative = [];
        Http::fake(function () use (&$authoritative) {
            return Http::response($authoritative);
        });

        foreach (['suspended', 'terminated'] as $providerStatus) {
            $provider = $this->provider();
            $user = $this->approvedIndividual($provider);
            $account = $this->pendingAccount($user, $provider, withAuthenticatedIds: true);
            $account->update(['status' => 'active', 'provider_status' => 'clear']);
            $restrictive = $this->fixture('customer-status-clear-webhook.json');
            $restrictive['externalId'] = $account->external_reference;
            $restrictive['status'] = $providerStatus;
            $authoritative = $this->authoritativeCustomer($account, $restrictive, $providerStatus);

            $this->withHeaders([
                'x-partner-key' => 'verified-partner-key',
                'x-request-id' => "{$providerStatus}-current-001",
            ])->postJson('/api/webhooks/providers/nium', $restrictive)->assertOk();
            $this->assertSame('blocked', $account->fresh()->status);

            $delayedClear = $this->fixture('customer-status-clear-webhook.json');
            $delayedClear['externalId'] = $account->external_reference;
            $this->withHeaders([
                'x-partner-key' => 'verified-partner-key',
                'x-request-id' => "{$providerStatus}-delayed-clear-001",
            ])->postJson('/api/webhooks/providers/nium', $delayedClear)->assertOk();
            $this->assertSame('blocked', $account->fresh()->status);
        }
    }

    public function test_wallet_and_both_identifier_conflicts_are_quarantined_with_request_id_evidence(): void
    {
        foreach (['wallet', 'both'] as $conflictType) {
            $provider = $this->provider();
            $user = $this->approvedIndividual($provider);
            $account = $this->pendingAccount($user, $provider, withAuthenticatedIds: true);
            $payload = $this->fixture('customer-status-clear-webhook.json');
            $payload['externalId'] = $account->external_reference;
            $payload['walletHashIds'] = ['different-wallet-id'];

            if ($conflictType === 'both') {
                $payload['customerHashId'] = 'different-customer-id';
            }

            $requestId = "{$conflictType}-conflict-001";
            $this->withHeaders([
                'x-partner-key' => 'verified-partner-key',
                'x-request-id' => $requestId,
            ])->postJson('/api/webhooks/providers/nium', $payload)->assertUnprocessable();

            $account->refresh();
            $this->assertSame('blocked', $account->status);
            $this->assertSame('235a58d9-9a83-4e98-9711-a5fa1dcfecda', $account->external_account_id);
            $audit = AuditLog::query()
                ->where('user_id', $user->id)
                ->where('action', 'provider_account.nium_security_conflict')
                ->latest('id')
                ->firstOrFail();
            $this->assertSame($requestId, $audit->new_data['request_id']);
        }
    }

    public function test_duplicate_recovery_rejects_mismatching_external_id_and_transient_lookup_keeps_reference(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            $calls++;

            if ($request->method() === 'POST') {
                return Http::response(['errors' => [['code' => 'customer_exists']]], 409);
            }

            return $calls === 1
                ? Http::response(['customers' => []])
                : Http::response(['customers' => [[
                    'externalId' => 'another-users-external-id',
                    'customerHashId' => 'must-not-link',
                ]]]);
        });

        $account = app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
        $this->assertNull($account->external_customer_id);
        $this->assertSame('failed', $account->reconciliation_status);

        $secondUser = $this->approvedIndividual($provider);
        Http::fake(['*' => Http::response(['errors' => [['code' => 'temporary_unavailable']]], 503)]);

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $secondUser);
            $this->fail('Transient lookup failure must be surfaced.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $failed = $secondUser->providerAccounts()->firstOrFail();
        $this->assertNotNull($failed->external_reference);
        $this->assertSame('failed', $failed->reconciliation_status);
    }

    public function test_customer_and_wallet_are_verified_independently_across_responses(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $account = $this->pendingAccount($user, $provider);
        $service = app(NiumProviderAccountStateService::class);

        $account = $service->applyAuthenticatedState($account, [
            'customerHashId' => 'separately-verified-customer',
            'status' => 'clear',
            'subStatus' => '',
        ], 'verified_customer_response');
        $this->assertNotNull($account->customer_id_verified_at);
        $this->assertNull($account->wallet_id_verified_at);
        $this->assertNotSame('active', $account->status);

        $account = $service->applyAuthenticatedState($account, [
            'walletHashId' => 'separately-verified-wallet',
            'status' => 'clear',
            'subStatus' => '',
        ], 'verified_wallet_response');
        $this->assertNotNull($account->wallet_id_verified_at);
        $this->assertSame('active', $account->status);

        $legacyUser = $this->approvedIndividual($provider);
        $legacy = $this->pendingAccount($legacyUser, $provider);
        $legacy->update(['external_customer_id' => 'unverified-legacy-customer']);
        $legacy = $service->applyAuthenticatedState($legacy, [
            'walletHashId' => 'verified-wallet-only',
            'status' => 'clear',
            'subStatus' => '',
        ], 'verified_wallet_response');
        $this->assertNull($legacy->customer_id_verified_at);
        $this->assertNotNull($legacy->wallet_id_verified_at);
        $this->assertNotSame('active', $legacy->status);
    }

    public function test_lifecycle_request_id_edge_cases_use_only_the_header_key(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $account = $this->pendingAccount($user, $provider);
        $payload = $this->fixture('customer-status-rfi-webhook.json');
        $payload['externalId'] = $account->external_reference;
        $payload['eventId'] = 'same-payload-event';

        $this->withHeaders([
            'x-partner-key' => 'verified-partner-key',
            'x-request-id' => '   ',
        ])->postJson('/api/webhooks/providers/nium', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('webhook_events', 0);

        Http::fake(['*' => Http::response($this->authoritativeCustomer($account, $payload))]);

        foreach (['header-key-one', 'header-key-two'] as $requestId) {
            $this->withHeaders([
                'x-partner-key' => 'verified-partner-key',
                'x-request-id' => $requestId,
            ])->postJson('/api/webhooks/providers/nium', $payload)->assertOk();
        }

        $this->assertDatabaseHas('webhook_events', ['event_id' => 'header-key-one']);
        $this->assertDatabaseHas('webhook_events', ['event_id' => 'header-key-two']);
    }

    private function provider(): IntegrationProvider
    {
        return IntegrationProvider::query()->firstOrCreate([
            'code' => 'nium',
        ], [
            'name' => 'Nium',
            'status' => 'active',
        ]);
    }

    private function approvedIndividual(IntegrationProvider $provider): User
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create([
            'full_name' => 'John Doe',
            'email' => "john.doe.{$suffix}@example.com",
            'phone' => '+44'.random_int(1000000000, 9999999999),
            'kyc_status' => 'verified',
        ]);
        $user->profile()->create([
            'user_type' => 'individual',
            'country_code' => 'GB',
        ]);
        $kycProfile = $user->kycProfile()->create([
            'status' => 'approved',
            'applicant_type' => 'individual',
            'legal_name' => 'John Doe',
            'date_of_birth' => '1985-05-15',
            'nationality_country_code' => 'GB',
            'residence_country_code' => 'GB',
            'address_line1' => '456 Corporate Ave',
            'address_line2' => 'Suite 8',
            'city' => 'Cardiff',
            'state' => 'Wales',
            'postal_code' => 'CF24',
            'country_code' => 'GB',
            'metadata' => [
                'nium_region' => 'UK',
                'nium_kyc_type' => 'minimum',
                'mobile_country_code' => '44',
                'verification_consent' => true,
                'nium_v5_fields' => [
                    'annualIncome' => 'gb005',
                    'expectedAccountUsage' => [
                        'credit' => [
                            'averageTransactionValue' => 'tc001',
                            'monthlyTransactionVolume' => 'eu008',
                            'monthlyTransactions' => 'tc001',
                        ],
                        'intendedUses' => ['iu002', 'iu003'],
                    ],
                    'incomeSourceType' => 'salaried_employee',
                    'natureOfBusiness' => [
                        'industryCodes' => ['is112'],
                    ],
                ],
            ],
        ]);
        $kycProfile->documents()->create([
            'type' => 'passport_front',
            'status' => 'approved',
            'file_url' => 'https://files.example.test/passport.jpg',
            'storage_disk' => 'kyc_private',
            'file_path' => "kyc/{$user->id}/passport-front.jpg",
            'original_name' => 'passport-front.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 27,
            'document_number' => 'PR123456',
            'issuing_country_code' => 'GB',
            'expires_at' => '2030-12-12',
            'metadata' => [
                'existing_key' => 'existing-value',
                'nium_file_id' => self::INDIVIDUAL_FILE_ID,
                'nium_file_state' => 'AVAILABLE',
                'nium_uploaded_at' => '2026-07-23T05:00:00.000000Z',
                'nium_available_at' => '2026-07-23T05:01:00.000000Z',
            ],
        ]);
        Storage::disk('kyc_private')->put(
            "kyc/{$user->id}/passport-front.jpg",
            'safe-individual-file-bytes',
        );
        $user->kycProviderSubmissions()->create([
            'kyc_profile_id' => $kycProfile->id,
            'provider_id' => $provider->id,
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        return $user;
    }

    private function approvedCorporate(IntegrationProvider $provider): User
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create([
            'full_name' => 'Alice Applicant',
            'email' => "alice.applicant.{$suffix}@example.com",
            'phone' => '+6591234567',
            'kyc_status' => 'verified',
        ]);
        $user->profile()->create([
            'user_type' => 'business',
            'country_code' => 'SG',
        ]);
        $kycProfile = $user->kycProfile()->create([
            'status' => 'approved',
            'applicant_type' => 'business',
            'legal_name' => 'Acme Holdings Limited',
            'business_name' => 'Acme Holdings Limited',
            'business_registration_number' => 'ACME-2026-001',
            'registered_country_code' => 'SG',
            'address_line1' => '1 Corporate Avenue',
            'city' => 'Singapore',
            'postal_code' => '018989',
            'country_code' => 'SG',
            'metadata' => [
                'nium_region' => 'SG',
                'nium_kyc_type' => 'minimum',
                'registered_date' => '2020-01-15',
                'nium_business_type' => 'private_company',
            ],
        ]);
        $applicant = $kycProfile->relatedPersons()->create([
            'relationship_type' => 'applicant',
            'status' => 'approved',
            'legal_name' => 'Alice Applicant',
            'date_of_birth' => '1988-04-12',
            'nationality_country_code' => 'SG',
            'residence_country_code' => 'SG',
            'address_line1' => '2 Applicant Street',
            'city' => 'Singapore',
            'postal_code' => '018990',
            'country_code' => 'SG',
            'metadata' => [
                'email' => $user->email,
                'phone' => $user->phone,
            ],
        ]);
        $stakeholder = $kycProfile->relatedPersons()->create([
            'relationship_type' => 'beneficial_owner',
            'status' => 'approved',
            'legal_name' => 'Uma Owner',
            'date_of_birth' => '1980-02-20',
            'nationality_country_code' => 'SG',
            'residence_country_code' => 'SG',
            'ownership_percentage' => 60,
            'address_line1' => '3 Owner Road',
            'city' => 'Singapore',
            'postal_code' => '018991',
            'country_code' => 'SG',
            'metadata' => [
                'email' => "uma.owner.{$suffix}@example.com",
                'phone' => '+6598765432',
            ],
        ]);

        $kycProfile->documents()->create([
            'type' => 'business_registration',
            'status' => 'approved',
            'file_url' => 'https://files.example.test/business-registration.pdf',
            'document_number' => 'ACME-2026-001',
            'issuing_country_code' => 'SG',
            'metadata' => $this->availableFileMetadata(self::BUSINESS_FILE_ID),
        ]);
        $applicant->documents()->create([
            'kyc_profile_id' => $kycProfile->id,
            'type' => 'passport_front',
            'status' => 'approved',
            'file_url' => 'https://files.example.test/applicant-passport.jpg',
            'document_number' => 'APPLICANT-PASSPORT',
            'issuing_country_code' => 'SG',
            'metadata' => $this->availableFileMetadata(self::APPLICANT_FILE_ID),
        ]);
        $stakeholder->documents()->create([
            'kyc_profile_id' => $kycProfile->id,
            'type' => 'passport_front',
            'status' => 'approved',
            'file_url' => 'https://files.example.test/stakeholder-passport.jpg',
            'document_number' => 'STAKEHOLDER-PASSPORT',
            'issuing_country_code' => 'SG',
            'metadata' => $this->availableFileMetadata(self::STAKEHOLDER_FILE_ID),
        ]);
        $user->kycProviderSubmissions()->create([
            'kyc_profile_id' => $kycProfile->id,
            'provider_id' => $provider->id,
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        return $user;
    }

    private function individualDocument(User $user): KycDocument
    {
        return $user->kycProfile()->firstOrFail()->documents()->firstOrFail();
    }

    private function availableFileMetadata(string $fileId): array
    {
        return [
            'nium_file_id' => $fileId,
            'nium_file_state' => 'AVAILABLE',
            'nium_uploaded_at' => '2026-07-23T05:00:00.000000Z',
            'nium_available_at' => '2026-07-23T05:01:00.000000Z',
        ];
    }

    private function pendingAccount(
        User $user,
        IntegrationProvider $provider,
        bool $withAuthenticatedIds = false,
    ): UserProviderAccount {
        return $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => $withAuthenticatedIds ? '2ba22977-eb3d-4db0-aa3f-7d8459ed6420' : null,
            'external_account_id' => $withAuthenticatedIds ? '235a58d9-9a83-4e98-9711-a5fa1dcfecda' : null,
            'external_reference' => (string) Str::uuid(),
            'status' => 'submitted',
            'provider_status' => 'pending',
            'customer_id_verified_at' => $withAuthenticatedIds ? now() : null,
            'wallet_id_verified_at' => $withAuthenticatedIds ? now() : null,
            'provider_ids_verified_at' => $withAuthenticatedIds ? now() : null,
        ]);
    }

    private function authoritativeCustomer(
        UserProviderAccount $account,
        array $notification,
        ?string $status = null,
    ): array {
        $walletHashId = $notification['walletHashId']
            ?? ($notification['walletHashIds'][0] ?? null)
            ?? $account->external_account_id
            ?? '235a58d9-9a83-4e98-9711-a5fa1dcfecda';

        return [
            'customerHashId' => $notification['customerHashId'] ?? $account->external_customer_id,
            'externalId' => $account->external_reference,
            'status' => $status ?? ($notification['status'] ?? 'pending'),
            'subStatus' => $notification['subStatus'] ?? '',
            'wallets' => [['walletHashId' => $walletHashId]],
        ];
    }

    private function issueTokenFor(User $user): string
    {
        $plainToken = Str::random(80);

        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'test-token',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        return $plainToken;
    }

    private function fixture(string $name): array
    {
        return json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/nium/'.$name)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
