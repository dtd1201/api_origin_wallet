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
use App\Services\Nium\NiumCustomerPayloadFactory;
use App\Services\Nium\NiumEvidencePersistenceException;
use App\Services\Nium\NiumProviderAccountStateService;
use App\Services\Nium\NiumProviderRequestException;
use App\Services\Nium\NiumRegionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
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

    private const DEVICE_SESSION_ID = '99999999-9999-4999-8999-999999999999';

    private const BUSINESS_DOCUMENT_BYTES = 'synthetic-business-registration-bytes';

    private const APPLICANT_DOCUMENT_BYTES = 'synthetic-applicant-passport-bytes';

    private const STAKEHOLDER_DOCUMENT_BYTES = 'synthetic-stakeholder-passport-bytes';

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
        config()->set('services.nium.sg_corporate_client_schema', [
            'require_bank_account_details' => true,
            'require_device_details' => true,
            'require_routing_codes' => true,
        ]);
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

    public function test_fixture_v4_style_applicant_email_is_rejected_before_any_nium_http(): void
    {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $applicant = $user->kycProfile->relatedPersons->firstWhere('relationship_type', 'applicant');
        $metadata = (array) $applicant->metadata;
        $metadata['email'] = str_repeat('a', 77).'@example.invalid';
        $applicant->update(['metadata' => $metadata]);
        $this->mock(NiumCustomerDocumentPreparationService::class)->shouldNotReceive('prepare');
        Http::fake(fn () => throw new RuntimeException('Unexpected HTTP request.'));
        $user->unsetRelation('kycProfile');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid Nium V5 email at nium_v5_fields.applicant.email.');

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_valid_v5_emails_are_trimmed_only_and_otherwise_unchanged(): void
    {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $applicant = $user->kycProfile->relatedPersons->firstWhere('relationship_type', 'applicant');
        $stakeholder = $user->kycProfile->relatedPersons->firstWhere('relationship_type', 'beneficial_owner');
        $applicantMetadata = (array) $applicant->metadata;
        $applicantMetadata['email'] = '  Applicant.Tag+Case@controlled.example  ';
        $applicant->update(['metadata' => $applicantMetadata]);
        $stakeholderMetadata = (array) $stakeholder->metadata;
        $stakeholderMetadata['email'] = 'Stakeholder.Tag+Case@controlled.example';
        $stakeholder->update(['metadata' => $stakeholderMetadata]);
        $user->unsetRelation('kycProfile');

        $payload = app(NiumCustomerPayloadFactory::class)->build($user, (string) Str::uuid());

        $this->assertSame('Applicant.Tag+Case@controlled.example', $payload['applicant']['email']);
        $this->assertSame('Stakeholder.Tag+Case@controlled.example', $payload['stakeholders']['individual'][0]['email']);
        Http::assertNothingSent();
    }

    public function test_invalid_individual_email_reports_customer_path_before_any_nium_http(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $user->update(['email' => 'customer@example.invalid']);
        $this->mock(NiumCustomerDocumentPreparationService::class)->shouldNotReceive('prepare');
        Http::fake(fn () => throw new RuntimeException('Unexpected HTTP request.'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid Nium V5 email at nium_v5_fields.customer.email.');

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_invalid_stakeholder_email_reports_stakeholder_path_before_any_nium_http(): void
    {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $stakeholder = $user->kycProfile->relatedPersons->firstWhere('relationship_type', 'beneficial_owner');
        $metadata = (array) $stakeholder->metadata;
        $metadata['email'] = 'stakeholder@sub.invalid';
        $stakeholder->update(['metadata' => $metadata]);
        $this->mock(NiumCustomerDocumentPreparationService::class)->shouldNotReceive('prepare');
        Http::fake(fn () => throw new RuntimeException('Unexpected HTTP request.'));
        $user->unsetRelation('kycProfile');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid Nium V5 email at nium_v5_fields.stakeholders.individual[*].email.');

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
        } finally {
            Http::assertNothingSent();
        }
    }

    #[DataProvider('missingTradeNameProvider')]
    public function test_sg_corporate_full_requires_approved_trade_name_before_any_nium_http(
        bool $remove,
        mixed $value,
    ): void {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $profile = $user->kycProfile()->firstOrFail();
        $metadata = (array) $profile->metadata;

        if ($remove) {
            unset($metadata['nium_v5_fields']['tradeName']);
        } else {
            $metadata['nium_v5_fields']['tradeName'] = $value;
        }

        $profile->update(['metadata' => $metadata]);
        $this->mock(NiumCustomerDocumentPreparationService::class)->shouldNotReceive('prepare');
        Http::fake(fn () => throw new RuntimeException('Unexpected HTTP request.'));
        $user->unsetRelation('kycProfile');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Nium SG corporate full KYC requires approved KYC metadata field '
            .'nium_v5_fields.tradeName as a non-empty string.',
        );

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
        } finally {
            Http::assertNothingSent();
        }
    }

    public static function missingTradeNameProvider(): array
    {
        return [
            'missing' => [true, null],
            'null' => [false, null],
            'blank' => [false, '   '],
        ];
    }

    public function test_sg_corporate_full_emits_only_explicit_trimmed_trade_name(): void
    {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $profile = $user->kycProfile()->firstOrFail();
        $metadata = (array) $profile->metadata;
        $metadata['nium_v5_fields']['tradeName'] = '  Acme Approved Trade  ';
        $profile->update([
            'business_name' => 'Different Legal Business Name',
            'metadata' => $metadata,
        ]);
        $user->unsetRelation('kycProfile');

        $payload = app(NiumCustomerPayloadFactory::class)->build($user, (string) Str::uuid());

        $this->assertSame('Different Legal Business Name', $payload['businessName']);
        $this->assertSame('Acme Approved Trade', $payload['tradeName']);
        Http::assertNothingSent();
    }

    public function test_approved_v5_website_survives_corporate_payload_merge(): void
    {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $profile = $user->kycProfile()->firstOrFail();
        $metadata = (array) $profile->metadata;
        unset($metadata['business_website']);
        $metadata['nium_v5_fields']['website'] = 'https://approved.example';
        $profile->update(['metadata' => $metadata]);
        $user->unsetRelation('kycProfile');

        $payload = app(NiumCustomerPayloadFactory::class)->build($user, (string) Str::uuid());

        $this->assertSame('https://approved.example', $payload['website']);
        Http::assertNothingSent();
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
        $entityReferenceFingerprint = substr(
            hash('sha256', 'b80612ea-1822-4788-aa3d-f0b4585f6015'),
            0,
            16,
        );
        $this->assertSame(
            'submitted',
            $account->metadata['nium_entity_kyc_states']['ref_'.$entityReferenceFingerprint]['kyc_status'],
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
            'safe-individual-file-bytes', "kyc/{$user->id}/passport-front.jpg", 'storagePath', 'is112',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }

    public function test_nium_provider_exception_contains_only_safe_code_and_schema_path(): void
    {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $rawDescription = 'RAW NIUM description for Alice Applicant at '
            .$user->email.' phone '.$user->phone.' DOB 1988-04-12 address 2 Applicant Street '
            .'file '.self::APPLICANT_FILE_ID.' account 1234567890 Bearer sandbox-api-key.';

        Http::fake(function (Request $request) use ($rawDescription) {
            if ($request->method() === 'GET') {
                return Http::response(['customers' => []]);
            }

            return Http::response([
                'errors' => [[
                    'errorCode' => 'invalid_input',
                    'field' => 'applicant.documents[0].fileIds',
                    'path' => 'applicant.documents[0].fileIds',
                    'description' => $rawDescription,
                ]],
            ], 400);
        });

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
            $this->fail('Expected the fake Nium rejection to throw a safe provider exception.');
        } catch (NiumProviderRequestException $exception) {
            $this->assertSame('Nium V5 customer creation failed.', $exception->getMessage());
            $this->assertSame('invalid_input', $exception->providerCode);
            $this->assertSame('applicant.documents[0].fileIds', $exception->providerField);
            $this->assertSame('applicant.documents[0].fileIds', $exception->providerPath);

            $serializedException = json_encode([
                'message' => $exception->getMessage(),
                'code' => $exception->providerCode,
                'field' => $exception->providerField,
                'path' => $exception->providerPath,
            ], JSON_THROW_ON_ERROR);

            foreach ($this->rawNiumErrorSecrets($user, $rawDescription) as $sensitiveValue) {
                $this->assertStringNotContainsString($sensitiveValue, $serializedException);
            }
        }
    }

    public function test_link_route_never_returns_or_persists_raw_nium_error_description(): void
    {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $rawDescription = 'RAW NIUM description for Alice Applicant at '
            .$user->email.' phone '.$user->phone.' DOB 1988-04-12 address 2 Applicant Street '
            .'file '.self::APPLICANT_FILE_ID.' account 1234567890 Bearer sandbox-api-key.';

        Http::fake(function (Request $request) use ($rawDescription) {
            if ($request->method() === 'GET') {
                return Http::response(['customers' => []]);
            }

            return Http::response([
                'errors' => [[
                    'errorCode' => 'invalid_input',
                    'field' => 'applicant.documents[0].fileIds',
                    'path' => 'applicant.documents[0].fileIds',
                    'description' => $rawDescription,
                ]],
            ], 400);
        });

        $response = $this->withToken($this->issueTokenFor($user))
            ->postJson("/api/user/users/{$user->id}/provider-accounts/nium/link");

        $response->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Nium V5 customer creation failed.',
                'code' => 'invalid_input',
                'field' => 'applicant.documents[0].fileIds',
                'path' => 'applicant.documents[0].fileIds',
            ]);

        $externalReference = (string) $user->providerAccounts()->firstOrFail()->external_reference;
        $serializedResponse = $response->getContent();
        $serializedLogs = json_encode([
            'api_request_logs' => ApiRequestLog::query()->get()->toArray(),
            'audit_logs' => AuditLog::query()->get()->toArray(),
        ], JSON_THROW_ON_ERROR);

        foreach ([
            ...$this->rawNiumErrorSecrets($user, $rawDescription),
        ] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $serializedResponse);
            $this->assertStringNotContainsString($sensitiveValue, $serializedLogs);
        }

        $this->assertStringNotContainsString($externalReference, $serializedResponse);
        $this->assertStringContainsString($externalReference, $serializedLogs);
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

    public function test_customer_creation_gateway_timeout_blocks_replay_and_reuses_available_document_state(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $customerCreateCalls = 0;

        Http::fake(function (Request $request) use (&$customerCreateCalls) {
            if ($request->method() === 'GET') {
                return Http::response(['customers' => []]);
            }

            $customerCreateCalls++;

            return Http::response(['message' => 'gateway timeout'], 504);
        });

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
            $this->fail('Expected the first customer creation attempt to time out.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $account = app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);

        $this->assertSame('submitted', $account->status);
        $this->assertSame(NiumProviderAccountStateService::CUSTOMER_CREATE_UNKNOWN, $account->reconciliation_error);
        $this->assertSame(1, $customerCreateCalls);
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
        $addressDiagnostic = [];

        Http::fake(function (Request $request) use ($createResponse, &$addressDiagnostic) {
            if ($request->method() === 'GET') {
                return Http::response(['customers' => []]);
            }

            $payload = $request->data();
            $addressDiagnostic = collect([
                'addresses.registeredAddress',
                'addresses.businessAddress',
                'applicant.address',
                'stakeholders.individual.0.address',
            ])->map(function (string $path) use ($payload): array {
                $address = data_get($payload, $path);

                return [
                    'payload_path' => $path,
                    'country_code' => is_array($address) ? ($address['country'] ?? null) : null,
                    'state_present' => is_array($address) && array_key_exists('state', $address),
                    'state_type' => is_array($address) && array_key_exists('state', $address)
                        ? get_debug_type($address['state'])
                        : 'missing',
                    'postal_code_present' => is_array($address) && array_key_exists('postcode', $address),
                    'city_present' => is_array($address) && array_key_exists('city', $address),
                ];
            })->all();

            return Http::response([
                ...$createResponse,
                'externalId' => $payload['externalId'],
            ]);
        });

        $account = app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);

        $this->assertSame('active', $account->status);
        $this->assertSame([
            [
                'payload_path' => 'addresses.registeredAddress',
                'country_code' => 'SG',
                'state_present' => true,
                'state_type' => 'string',
                'postal_code_present' => true,
                'city_present' => true,
            ],
            [
                'payload_path' => 'addresses.businessAddress',
                'country_code' => 'SG',
                'state_present' => true,
                'state_type' => 'string',
                'postal_code_present' => true,
                'city_present' => true,
            ],
            [
                'payload_path' => 'applicant.address',
                'country_code' => 'SG',
                'state_present' => true,
                'state_type' => 'string',
                'postal_code_present' => true,
                'city_present' => true,
            ],
            [
                'payload_path' => 'stakeholders.individual.0.address',
                'country_code' => 'SG',
                'state_present' => true,
                'state_type' => 'string',
                'postal_code_present' => true,
                'city_present' => true,
            ],
        ], $addressDiagnostic);
        $this->assertSame('SG', $user->kycProfile()->firstOrFail()->registered_country_code);
        $this->assertNotContains(
            true,
            collect($addressDiagnostic)
                ->pluck('country_code')
                ->map(fn ($country): bool => in_array($country, ['US', 'AU', 'NZ'], true))
                ->all(),
        );
        $requestLogs = ApiRequestLog::query()->get();
        $this->assertCount(2, $requestLogs);

        foreach ($requestLogs as $requestLog) {
            $this->assertSame(
                [],
                array_diff(
                    array_keys($requestLog->request_body),
                    ['external_id_fingerprint', 'customer_type', 'region'],
                ),
            );
        }

        $serializedRequestLogs = $requestLogs
            ->map(fn (ApiRequestLog $requestLog): string => json_encode(
                $requestLog->request_body,
                JSON_THROW_ON_ERROR,
            ))
            ->implode("\n");
        $this->assertStringNotContainsString('registeredAddress', $serializedRequestLogs);
        $this->assertStringNotContainsString('businessAddress', $serializedRequestLogs);
        $this->assertStringNotContainsString(
            'isBusinessAddressSameAsRegisteredAddress',
            $serializedRequestLogs,
        );
        $this->assertStringNotContainsString('nium_v5_fields', $serializedRequestLogs);
        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), 'gateway.nium.test')) {
                return false;
            }

            $payload = $request->data();
            $allFileIds = [
                ...$payload['documents'][0]['fileIds'],
                ...$payload['applicant']['documents'][0]['fileIds'],
                ...$payload['stakeholders']['individual'][0]['documents'][0]['fileIds'],
            ];
            $serializedPayload = json_encode($payload, JSON_THROW_ON_ERROR);

            return $payload['type'] === 'corporate'
                && $payload['region'] === 'SG'
                && $payload['kycType'] === 'full'
                && $payload['applicantDeclaration'] === true
                && $payload['applicantDeclarationTimeStamp'] === '2026-07-23 05:00:00'
                && $payload['isMultiLayeredCompany'] === false
                && is_array($payload['natureOfBusiness'])
                && $payload['natureOfBusiness']['operatingCountries'] === ['SG', 'HK']
                && is_array($payload['natureOfBusiness']['industryCodes'])
                && $payload['natureOfBusiness']['industryCodes'] === ['IS144']
                && is_array($payload['expectedAccountUsage'])
                && is_array($payload['expectedAccountUsage']['credit'])
                && is_string($payload['expectedAccountUsage']['credit']['averageTransactionValue'])
                && is_string($payload['expectedAccountUsage']['credit']['monthlyTransactionVolume'])
                && is_string($payload['expectedAccountUsage']['credit']['monthlyTransactions'])
                && $payload['expectedAccountUsage']['credit']['topTransactionCountries'] === ['SG', 'HK']
                && is_array($payload['expectedAccountUsage']['debit'])
                && is_string($payload['expectedAccountUsage']['debit']['averageTransactionValue'])
                && is_string($payload['expectedAccountUsage']['debit']['monthlyTransactionVolume'])
                && is_string($payload['expectedAccountUsage']['debit']['monthlyTransactions'])
                && $payload['expectedAccountUsage']['debit']['topTransactionCountries'] === ['IN', 'SG']
                && is_array($payload['expectedAccountUsage']['intendedUses'])
                && $payload['expectedAccountUsage']['intendedUses'] === ['IU003']
                && is_array($payload['sizeOfBusiness'])
                && is_string($payload['sizeOfBusiness']['annualTurnover'])
                && $payload['sizeOfBusiness']['annualTurnover'] === 'SG011'
                && $payload['sizeOfBusiness']['totalEmployees'] === 'EM009'
                && $payload['applicant']['positions'] === [['title' => 'DIRECTOR']]
                && $payload['stakeholders']['individual'][0]['positions'] === [['title' => 'UBO']]
                && $payload['bankAccountDetails'] === [
                    'accountName' => 'Acme Holdings Limited',
                    'accountNumber' => '1234567890',
                    'bankCountry' => 'SG',
                    'currency' => 'SGD',
                    'bankAccountType' => 'current',
                    'bankName' => 'DBS Bank',
                    'routingCodes' => [
                        [
                            'type' => 'SWIFT',
                            'value' => 'DBSSSGSG',
                        ],
                    ],
                ]
                && $payload['deviceDetails'] === [
                    'ipCountryCode' => 'SG',
                    'deviceInfo' => 'Synthetic test browser',
                    'ipAddress' => '192.0.2.10',
                    'sessionId' => self::DEVICE_SESSION_ID,
                ]
                && $payload['documents'][0]['fileIds'] === [self::BUSINESS_FILE_ID]
                && $payload['applicant']['documents'][0]['fileIds'] === [self::APPLICANT_FILE_ID]
                && $payload['stakeholders']['individual'][0]['documents'][0]['fileIds'] === [self::STAKEHOLDER_FILE_ID]
                && count($allFileIds) === 3
                && count(array_unique($allFileIds)) === 3
                && collect($allFileIds)->every(
                    fn (string $fileId): bool => Str::isUuid($fileId),
                )
                && ! str_contains($serializedPayload, 'kyc/corporate/')
                && ! str_contains($serializedPayload, 'storagePath')
                && ! str_contains($serializedPayload, 'file_path')
                && ! str_contains($serializedPayload, self::BUSINESS_DOCUMENT_BYTES)
                && ! str_contains($serializedPayload, self::APPLICANT_DOCUMENT_BYTES)
                && ! str_contains($serializedPayload, self::STAKEHOLDER_DOCUMENT_BYTES)
                && ! str_contains($serializedPayload, base64_encode(self::BUSINESS_DOCUMENT_BYTES))
                && ! str_contains($serializedPayload, base64_encode(self::APPLICANT_DOCUMENT_BYTES))
                && ! str_contains($serializedPayload, base64_encode(self::STAKEHOLDER_DOCUMENT_BYTES));
        });
        Http::assertNotSent(fn (Request $request): bool => str_contains(
            $request->url(),
            'document-storage-sandbox.nium.test',
        ));
    }

    public function test_sg_corporate_true_address_relationship_uses_registered_source_for_both_addresses(): void
    {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        Http::fake();

        $payload = app(NiumCustomerPayloadFactory::class)->build($user, (string) Str::uuid());
        $addresses = $payload['addresses'];

        $this->assertSame([
            'isBusinessAddressSameAsRegisteredAddress',
            'registeredAddress',
            'businessAddress',
        ], array_keys($addresses));
        $this->assertTrue($addresses['isBusinessAddressSameAsRegisteredAddress']);
        $this->assertIsBool($addresses['isBusinessAddressSameAsRegisteredAddress']);
        $this->assertSame([
            'addressLine1' => '1 Corporate Avenue',
            'city' => 'Singapore',
            'state' => 'SG-01',
            'postcode' => '018989',
            'country' => 'SG',
        ], $addresses['registeredAddress']);
        $this->assertSame($addresses['registeredAddress'], $addresses['businessAddress']);
        Http::assertNothingSent();
    }

    public function test_sg_corporate_false_address_relationship_uses_only_distinct_metadata_source(): void
    {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $this->setSgCorporateAddresses($user, false, [
            'address_line1' => '  88 Trading Road  ',
            'address_line2' => '',
            'city' => ' Singapore ',
            'state' => ' SG-04 ',
            'postal_code' => ' 049321 ',
            'country_code' => ' sg ',
        ]);
        Http::fake();

        $payload = app(NiumCustomerPayloadFactory::class)->build($user, (string) Str::uuid());
        $addresses = $payload['addresses'];

        $this->assertFalse($addresses['isBusinessAddressSameAsRegisteredAddress']);
        $this->assertIsBool($addresses['isBusinessAddressSameAsRegisteredAddress']);
        $this->assertSame('1 Corporate Avenue', $addresses['registeredAddress']['addressLine1']);
        $this->assertSame([
            'addressLine1' => '88 Trading Road',
            'city' => 'Singapore',
            'state' => 'SG-04',
            'postcode' => '049321',
            'country' => 'SG',
        ], $addresses['businessAddress']);
        $this->assertFalse(
            json_decode(json_encode($addresses, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)['isBusinessAddressSameAsRegisteredAddress'],
        );
        Http::assertNothingSent();
    }

    public function test_sg_corporate_identical_addresses_do_not_override_explicit_false(): void
    {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $this->setSgCorporateAddresses($user, false, [
            'address_line1' => '1 Corporate Avenue',
            'city' => 'Singapore',
            'state' => 'SG-01',
            'postal_code' => '018989',
            'country_code' => 'SG',
        ]);
        Http::fake();

        $addresses = app(NiumCustomerPayloadFactory::class)
            ->build($user, (string) Str::uuid())['addresses'];

        $this->assertFalse($addresses['isBusinessAddressSameAsRegisteredAddress']);
        $this->assertSame($addresses['registeredAddress'], $addresses['businessAddress']);
        Http::assertNothingSent();
    }

    #[DataProvider('niumRegionResolutionProvider')]
    public function test_nium_region_resolution_uses_the_shared_region_contract(
        bool $includeExplicitRegion,
        ?string $explicitRegion,
        string $registeredCountry,
        string $expectedRegion,
    ): void {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $profile = $user->kycProfile()->firstOrFail();
        $metadata = (array) $profile->metadata;

        if ($includeExplicitRegion) {
            $metadata['nium_region'] = $explicitRegion;
        } else {
            unset($metadata['nium_region']);
        }

        $profile->update([
            'registered_country_code' => $registeredCountry,
            'residence_country_code' => null,
            'country_code' => $registeredCountry,
            'metadata' => $metadata,
        ]);
        $user->unsetRelation('kycProfile');
        Http::fake(fn () => throw new RuntimeException('Unexpected HTTP request.'));

        $payload = app(NiumCustomerPayloadFactory::class)
            ->build($user, (string) Str::uuid());

        $this->assertSame($expectedRegion, $payload['region']);
        Http::assertNothingSent();
    }

    public static function niumRegionResolutionProvider(): array
    {
        return [
            'explicit SG' => [true, 'SG', 'US', 'SG'],
            'explicit lowercase sg' => [true, 'sg', 'US', 'SG'],
            'explicit trimmed lowercase sg' => [true, ' sg ', 'US', 'SG'],
            'explicit US' => [true, 'US', 'US', 'US'],
            'explicit EU' => [true, 'EU', 'DE', 'EU'],
            'explicit region overrides unsupported-country SG fallback' => [true, 'US', 'ZZ', 'US'],
            'registered SG fallback' => [false, null, 'SG', 'SG'],
            'registered GB fallback' => [false, null, 'GB', 'UK'],
            'registered NL fallback' => [false, null, 'NL', 'NL'],
            'listed European country fallback' => [false, null, 'DE', 'EU'],
            'directly supported non-SG country fallback' => [false, null, 'US', 'US'],
            'explicit null uses registered-country fallback' => [true, null, 'GB', 'UK'],
        ];
    }

    public function test_hk_corporate_full_payload_is_country_consistent_and_uses_controlled_fixture_files(): void
    {
        config()->set('services.nium.regulatory_region', 'HK');
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $profile = $user->kycProfile()->firstOrFail();
        $metadata = (array) $profile->metadata;
        $metadata['nium_region'] = 'HK';
        $metadata['nium_v5_fields']['addresses'] = [
            'isBusinessAddressSameAsRegisteredAddress' => true,
        ];
        $metadata['nium_v5_fields']['website'] = 'https://business.example.test';
        $metadata['nium_v5_fields']['natureOfBusiness']['operatingCountries'] = ['HK'];
        $metadata['nium_v5_fields']['expectedAccountUsage']['credit']['topTransactionCountries'] = ['HK'];
        $metadata['nium_v5_fields']['expectedAccountUsage']['debit']['topTransactionCountries'] = ['HK'];
        $metadata['nium_v5_fields']['bankAccountDetails']['bankCountry'] = 'HK';
        $metadata['nium_v5_fields']['bankAccountDetails']['currency'] = 'HKD';
        $metadata['nium_v5_fields']['deviceDetails']['ipCountryCode'] = 'HK';
        $profile->update([
            'registered_country_code' => 'HK',
            'address_line1' => '1 Synthetic Harbour Road',
            'address_line2' => null,
            'city' => 'Hong Kong',
            'state' => 'Hong Kong',
            'postal_code' => null,
            'country_code' => 'HK',
            'metadata' => $metadata,
        ]);
        $user->profile()->update(['country_code' => 'HK']);
        $profile->documents()
            ->where('type', 'business_registration')
            ->update(['issuing_country_code' => 'HK']);
        $profile->documents()->create([
            'type' => 'nar1',
            'status' => 'approved',
            'file_url' => 'private://fixture/nar1',
            'document_number' => 'NAR1-SYNTHETIC',
            'issuing_country_code' => 'HK',
            'metadata' => $this->availableFileMetadata('40000000-0000-4000-8000-000000000024'),
        ]);
        $applicant = $profile->relatedPersons()->where('relationship_type', 'applicant')->firstOrFail();
        $stakeholder = $profile->relatedPersons()->where('relationship_type', 'beneficial_owner')->firstOrFail();
        $historical = collect([
            $profile->documents()->create(['type' => 'business_registration', 'status' => 'superseded', 'file_url' => 'private://historical/company']),
            $applicant->documents()->create(['kyc_profile_id' => $profile->id, 'type' => 'passport_front', 'status' => 'superseded', 'file_url' => 'private://historical/applicant']),
            $stakeholder->documents()->create(['kyc_profile_id' => $profile->id, 'type' => 'passport_front', 'status' => 'superseded', 'file_url' => 'private://historical/stakeholder']),
        ]);
        $user->unsetRelation('kycProfile');
        Http::fake(fn () => throw new RuntimeException('Unexpected HTTP request.'));

        $payload = app(NiumCustomerPayloadFactory::class)->build(
            $user,
            (string) Str::uuid(),
        );
        $fileIds = collect($payload['documents'])->flatMap(fn (array $document): array => $document['fileIds'])
            ->merge([
                ...$payload['applicant']['documents'][0]['fileIds'],
                ...$payload['stakeholders']['individual'][0]['documents'][0]['fileIds'],
            ]);

        $this->assertSame('corporate', $payload['type']);
        $this->assertSame('HK', $payload['region']);
        $this->assertSame('full', $payload['kycType']);
        $this->assertSame('HK', $payload['registeredCountry']);
        $this->assertSame('HK', $payload['addresses']['registeredAddress']['country']);
        $this->assertTrue($payload['addresses']['isBusinessAddressSameAsRegisteredAddress']);
        $this->assertArrayNotHasKey('businessAddress', $payload['addresses']);
        $this->assertSame(['HK'], $payload['natureOfBusiness']['operatingCountries']);
        $this->assertSame('HK', $payload['bankAccountDetails']['bankCountry']);
        $this->assertSame('HKD', $payload['bankAccountDetails']['currency']);
        $this->assertSame('HK', $payload['deviceDetails']['ipCountryCode']);
        $this->assertSame(self::DEVICE_SESSION_ID, $payload['deviceDetails']['sessionId']);
        $this->assertSame(4, $fileIds->count());
        $this->assertSame(4, $fileIds->unique()->count());
        $this->assertTrue($fileIds->every(fn (string $fileId): bool => Str::isUuid($fileId)));
        $this->assertTrue($historical->every(fn (KycDocument $document): bool => $document->fresh()->status === 'superseded'));
        $this->assertSame('SG', $payload['applicant']['nationality']);
        $this->assertSame('SG', $payload['stakeholders']['individual'][0]['nationality']);
        Http::assertNothingSent();
    }

    public function test_configured_regulatory_region_mismatch_fails_before_documents_or_http(): void
    {
        config()->set('services.nium.regulatory_region', 'HK');
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $profile = $user->kycProfile()->firstOrFail();
        $metadata = (array) $profile->metadata;
        unset($metadata['nium_region']);
        $profile->update(['metadata' => $metadata]);
        $user->unsetRelation('kycProfile');
        $this->mock(NiumCustomerDocumentPreparationService::class)->shouldNotReceive('prepare');
        Http::fake(fn () => throw new RuntimeException('Unexpected HTTP request.'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(NiumRegionResolver::REGION_MISMATCH);

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_unsupported_country_without_explicit_or_configured_region_fails_locally(): void
    {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $profile = $user->kycProfile()->firstOrFail();
        $metadata = (array) $profile->metadata;
        unset($metadata['nium_region']);
        $profile->update([
            'registered_country_code' => 'ZZ',
            'country_code' => 'ZZ',
            'metadata' => $metadata,
        ]);
        $this->mock(NiumCustomerDocumentPreparationService::class)->shouldNotReceive('prepare');
        Http::fake(fn () => throw new RuntimeException('Unexpected HTTP request.'));
        $user->unsetRelation('kycProfile');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(NiumRegionResolver::INVALID_REGION);

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
        } finally {
            Http::assertNothingSent();
        }
    }

    #[DataProvider('invalidNiumRegionProvider')]
    public function test_invalid_nium_region_fails_before_dml_documents_or_http(
        mixed $invalidRegion,
        array $forbiddenErrorFragments,
    ): void {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $profile = $user->kycProfile()->firstOrFail();
        $metadata = (array) $profile->metadata;
        $metadata['nium_region'] = $invalidRegion;
        $profile->update(['metadata' => $metadata]);
        $submission = $user->kycProviderSubmissions()->firstOrFail();
        $submissionFingerprint = $this->modelFingerprint($submission);
        $providerAccountCount = UserProviderAccount::query()->count();
        $auditCount = AuditLog::query()->count();
        $apiRequestLogCount = ApiRequestLog::query()->count();
        $storageFingerprint = $this->storageFingerprint();
        $observedOperations = [];
        $this->mock(NiumCustomerDocumentPreparationService::class)
            ->shouldNotReceive('prepare');
        Http::fake(fn () => throw new RuntimeException('Unexpected HTTP request.'));
        $user->unsetRelation('kycProfile');
        $this->activateDmlGuard($observedOperations);

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
            $this->fail('Expected invalid Nium region validation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('nium_region_invalid', $exception->getMessage());
            $this->assertStringNotContainsString('metadata.', $exception->getMessage());

            foreach ($forbiddenErrorFragments as $fragment) {
                $this->assertStringNotContainsString($fragment, $exception->getMessage());
            }
        }

        $operationsDuringInvocation = array_values(array_unique($observedOperations));
        $this->assertSame(['SELECT'], $operationsDuringInvocation);
        $this->assertSame($providerAccountCount, UserProviderAccount::query()->count());
        $this->assertSame($auditCount, AuditLog::query()->count());
        $this->assertSame($apiRequestLogCount, ApiRequestLog::query()->count());
        $this->assertSame($submissionFingerprint, $this->modelFingerprint($submission->fresh()));
        $this->assertSame($storageFingerprint, $this->storageFingerprint());
        Http::assertNothingSent();
    }

    public static function invalidNiumRegionProvider(): array
    {
        return [
            'empty string' => ['', []],
            'whitespace string' => ['   ', []],
            'unsupported string' => ['synthetic_unknown_region', ['synthetic_unknown_region']],
            'empty array' => [[], []],
            'list array' => [['synthetic_list_region'], ['synthetic_list_region']],
            'associative array' => [
                ['region' => 'synthetic_associative_region'],
                ['synthetic_associative_region'],
            ],
            'decoded object equivalent' => [
                ['region' => ['value' => 'synthetic_object_region']],
                ['synthetic_object_region'],
            ],
        ];
    }

    #[DataProvider('emptyBusinessAddressForTrueProvider')]
    public function test_true_sg_corporate_relationship_accepts_only_approved_empty_business_address_shapes(
        bool $includeBusinessAddress,
        mixed $businessAddress,
    ): void {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $this->setSgCorporateAddresses($user, true, $businessAddress, $includeBusinessAddress);
        Http::fake(fn () => throw new RuntimeException('Unexpected HTTP request.'));

        $addresses = app(NiumCustomerPayloadFactory::class)
            ->build($user, (string) Str::uuid())['addresses'];

        $this->assertTrue($addresses['isBusinessAddressSameAsRegisteredAddress']);
        $this->assertSame($addresses['registeredAddress'], $addresses['businessAddress']);
        Http::assertNothingSent();
    }

    public static function emptyBusinessAddressForTrueProvider(): array
    {
        return [
            'absent' => [false, null],
            'null' => [true, null],
            'empty array' => [true, []],
            'null optional line two' => [true, ['address_line2' => null]],
            'empty optional line two' => [true, ['address_line2' => '']],
            'whitespace optional line two' => [true, ['address_line2' => '   ']],
        ];
    }

    #[DataProvider('conflictingBusinessAddressForTrueProvider')]
    public function test_true_sg_corporate_relationship_rejects_every_populated_or_malformed_business_address(
        mixed $businessAddress,
        array $forbiddenErrorFragments = [],
    ): void {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $this->setSgCorporateAddresses($user, true, $businessAddress);

        $this->assertSgCorporateAddressFailureHasNoSideEffects(
            $provider,
            $user,
            'sg_corporate_business_address_conflict',
            $forbiddenErrorFragments,
        );
    }

    public static function conflictingBusinessAddressForTrueProvider(): array
    {
        return [
            'non-empty required field' => [['city' => 'populated']],
            'non-empty optional line two' => [['address_line2' => 'populated']],
            'unknown key only' => [['unknown' => 'populated']],
            'numeric list' => [['populated']],
            'scalar' => ['populated'],
            'nested array value' => [['address_line2' => ['nested']]],
            'nested object value' => [['address_line2' => (object) ['nested' => true]]],
            'approved_empty_child_plus_unknown_child' => [
                [
                    'address_line2' => '',
                    'synthetic_unknown_child' => 'synthetic_nonempty_value',
                ],
                ['address_line2', 'synthetic_unknown_child', 'synthetic_nonempty_value'],
            ],
        ];
    }

    #[DataProvider('invalidBusinessAddressForFalseProvider')]
    public function test_false_sg_corporate_relationship_rejects_every_incomplete_or_malformed_business_address(
        bool $includeBusinessAddress,
        mixed $businessAddress,
    ): void {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $this->setSgCorporateAddresses($user, false, $businessAddress, $includeBusinessAddress);

        $this->assertSgCorporateAddressFailureHasNoSideEffects(
            $provider,
            $user,
            'sg_corporate_business_address_invalid',
        );
    }

    public static function invalidBusinessAddressForFalseProvider(): array
    {
        $valid = self::validBusinessAddressSource();
        $cases = [
            'absent' => [false, null],
            'null' => [true, null],
            'empty array' => [true, []],
            'numeric list' => [true, ['invalid']],
            'scalar' => [true, 'invalid'],
            'unknown key only' => [true, ['unknown' => 'invalid']],
            'valid fields plus unknown key' => [true, [...$valid, 'unknown' => 'invalid']],
            'malformed country code' => [true, [...$valid, 'country_code' => 'SGP']],
        ];

        foreach (['address_line1', 'city', 'state', 'postal_code', 'country_code'] as $field) {
            $missing = $valid;
            unset($missing[$field]);
            $cases["missing {$field}"] = [true, $missing];
            $cases["empty {$field}"] = [true, [...$valid, $field => '']];
            $cases["whitespace {$field}"] = [true, [...$valid, $field => '   ']];
        }

        foreach ([
            'address_line1',
            'address_line2',
            'city',
            'state',
            'postal_code',
            'country_code',
        ] as $field) {
            $cases["non-string {$field}"] = [true, [...$valid, $field => ['nested']]];
        }

        $cases['nested object component'] = [
            true,
            [...$valid, 'address_line2' => (object) ['nested' => true]],
        ];

        return $cases;
    }

    #[DataProvider('validBusinessAddressForFalseProvider')]
    public function test_false_sg_corporate_relationship_accepts_all_approved_optional_line_two_shapes(
        bool $includeAddressLine2,
        mixed $addressLine2,
    ): void {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $source = self::validBusinessAddressSource();

        if ($includeAddressLine2) {
            $source['address_line2'] = $addressLine2;
        }

        $this->setSgCorporateAddresses($user, false, $source);
        Http::fake(fn () => throw new RuntimeException('Unexpected HTTP request.'));

        $addresses = app(NiumCustomerPayloadFactory::class)
            ->build($user, (string) Str::uuid())['addresses'];

        $this->assertFalse($addresses['isBusinessAddressSameAsRegisteredAddress']);
        $this->assertSame('88 Trading Road', $addresses['businessAddress']['addressLine1']);

        if (is_string($addressLine2) && trim($addressLine2) !== '') {
            $this->assertSame(trim($addressLine2), $addresses['businessAddress']['addressLine2']);
        } else {
            $this->assertArrayNotHasKey('addressLine2', $addresses['businessAddress']);
        }

        Http::assertNothingSent();
    }

    public static function validBusinessAddressForFalseProvider(): array
    {
        return [
            'line two absent' => [false, null],
            'line two null' => [true, null],
            'line two empty' => [true, ''],
            'complete distinct address' => [true, 'Unit 2'],
        ];
    }

    public function test_invalid_sg_corporate_source_without_existing_account_executes_no_dml_or_side_effect(): void
    {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $profile = $user->kycProfile()->firstOrFail();
        $metadata = (array) $profile->metadata;
        $metadata['nium_v5_fields']['addresses']['isBusinessAddressSameAsRegisteredAddress'] = 'invalid';
        $profile->update(['metadata' => $metadata]);
        $submission = $user->kycProviderSubmissions()->firstOrFail();
        $submissionFingerprint = $this->modelFingerprint($submission);
        $auditCount = AuditLog::query()->count();
        $storageFingerprint = $this->storageFingerprint();
        $observedOperations = [];
        $this->mock(NiumCustomerDocumentPreparationService::class)
            ->shouldNotReceive('prepare');
        Http::fake(fn () => throw new RuntimeException('Unexpected HTTP request.'));
        $user->unsetRelation('kycProfile');
        $this->activateDmlGuard($observedOperations);

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
            $this->fail('Expected invalid SG corporate source validation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('sg_corporate_address_relationship_invalid', $exception->getMessage());
        }

        $operationsDuringInvocation = array_values(array_unique($observedOperations));
        $this->assertSame(['SELECT'], $operationsDuringInvocation);
        $this->assertDatabaseCount('user_provider_accounts', 0);
        $this->assertSame($auditCount, AuditLog::query()->count());
        $this->assertSame($submissionFingerprint, $this->modelFingerprint($submission->fresh()));
        $this->assertSame($storageFingerprint, $this->storageFingerprint());
        Http::assertNothingSent();
    }

    public function test_invalid_sg_corporate_source_leaves_existing_account_and_submission_byte_equivalent(): void
    {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $account = $this->pendingAccount($user, $provider)->fresh();
        $profile = $user->kycProfile()->firstOrFail();
        $metadata = (array) $profile->metadata;
        $metadata['nium_v5_fields']['addresses']['isBusinessAddressSameAsRegisteredAddress'] = 'invalid';
        $profile->update(['metadata' => $metadata]);
        $submission = $user->kycProviderSubmissions()->firstOrFail();
        $accountFingerprint = $this->modelFingerprint($account);
        $submissionFingerprint = $this->modelFingerprint($submission);
        $externalReferenceFingerprint = hash('sha256', (string) $account->external_reference);
        $accountStatus = $account->status;
        $customerIdPresent = filled($account->external_customer_id);
        $accountUpdatedAt = $account->getRawOriginal('updated_at');
        $auditCount = AuditLog::query()->count();
        $storageFingerprint = $this->storageFingerprint();
        $observedOperations = [];
        $this->mock(NiumCustomerDocumentPreparationService::class)
            ->shouldNotReceive('prepare');
        Http::fake(fn () => throw new RuntimeException('Unexpected HTTP request.'));
        $user->unsetRelation('kycProfile');
        $this->activateDmlGuard($observedOperations);

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
            $this->fail('Expected invalid SG corporate source validation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('sg_corporate_address_relationship_invalid', $exception->getMessage());
        }

        $operationsDuringInvocation = array_values(array_unique($observedOperations));
        $accountAfter = $account->fresh();
        $this->assertSame(['SELECT'], $operationsDuringInvocation);
        $this->assertSame($accountFingerprint, $this->modelFingerprint($accountAfter));
        $this->assertSame($accountStatus, $accountAfter->status);
        $this->assertSame(
            $externalReferenceFingerprint,
            hash('sha256', (string) $accountAfter->external_reference),
        );
        $this->assertSame($customerIdPresent, filled($accountAfter->external_customer_id));
        $this->assertSame($accountUpdatedAt, $accountAfter->getRawOriginal('updated_at'));
        $this->assertSame($submissionFingerprint, $this->modelFingerprint($submission->fresh()));
        $this->assertSame($auditCount, AuditLog::query()->count());
        $this->assertNull($accountAfter->reconciliation_error);
        $this->assertSame($storageFingerprint, $this->storageFingerprint());
        Http::assertNothingSent();
    }

    public function test_valid_sg_corporate_source_preserves_successful_provisioning_lookup_and_post(): void
    {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $lookupCalls = 0;
        $postCalls = 0;
        $createResponse = $this->fixture('customer-v5-create-response.json');

        Http::fake(function (Request $request) use (&$lookupCalls, &$postCalls, $createResponse) {
            if ($request->method() === 'GET') {
                $lookupCalls++;

                return Http::response(['customers' => []]);
            }

            $postCalls++;

            return Http::response([
                ...$createResponse,
                'externalId' => $request->data()['externalId'],
            ]);
        });

        $account = app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);

        $this->assertSame('active', $account->status);
        $this->assertSame(1, $lookupCalls);
        $this->assertSame(1, $postCalls);
        $this->assertDatabaseCount('user_provider_accounts', 1);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => str_contains(
            $request->url(),
            'document-storage-sandbox.nium.test',
        ));
    }

    public function test_missing_or_non_boolean_sg_corporate_address_relationship_fails_closed_before_http(): void
    {
        $invalidValues = [
            'missing' => new \stdClass,
            'string true' => 'true',
            'string false' => 'false',
            'string one' => '1',
            'string zero' => '0',
            'integer one' => 1,
            'integer zero' => 0,
            'null' => null,
            'array' => ['invalid'],
            'object' => (object) ['invalid' => true],
        ];

        foreach ($invalidValues as $label => $value) {
            $provider = $this->provider();
            $user = $this->approvedCorporate($provider);
            $profile = $user->kycProfile()->firstOrFail();
            $metadata = (array) $profile->metadata;

            if ($label === 'missing') {
                unset($metadata['nium_v5_fields']['addresses']['isBusinessAddressSameAsRegisteredAddress']);
            } else {
                $metadata['nium_v5_fields']['addresses']['isBusinessAddressSameAsRegisteredAddress'] = $value;
            }

            $profile->update(['metadata' => $metadata]);
            $user->unsetRelation('kycProfile');
            Http::fake();

            try {
                app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
                $this->fail("Expected {$label} relationship declaration to fail closed.");
            } catch (RuntimeException $exception) {
                $this->assertSame('sg_corporate_address_relationship_invalid', $exception->getMessage());
            }

            Http::assertNothingSent();
        }
    }

    public function test_false_sg_corporate_relationship_requires_complete_typed_business_address(): void
    {
        $valid = [
            'address_line1' => '88 Trading Road',
            'city' => 'Singapore',
            'state' => 'SG-04',
            'postal_code' => '049321',
            'country_code' => 'SG',
        ];
        $invalidSources = ['missing object' => null];

        foreach (array_keys($valid) as $field) {
            $source = $valid;
            unset($source[$field]);
            $invalidSources["missing {$field}"] = $source;
        }

        $invalidSources['malformed country'] = [...$valid, 'country_code' => 'SGP'];
        $invalidSources['non-string component'] = [...$valid, 'city' => 123];

        foreach ($invalidSources as $label => $source) {
            $provider = $this->provider();
            $user = $this->approvedCorporate($provider);
            $this->setSgCorporateAddresses($user, false, $source);
            Http::fake();

            try {
                app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
                $this->fail("Expected {$label} business address to fail closed.");
            } catch (RuntimeException $exception) {
                $this->assertSame('sg_corporate_business_address_invalid', $exception->getMessage());
            }

            Http::assertNothingSent();
        }
    }

    public function test_true_sg_corporate_relationship_rejects_separate_non_empty_business_address(): void
    {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $profile = $user->kycProfile()->firstOrFail();
        $fileIdsBefore = $profile->documents()
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (KycDocument $document): array => [
                $document->id => $document->metadata['nium_file_id'],
            ])
            ->all();
        $this->setSgCorporateAddresses($user, true, [
            'address_line1' => '88 Conflicting Road',
        ]);
        $auditCount = AuditLog::query()->count();
        Http::fake();

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
            $this->fail('Expected a separate business address to conflict with an explicit true declaration.');
        } catch (RuntimeException $exception) {
            $this->assertSame('sg_corporate_business_address_conflict', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('api_request_logs', 0);
        $this->assertDatabaseCount('user_provider_accounts', 0);
        $this->assertSame($auditCount, AuditLog::query()->count());
        $this->assertSame(
            $fileIdsBefore,
            $profile->documents()
                ->orderBy('id')
                ->get()
                ->mapWithKeys(fn (KycDocument $document): array => [
                    $document->id => $document->metadata['nium_file_id'],
                ])
                ->all(),
        );
    }

    public function test_both_address_sources_without_relationship_declaration_are_ambiguous(): void
    {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $profile = $user->kycProfile()->firstOrFail();
        $metadata = (array) $profile->metadata;
        $metadata['nium_v5_fields']['addresses'] = [
            'businessAddress' => [
                'address_line1' => '88 Trading Road',
                'city' => 'Singapore',
                'state' => 'SG-04',
                'postal_code' => '049321',
                'country_code' => 'SG',
            ],
        ];
        $profile->update(['metadata' => $metadata]);
        $user->unsetRelation('kycProfile');
        Http::fake();

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
            $this->fail('Expected ambiguous address sources to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('sg_corporate_address_relationship_invalid', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_sg_individual_and_non_sg_individual_ignore_corporate_address_relationship_contract(): void
    {
        $provider = $this->provider();

        foreach (['SG', 'UK'] as $region) {
            $user = $this->approvedIndividual($provider);
            $profile = $user->kycProfile()->firstOrFail();
            $metadata = (array) $profile->metadata;
            $metadata['nium_region'] = $region;
            $metadata['nium_v5_fields']['addresses'] = [
                'isBusinessAddressSameAsRegisteredAddress' => 'not-a-boolean',
            ];
            $profile->update(['metadata' => $metadata]);
            $user->unsetRelation('kycProfile');
            Http::fake();

            app(NiumCustomerPayloadFactory::class)->validateRequiredSourceData($user);

            Http::assertNothingSent();
        }
    }

    public function test_missing_sg_corporate_registered_and_business_address_state_fails_before_http(): void
    {
        $this->assertMissingSgCorporateAddressStateFailsBeforeHttp(
            'profile',
            'Nium SG corporate full KYC requires approved internal address field '
            .'addresses.registeredAddress.state as a string.',
        );
    }

    public function test_missing_sg_corporate_applicant_address_state_fails_before_http(): void
    {
        $this->assertMissingSgCorporateAddressStateFailsBeforeHttp(
            'applicant',
            'Nium SG corporate full KYC requires approved internal address field '
            .'applicant.address.state as a string.',
        );
    }

    public function test_missing_sg_corporate_stakeholder_address_state_fails_before_http(): void
    {
        $this->assertMissingSgCorporateAddressStateFailsBeforeHttp(
            'stakeholder',
            'Nium SG corporate full KYC requires approved internal address field '
            .'stakeholders.individual[*].address.state as a string.',
        );
    }

    public function test_sg_corporate_address_state_validation_does_not_affect_individual_source_data(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $user->kycProfile()->firstOrFail()->update(['state' => null]);
        Http::fake();

        app(NiumCustomerPayloadFactory::class)->validateRequiredSourceData($user);

        Http::assertNothingSent();
    }

    public function test_missing_sg_corporate_nature_of_business_fails_before_http_and_retry_reuses_available_files(): void
    {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $profile = $user->kycProfile()->firstOrFail();
        $metadata = (array) $profile->metadata;
        unset($metadata['nium_v5_fields']['natureOfBusiness']);
        $profile->update(['metadata' => $metadata]);
        $fileIdsBefore = KycDocument::query()
            ->where('kyc_profile_id', $profile->id)
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (KycDocument $document): array => [
                $document->id => $document->metadata['nium_file_id'],
            ])
            ->all();
        $this->assertCount(3, $fileIdsBefore);
        $this->assertSame(
            ['AVAILABLE'],
            KycDocument::query()
                ->where('kyc_profile_id', $profile->id)
                ->pluck('metadata')
                ->map(fn (array $metadata): string => (string) $metadata['nium_file_state'])
                ->unique()
                ->values()
                ->all(),
        );
        $allowCustomerRequests = false;
        $customerLookupCalls = 0;
        $customerCreateCalls = 0;
        $createResponse = $this->fixture('customer-v5-create-response.json');

        Http::fake(function (Request $request) use (
            &$allowCustomerRequests,
            &$customerLookupCalls,
            &$customerCreateCalls,
            $createResponse,
        ) {
            if (! $allowCustomerRequests) {
                return Http::response(['message' => 'unexpected HTTP request'], 500);
            }

            if ($request->method() === 'GET') {
                $customerLookupCalls++;

                return Http::response(['customers' => []]);
            }

            $customerCreateCalls++;

            return Http::response([
                ...$createResponse,
                'externalId' => $request->data()['externalId'],
            ]);
        });

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
            $this->fail('Expected missing corporate natureOfBusiness to block Nium onboarding.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Nium SG corporate full KYC requires approved KYC metadata field '
                .'nium_v5_fields.natureOfBusiness as an object.',
                $exception->getMessage(),
            );
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('user_provider_accounts', 0);
        $this->assertSame(
            $fileIdsBefore,
            KycDocument::query()
                ->where('kyc_profile_id', $profile->id)
                ->orderBy('id')
                ->get()
                ->mapWithKeys(fn (KycDocument $document): array => [
                    $document->id => $document->metadata['nium_file_id'],
                ])
                ->all(),
        );

        $metadata['nium_v5_fields']['natureOfBusiness'] = [
            'operatingCountries' => ['SG', 'HK'],
            'industryCodes' => ['IS144'],
        ];
        $profile->update(['metadata' => $metadata]);
        $user->unsetRelation('kycProfile');
        $allowCustomerRequests = true;

        $account = app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
        $serializedLogs = ApiRequestLog::query()->get()
            ->map(fn (ApiRequestLog $log): string => json_encode($log->toArray(), JSON_THROW_ON_ERROR))
            ->implode('\n');

        $this->assertSame('active', $account->status);
        $this->assertSame(1, $customerLookupCalls);
        $this->assertSame(1, $customerCreateCalls);
        $this->assertSame(
            $fileIdsBefore,
            KycDocument::query()
                ->where('kyc_profile_id', $profile->id)
                ->orderBy('id')
                ->get()
                ->mapWithKeys(fn (KycDocument $document): array => [
                    $document->id => $document->metadata['nium_file_id'],
                ])
                ->all(),
        );
        foreach ([
            'IS144',
            'IU003',
            'ATVSG02',
            'MVSG10',
            'ATC03',
            'ATVSG01',
            'MVSG05',
            'ATC02',
            'SG011',
            'EM009',
            '1234567890',
            'DBSSSGSG',
            '192.0.2.10',
            self::DEVICE_SESSION_ID,
            '1 Corporate Avenue',
            '2 Applicant Street',
            '3 Owner Road',
            'SG-01',
            'SG-02',
            'SG-03',
        ] as $rawFieldValue) {
            $this->assertStringNotContainsString($rawFieldValue, $serializedLogs);
        }
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => str_contains(
            $request->url(),
            'document-storage-sandbox.nium.test',
        ));
    }

    public function test_missing_sg_corporate_expected_account_usage_fails_before_http(): void
    {
        $this->assertMissingSgCorporateSourceFieldFailsBeforeHttp(
            'expectedAccountUsage',
            'Nium SG corporate full KYC requires approved KYC metadata field '
            .'nium_v5_fields.expectedAccountUsage as an object.',
        );
    }

    public function test_missing_sg_corporate_size_of_business_fails_before_http(): void
    {
        $this->assertMissingSgCorporateSourceFieldFailsBeforeHttp(
            'sizeOfBusiness',
            'Nium SG corporate full KYC requires approved KYC metadata field '
            .'nium_v5_fields.sizeOfBusiness as an object.',
        );
    }

    public function test_sg_corporate_minimum_kyc_type_fails_before_http(): void
    {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $profile = $user->kycProfile()->firstOrFail();
        $metadata = (array) $profile->metadata;
        $metadata['nium_kyc_type'] = 'minimum';
        $profile->update(['metadata' => $metadata]);
        Http::fake();

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
            $this->fail('Expected SG corporate minimum KYC to fail before HTTP.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('nium_kyc_type to be full', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_missing_sg_corporate_applicant_declaration_fails_before_http(): void
    {
        $this->assertMissingSgCorporateMetadataPathFailsBeforeHttp('applicantDeclaration');
    }

    public function test_false_sg_corporate_applicant_declaration_fails_before_http(): void
    {
        $this->assertInvalidSgCorporateMetadataValueFailsBeforeHttp(
            'applicantDeclaration',
            false,
        );
    }

    public function test_missing_sg_corporate_applicant_declaration_timestamp_fails_before_http(): void
    {
        $this->assertMissingSgCorporateMetadataPathFailsBeforeHttp('applicantDeclarationTimeStamp');
    }

    public function test_invalid_sg_corporate_applicant_declaration_timestamp_fails_before_http(): void
    {
        $this->assertInvalidSgCorporateMetadataValueFailsBeforeHttp(
            'applicantDeclarationTimeStamp',
            '2026-07-23T05:00:00Z',
        );
    }

    public function test_missing_sg_corporate_multi_layered_flag_fails_before_http(): void
    {
        $this->assertMissingSgCorporateMetadataPathFailsBeforeHttp('isMultiLayeredCompany');
    }

    public function test_string_sg_corporate_multi_layered_flag_fails_before_http(): void
    {
        $this->assertInvalidSgCorporateMetadataValueFailsBeforeHttp(
            'isMultiLayeredCompany',
            'false',
        );
    }

    public function test_missing_sg_corporate_debit_usage_fails_before_http(): void
    {
        $this->assertMissingSgCorporateMetadataPathFailsBeforeHttp('expectedAccountUsage.debit');
    }

    public function test_missing_sg_corporate_credit_usage_fails_before_http(): void
    {
        $this->assertMissingSgCorporateMetadataPathFailsBeforeHttp('expectedAccountUsage.credit');
    }

    public function test_missing_sg_corporate_top_transaction_countries_fails_before_http(): void
    {
        $this->assertMissingSgCorporateMetadataPathFailsBeforeHttp(
            'expectedAccountUsage.credit.topTransactionCountries',
        );
    }

    public function test_missing_sg_corporate_debit_top_transaction_countries_fails_before_http(): void
    {
        $this->assertMissingSgCorporateMetadataPathFailsBeforeHttp(
            'expectedAccountUsage.debit.topTransactionCountries',
        );
    }

    public function test_missing_sg_corporate_total_employees_fails_before_http(): void
    {
        $this->assertMissingSgCorporateMetadataPathFailsBeforeHttp('sizeOfBusiness.totalEmployees');
    }

    public function test_missing_sg_corporate_operating_countries_fails_before_http(): void
    {
        $this->assertMissingSgCorporateMetadataPathFailsBeforeHttp(
            'natureOfBusiness.operatingCountries',
        );
    }

    public function test_missing_sg_corporate_bank_account_details_fails_before_http(): void
    {
        $this->assertMissingSgCorporateMetadataPathFailsBeforeHttp('bankAccountDetails');
    }

    public function test_missing_sg_corporate_device_details_fails_before_http(): void
    {
        $this->assertMissingSgCorporateMetadataPathFailsBeforeHttp('deviceDetails');
    }

    public function test_sg_corporate_country_lists_are_trimmed_uppercased_and_deduplicated(): void
    {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $profile = $user->kycProfile()->firstOrFail();
        $metadata = (array) $profile->metadata;
        Arr::set($metadata, 'nium_v5_fields.natureOfBusiness.operatingCountries', [
            'sg',
            ' hK ',
            'SG',
        ]);
        Arr::set($metadata, 'nium_v5_fields.expectedAccountUsage.credit.topTransactionCountries', [
            'sG',
            ' us ',
            'SG',
        ]);
        Arr::set($metadata, 'nium_v5_fields.expectedAccountUsage.debit.topTransactionCountries', [
            'in',
            'Sg',
            'IN',
        ]);
        $profile->update(['metadata' => $metadata]);
        $user->unsetRelation('kycProfile');
        Http::fake();

        $payload = app(NiumCustomerPayloadFactory::class)->build(
            $user,
            (string) Str::uuid(),
        );

        $this->assertSame(['SG', 'HK'], $payload['natureOfBusiness']['operatingCountries']);
        $this->assertSame(
            ['SG', 'US'],
            $payload['expectedAccountUsage']['credit']['topTransactionCountries'],
        );
        $this->assertSame(
            ['IN', 'SG'],
            $payload['expectedAccountUsage']['debit']['topTransactionCountries'],
        );
        Http::assertNothingSent();
    }

    public function test_invalid_length_sg_corporate_operating_country_fails_before_http(): void
    {
        $this->assertInvalidSgCorporateMetadataValueFailsBeforeHttp(
            'natureOfBusiness.operatingCountries',
            ['SGP'],
        );
    }

    public function test_numeric_sg_corporate_credit_country_fails_before_http(): void
    {
        $this->assertInvalidSgCorporateMetadataValueFailsBeforeHttp(
            'expectedAccountUsage.credit.topTransactionCountries',
            [65],
        );
    }

    public function test_empty_sg_corporate_debit_country_list_fails_before_http(): void
    {
        $this->assertInvalidSgCorporateMetadataValueFailsBeforeHttp(
            'expectedAccountUsage.debit.topTransactionCountries',
            [],
        );
    }

    public function test_sg_corporate_country_list_containing_only_invalid_values_fails_before_http(): void
    {
        $this->assertInvalidSgCorporateMetadataValueFailsBeforeHttp(
            'natureOfBusiness.operatingCountries',
            ['', '123'],
        );
    }

    public function test_unconfigured_sg_corporate_client_policy_fails_closed_before_http(): void
    {
        config()->set('services.nium.sg_corporate_client_schema', [
            'require_bank_account_details' => null,
            'require_device_details' => null,
            'require_routing_codes' => null,
        ]);
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        Http::fake();

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
            $this->fail('Expected an unconfigured client schema policy to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Nium SG corporate client schema requirements are not configured.',
                $exception->getMessage(),
            );
        }

        Http::assertNothingSent();
    }

    public function test_optional_sg_corporate_bank_and_device_sections_may_be_absent(): void
    {
        config()->set('services.nium.sg_corporate_client_schema', [
            'require_bank_account_details' => false,
            'require_device_details' => false,
            'require_routing_codes' => false,
        ]);
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $profile = $user->kycProfile()->firstOrFail();
        $metadata = (array) $profile->metadata;
        Arr::forget($metadata, [
            'nium_v5_fields.bankAccountDetails',
            'nium_v5_fields.deviceDetails',
        ]);
        $profile->update(['metadata' => $metadata]);
        $user->unsetRelation('kycProfile');
        Http::fake();

        $payload = app(NiumCustomerPayloadFactory::class)->build(
            $user,
            (string) Str::uuid(),
        );

        $this->assertArrayNotHasKey('bankAccountDetails', $payload);
        $this->assertArrayNotHasKey('deviceDetails', $payload);
        Http::assertNothingSent();
    }

    public function test_optional_sg_corporate_routing_codes_may_be_absent(): void
    {
        config()->set('services.nium.sg_corporate_client_schema', [
            'require_bank_account_details' => true,
            'require_device_details' => false,
            'require_routing_codes' => false,
        ]);
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $profile = $user->kycProfile()->firstOrFail();
        $metadata = (array) $profile->metadata;
        Arr::forget($metadata, [
            'nium_v5_fields.bankAccountDetails.routingCodes',
            'nium_v5_fields.deviceDetails',
        ]);
        $profile->update(['metadata' => $metadata]);
        $user->unsetRelation('kycProfile');
        Http::fake();

        $payload = app(NiumCustomerPayloadFactory::class)->build(
            $user,
            (string) Str::uuid(),
        );

        $this->assertArrayHasKey('bankAccountDetails', $payload);
        $this->assertArrayNotHasKey('routingCodes', $payload['bankAccountDetails']);
        $this->assertArrayNotHasKey('deviceDetails', $payload);
        Http::assertNothingSent();
    }

    public function test_supplied_optional_sg_corporate_bank_section_is_still_validated(): void
    {
        config()->set('services.nium.sg_corporate_client_schema', [
            'require_bank_account_details' => false,
            'require_device_details' => false,
            'require_routing_codes' => false,
        ]);
        $this->assertMissingSgCorporateMetadataPathFailsBeforeHttp(
            'bankAccountDetails.accountName',
        );
    }

    public function test_supplied_optional_sg_corporate_routing_codes_are_still_validated(): void
    {
        config()->set('services.nium.sg_corporate_client_schema', [
            'require_bank_account_details' => true,
            'require_device_details' => false,
            'require_routing_codes' => false,
        ]);
        $this->assertInvalidSgCorporateMetadataValueFailsBeforeHttp(
            'bankAccountDetails.routingCodes',
            'SWIFT:DBSSSGSG',
        );
    }

    public function test_supplied_optional_sg_corporate_device_section_is_still_validated(): void
    {
        config()->set('services.nium.sg_corporate_client_schema', [
            'require_bank_account_details' => false,
            'require_device_details' => false,
            'require_routing_codes' => false,
        ]);
        $this->assertInvalidSgCorporateMetadataValueFailsBeforeHttp(
            'deviceDetails.ipAddress',
            'not-an-ip',
        );
    }

    public function test_sg_corporate_bank_account_missing_account_name_fails_before_http(): void
    {
        $this->assertMissingSgCorporateMetadataPathFailsBeforeHttp(
            'bankAccountDetails.accountName',
        );
    }

    public function test_sg_corporate_bank_account_invalid_country_fails_before_http(): void
    {
        $this->assertInvalidSgCorporateMetadataValueFailsBeforeHttp(
            'bankAccountDetails.bankCountry',
            'SGP',
        );
    }

    public function test_sg_corporate_bank_account_invalid_currency_fails_before_http(): void
    {
        $this->assertInvalidSgCorporateMetadataValueFailsBeforeHttp(
            'bankAccountDetails.currency',
            'SG',
        );
    }

    public function test_sg_corporate_routing_codes_non_array_fails_before_http(): void
    {
        $this->assertInvalidSgCorporateMetadataValueFailsBeforeHttp(
            'bankAccountDetails.routingCodes',
            'SWIFT:DBSSSGSG',
        );
    }

    public function test_sg_corporate_required_routing_codes_empty_fails_before_http(): void
    {
        $this->assertInvalidSgCorporateMetadataValueFailsBeforeHttp(
            'bankAccountDetails.routingCodes',
            [],
        );
    }

    public function test_sg_corporate_routing_code_missing_type_fails_before_http(): void
    {
        $this->assertInvalidSgCorporateMetadataValueFailsBeforeHttp(
            'bankAccountDetails.routingCodes',
            [['value' => 'DBSSSGSG']],
        );
    }

    public function test_sg_corporate_routing_code_missing_value_fails_before_http(): void
    {
        $this->assertInvalidSgCorporateMetadataValueFailsBeforeHttp(
            'bankAccountDetails.routingCodes',
            [['type' => 'SWIFT']],
        );
    }

    public function test_sg_corporate_device_details_missing_field_fails_before_http(): void
    {
        $this->assertMissingSgCorporateMetadataPathFailsBeforeHttp(
            'deviceDetails.sessionId',
        );
    }

    public function test_sg_corporate_device_details_invalid_ip_fails_before_http(): void
    {
        $this->assertInvalidSgCorporateMetadataValueFailsBeforeHttp(
            'deviceDetails.ipAddress',
            'not-an-ip',
        );
    }

    public function test_sg_corporate_device_details_invalid_country_fails_before_http(): void
    {
        $this->assertInvalidSgCorporateMetadataValueFailsBeforeHttp(
            'deviceDetails.ipCountryCode',
            'SGP',
        );
    }

    public function test_sg_corporate_device_details_valid_session_uuid_is_preserved(): void
    {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        Http::fake();

        $payload = app(NiumCustomerPayloadFactory::class)->build(
            $user,
            (string) Str::uuid(),
        );

        $this->assertSame(self::DEVICE_SESSION_ID, $payload['deviceDetails']['sessionId']);
        Http::assertNothingSent();
    }

    public function test_sg_corporate_device_details_non_uuid_session_fails_before_http(): void
    {
        $this->assertInvalidSgCorporateMetadataValueFailsBeforeHttp(
            'deviceDetails.sessionId',
            'synthetic-v3-eadfc7651aa42c6c',
        );
    }

    public function test_sg_corporate_device_details_empty_session_values_fail_before_http(): void
    {
        foreach ([null, '', '   '] as $value) {
            $this->assertInvalidSgCorporateMetadataValueFailsBeforeHttp(
                'deviceDetails.sessionId',
                $value,
            );
        }
    }

    public function test_invalid_sg_corporate_stakeholder_role_fails_before_http(): void
    {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $stakeholder = $user->kycProfile()
            ->firstOrFail()
            ->relatedPersons()
            ->where('relationship_type', 'beneficial_owner')
            ->firstOrFail();
        $metadata = (array) $stakeholder->metadata;
        $metadata['positions'] = ['unsupported_role'];
        $stakeholder->update(['metadata' => $metadata]);
        Http::fake();

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
            $this->fail('Expected an unsupported stakeholder role to fail before HTTP.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'Unsupported Nium SG corporate stakeholder position',
                $exception->getMessage(),
            );
        }

        Http::assertNothingSent();
    }

    public function test_sg_corporate_position_aliases_map_to_supported_nium_constants(): void
    {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $stakeholder = $user->kycProfile()
            ->firstOrFail()
            ->relatedPersons()
            ->where('relationship_type', 'beneficial_owner')
            ->firstOrFail();
        $metadata = (array) $stakeholder->metadata;
        $metadata['positions'] = [
            'director',
            'beneficial_owner',
            'ultimate_beneficial_owner',
            'shareholder',
            'signatory',
        ];
        $stakeholder->update(['metadata' => $metadata]);
        $user->unsetRelation('kycProfile');
        Http::fake();

        $payload = app(NiumCustomerPayloadFactory::class)->build(
            $user,
            (string) Str::uuid(),
        );

        $this->assertSame([
            ['title' => 'DIRECTOR'],
            ['title' => 'UBO'],
            ['title' => 'SHAREHOLDER'],
            ['title' => 'SIGNATORY'],
        ], $payload['stakeholders']['individual'][0]['positions']);
        Http::assertNothingSent();
    }

    public function test_non_sg_corporate_payload_is_not_forced_to_full_kyc(): void
    {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $profile = $user->kycProfile()->firstOrFail();
        $metadata = (array) $profile->metadata;
        $metadata['nium_region'] = 'EU';
        $metadata['nium_kyc_type'] = 'minimum';
        $metadata['nium_v5_fields']['addresses'] = [
            'isBusinessAddressSameAsRegisteredAddress' => 'not-a-boolean',
            'businessAddress' => 'not-an-object',
        ];
        $profile->update([
            'registered_country_code' => 'DE',
            'country_code' => 'DE',
            'metadata' => $metadata,
        ]);
        $user->unsetRelation('kycProfile');
        Http::fake();

        $payload = app(NiumCustomerPayloadFactory::class)->build(
            $user,
            (string) Str::uuid(),
        );

        $this->assertSame('EU', $payload['region']);
        $this->assertSame('minimum', $payload['kycType']);
        Http::assertNothingSent();
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

    private function assertMissingSgCorporateSourceFieldFailsBeforeHttp(
        string $field,
        string $expectedMessage,
    ): void {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $profile = $user->kycProfile()->firstOrFail();
        $metadata = (array) $profile->metadata;
        unset($metadata['nium_v5_fields'][$field]);
        $profile->update(['metadata' => $metadata]);
        Http::fake();

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
            $this->fail("Expected missing {$field} to block Nium onboarding.");
        } catch (RuntimeException $exception) {
            $this->assertSame($expectedMessage, $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('user_provider_accounts', 0);
        $this->assertDatabaseCount('api_request_logs', 0);
    }

    private function assertMissingSgCorporateMetadataPathFailsBeforeHttp(string $path): void
    {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $profile = $user->kycProfile()->firstOrFail();
        $metadata = (array) $profile->metadata;
        Arr::forget($metadata, 'nium_v5_fields.'.$path);
        $profile->update(['metadata' => $metadata]);
        Http::fake();

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
            $this->fail("Expected missing {$path} to block Nium onboarding.");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'nium_v5_fields.'.$path,
                $exception->getMessage(),
            );
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('user_provider_accounts', 0);
    }

    private function assertInvalidSgCorporateMetadataValueFailsBeforeHttp(
        string $path,
        mixed $value,
    ): void {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $profile = $user->kycProfile()->firstOrFail();
        $metadata = (array) $profile->metadata;
        Arr::set($metadata, 'nium_v5_fields.'.$path, $value);
        $profile->update(['metadata' => $metadata]);
        Http::fake();

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
            $this->fail("Expected invalid {$path} to block Nium onboarding.");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'nium_v5_fields.'.$path,
                $exception->getMessage(),
            );
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('user_provider_accounts', 0);
        $this->assertDatabaseCount('api_request_logs', 0);
    }

    private function assertMissingSgCorporateAddressStateFailsBeforeHttp(
        string $subject,
        string $expectedMessage,
    ): void {
        $provider = $this->provider();
        $user = $this->approvedCorporate($provider);
        $profile = $user->kycProfile()->firstOrFail();

        match ($subject) {
            'profile' => $profile->update(['state' => null]),
            'applicant' => $profile->relatedPersons()
                ->where('relationship_type', 'applicant')
                ->firstOrFail()
                ->update(['state' => null]),
            'stakeholder' => $profile->relatedPersons()
                ->where('relationship_type', 'beneficial_owner')
                ->firstOrFail()
                ->update(['state' => null]),
        };

        $fileIdsBefore = KycDocument::query()
            ->where('kyc_profile_id', $profile->id)
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (KycDocument $document): array => [
                $document->id => $document->metadata['nium_file_id'],
            ])
            ->all();
        Http::fake();

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
            $this->fail("Expected missing {$subject} address state to block Nium onboarding.");
        } catch (RuntimeException $exception) {
            $this->assertSame($expectedMessage, $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('user_provider_accounts', 0);
        $this->assertSame(
            $fileIdsBefore,
            KycDocument::query()
                ->where('kyc_profile_id', $profile->id)
                ->orderBy('id')
                ->get()
                ->mapWithKeys(fn (KycDocument $document): array => [
                    $document->id => $document->metadata['nium_file_id'],
                ])
                ->all(),
        );
    }

    public function test_customer_create_timeout_is_not_automatically_resubmitted_after_authoritative_lookup_finds_nothing(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $postCount = 0;

        Http::fake(function (Request $request) use (&$postCount) {
            if ($request->method() === 'POST') {
                $postCount++;

                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response(['customers' => []]);
        });

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
            $this->fail('Expected the Customer Create timeout.');
        } catch (ConnectionException) {
            // The outcome is unknown and the write is quarantined from automatic retry.
        }

        $account = $user->providerAccounts()->where('provider_id', $provider->id)->sole();
        $this->assertFalse(Arr::get((array) $account->metadata, 'is_resubmission_allowed'));

        app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);

        $this->assertSame(1, $postCount);
        $this->assertSame('customer_create_unknown', $account->fresh()->reconciliation_error);
        $this->assertSame('unknown_external_outcome', ApiRequestLog::query()
            ->where('operation', 'customer_create')
            ->sole()
            ->response_body['external_outcome']);
    }

    public function test_customer_lookup_connection_failure_is_not_quarantined_as_unknown_write_outcome(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        Http::fake(['*' => Http::failedConnection('synthetic customer lookup connection failure')]);

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
            $this->fail('Expected the Customer lookup connection failure.');
        } catch (ConnectionException) {
            // A failed GET has no external write outcome to quarantine.
        }

        $account = $user->providerAccounts()->where('provider_id', $provider->id)->sole();
        $this->assertNotSame(false, Arr::get((array) $account->metadata, 'is_resubmission_allowed'));
        $this->assertNotSame('customer_create_unknown', $account->reconciliation_error);

        $log = ApiRequestLog::query()->sole();
        $this->assertSame('GET', $log->request_method);
        $this->assertSame('connection_failure', $log->transport_outcome);
        $this->assertArrayNotHasKey('external_outcome', $log->response_body);
        Http::assertSentCount(1);
    }

    public function test_customer_create_submitting_and_unknown_states_never_issue_a_create_post(): void
    {
        foreach ([
            NiumProviderAccountStateService::CUSTOMER_CREATE_SUBMITTING,
            NiumProviderAccountStateService::CUSTOMER_CREATE_UNKNOWN,
        ] as $state) {
            $provider = $this->provider();
            $user = $this->approvedIndividual($provider);
            $account = $this->pendingAccount($user, $provider);
            $account->update([
                'reconciliation_status' => $state === NiumProviderAccountStateService::CUSTOMER_CREATE_UNKNOWN
                    ? 'failed'
                    : 'pending',
                'reconciliation_error' => $state,
                'metadata' => $state === NiumProviderAccountStateService::CUSTOMER_CREATE_UNKNOWN
                    ? ['is_resubmission_allowed' => false]
                    : [],
            ]);

            Http::fake(['*' => Http::response(['customers' => []])]);
            $result = app(NiumCustomerOnboardingService::class)->beginOnboarding($provider, $user);

            Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
            Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
            $this->assertSame($state, $account->fresh()->reconciliation_error);

            if ($state === NiumProviderAccountStateService::CUSTOMER_CREATE_SUBMITTING) {
                $this->assertSame('wait_for_customer_creation', $result->nextAction);
            }

            $account->delete();
            $user->delete();
        }
    }

    public function test_authoritative_customer_create_rejection_is_retry_eligible(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);

        Http::fake(function (Request $request) {
            return $request->method() === 'POST'
                ? Http::response(['code' => 'validation_error'], 422)
                : Http::response(['customers' => []]);
        });

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
            $this->fail('Expected authoritative Customer Create rejection.');
        } catch (NiumProviderRequestException) {
            // A completed 4xx proves that this create attempt was rejected.
        }

        $account = $user->providerAccounts()->where('provider_id', $provider->id)->sole();
        $this->assertSame(NiumProviderAccountStateService::CUSTOMER_CREATE_FAILED, $account->reconciliation_error);
        $this->assertTrue(Arr::get((array) $account->metadata, 'is_resubmission_allowed'));
        Http::assertSentCount(2);
    }

    public function test_customer_create_evidence_failure_enters_unknown_and_blocks_replay(): void
    {
        $provider = $this->provider();
        $user = $this->approvedIndividual($provider);
        $postCount = 0;

        DB::statement(<<<'SQL'
            CREATE TRIGGER fail_nium_create_evidence
            BEFORE INSERT ON api_request_logs
            WHEN NEW.request_method = 'POST'
            BEGIN
                SELECT RAISE(ABORT, 'synthetic evidence persistence failure');
            END
        SQL);
        Http::fake(function (Request $request) use (&$postCount) {
            if ($request->method() === 'POST') {
                $postCount++;

                return Http::response($this->fixture('customer-v5-create-response.json'));
            }

            return Http::response(['customers' => []]);
        });

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
            $this->fail('Expected evidence persistence failure.');
        } catch (NiumEvidencePersistenceException) {
            // The provider outcome is unknown because its response could not be durably captured.
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_nium_create_evidence');
        }

        app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);

        $account = $user->providerAccounts()->where('provider_id', $provider->id)->sole();
        $this->assertSame(NiumProviderAccountStateService::CUSTOMER_CREATE_UNKNOWN, $account->reconciliation_error);
        $this->assertFalse(Arr::get((array) $account->metadata, 'is_resubmission_allowed'));
        $this->assertSame(1, $postCount);
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
            'state' => 'SG-01',
            'postal_code' => '018989',
            'country_code' => 'SG',
            'metadata' => [
                'nium_region' => 'SG',
                'nium_kyc_type' => 'full',
                'registered_date' => '2020-01-15',
                'nium_business_type' => 'private_company',
                'nium_v5_fields' => [
                    'tradeName' => 'Acme Holdings',
                    'addresses' => [
                        'isBusinessAddressSameAsRegisteredAddress' => true,
                    ],
                    'applicantDeclaration' => true,
                    'applicantDeclarationTimeStamp' => '2026-07-23 05:00:00',
                    'isMultiLayeredCompany' => false,
                    'natureOfBusiness' => [
                        'operatingCountries' => ['SG', 'HK'],
                        'industryCodes' => ['IS144'],
                    ],
                    'expectedAccountUsage' => [
                        'credit' => [
                            'averageTransactionValue' => 'ATVSG02',
                            'monthlyTransactionVolume' => 'MVSG10',
                            'monthlyTransactions' => 'ATC03',
                            'topTransactionCountries' => ['SG', 'HK'],
                        ],
                        'debit' => [
                            'averageTransactionValue' => 'ATVSG01',
                            'monthlyTransactionVolume' => 'MVSG05',
                            'monthlyTransactions' => 'ATC02',
                            'topTransactionCountries' => ['IN', 'SG'],
                        ],
                        'intendedUses' => ['IU003'],
                    ],
                    'sizeOfBusiness' => [
                        'annualTurnover' => 'SG011',
                        'totalEmployees' => 'EM009',
                    ],
                    'bankAccountDetails' => [
                        'accountName' => 'Acme Holdings Limited',
                        'accountNumber' => '1234567890',
                        'bankCountry' => 'SG',
                        'currency' => 'SGD',
                        'bankAccountType' => 'current',
                        'bankName' => 'DBS Bank',
                        'routingCodes' => [
                            [
                                'type' => 'SWIFT',
                                'value' => 'DBSSSGSG',
                            ],
                        ],
                    ],
                    'deviceDetails' => [
                        'ipCountryCode' => 'SG',
                        'deviceInfo' => 'Synthetic test browser',
                        'ipAddress' => '192.0.2.10',
                        'sessionId' => self::DEVICE_SESSION_ID,
                    ],
                ],
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
            'state' => 'SG-02',
            'postal_code' => '018990',
            'country_code' => 'SG',
            'metadata' => [
                'email' => $user->email,
                'phone' => $user->phone,
                'positions' => ['director'],
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
            'state' => 'SG-03',
            'postal_code' => '018991',
            'country_code' => 'SG',
            'metadata' => [
                'email' => "uma.owner.{$suffix}@example.com",
                'phone' => '+6598765432',
                'positions' => ['ultimate_beneficial_owner'],
            ],
        ]);

        Storage::disk('kyc_private')->put(
            'kyc/corporate/business-registration.png',
            self::BUSINESS_DOCUMENT_BYTES,
        );
        Storage::disk('kyc_private')->put(
            'kyc/corporate/applicant-passport.png',
            self::APPLICANT_DOCUMENT_BYTES,
        );
        Storage::disk('kyc_private')->put(
            'kyc/corporate/stakeholder-passport.png',
            self::STAKEHOLDER_DOCUMENT_BYTES,
        );

        $kycProfile->documents()->create([
            'type' => 'business_registration',
            'status' => 'approved',
            'file_url' => 'https://files.example.test/business-registration.pdf',
            'storage_disk' => 'kyc_private',
            'file_path' => 'kyc/corporate/business-registration.png',
            'document_number' => 'ACME-2026-001',
            'issuing_country_code' => 'SG',
            'metadata' => $this->availableFileMetadata(self::BUSINESS_FILE_ID),
        ]);
        $applicant->documents()->create([
            'kyc_profile_id' => $kycProfile->id,
            'type' => 'passport_front',
            'status' => 'approved',
            'file_url' => 'https://files.example.test/applicant-passport.jpg',
            'storage_disk' => 'kyc_private',
            'file_path' => 'kyc/corporate/applicant-passport.png',
            'document_number' => 'APPLICANT-PASSPORT',
            'issuing_country_code' => 'SG',
            'metadata' => $this->availableFileMetadata(self::APPLICANT_FILE_ID),
        ]);
        $stakeholder->documents()->create([
            'kyc_profile_id' => $kycProfile->id,
            'type' => 'passport_front',
            'status' => 'approved',
            'file_url' => 'https://files.example.test/stakeholder-passport.jpg',
            'storage_disk' => 'kyc_private',
            'file_path' => 'kyc/corporate/stakeholder-passport.png',
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

    /**
     * @return array<string, string>
     */
    private static function validBusinessAddressSource(): array
    {
        return [
            'address_line1' => '88 Trading Road',
            'city' => 'Singapore',
            'state' => 'SG-04',
            'postal_code' => '049321',
            'country_code' => 'SG',
        ];
    }

    private function setSgCorporateAddresses(
        User $user,
        bool $relationship,
        mixed $businessAddress = null,
        bool $includeBusinessAddress = true,
    ): void {
        $profile = $user->kycProfile()->firstOrFail();
        $metadata = (array) $profile->metadata;
        $metadata['nium_v5_fields']['addresses'] = [
            'isBusinessAddressSameAsRegisteredAddress' => $relationship,
        ];

        if ($includeBusinessAddress) {
            $metadata['nium_v5_fields']['addresses']['businessAddress'] = $businessAddress;
        }

        $profile->update(['metadata' => $metadata]);
        $user->unsetRelation('kycProfile');
    }

    private function assertSgCorporateAddressFailureHasNoSideEffects(
        IntegrationProvider $provider,
        User $user,
        string $expectedCode,
        array $forbiddenErrorFragments = [],
    ): void {
        $profile = $user->kycProfile()->firstOrFail();
        $submission = $user->kycProviderSubmissions()->firstOrFail();
        $submissionFingerprint = $this->modelFingerprint($submission);
        $providerAccountCount = UserProviderAccount::query()->count();
        $auditCount = AuditLog::query()->count();
        $apiRequestLogCount = ApiRequestLog::query()->count();
        $documentFingerprints = $profile->documents()
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (KycDocument $document): array => [
                $document->id => $this->modelFingerprint($document),
            ])
            ->all();
        $storageFingerprint = $this->storageFingerprint();
        $this->mock(NiumCustomerDocumentPreparationService::class)
            ->shouldNotReceive('prepare');
        Http::fake(fn () => throw new RuntimeException('Unexpected HTTP request.'));
        $user->unsetRelation('kycProfile');

        try {
            app(NiumCustomerOnboardingService::class)->syncUser($provider, $user);
            $this->fail('Expected SG corporate business-address validation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expectedCode, $exception->getMessage());
            $this->assertStringNotContainsString('metadata.', $exception->getMessage());
            $this->assertStringNotContainsString('businessAddress', $exception->getMessage());

            foreach ($forbiddenErrorFragments as $fragment) {
                $this->assertStringNotContainsString($fragment, $exception->getMessage());
            }
        }

        $this->assertSame($providerAccountCount, UserProviderAccount::query()->count());
        $this->assertSame($auditCount, AuditLog::query()->count());
        $this->assertSame($apiRequestLogCount, ApiRequestLog::query()->count());
        $this->assertSame($submissionFingerprint, $this->modelFingerprint($submission->fresh()));
        $this->assertSame(
            $documentFingerprints,
            $profile->documents()
                ->orderBy('id')
                ->get()
                ->mapWithKeys(fn (KycDocument $document): array => [
                    $document->id => $this->modelFingerprint($document),
                ])
                ->all(),
        );
        $this->assertSame($storageFingerprint, $this->storageFingerprint());
        Http::assertNothingSent();
    }

    private function modelFingerprint(Model $model): string
    {
        $attributes = $model->getRawOriginal();
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, string>
     */
    private function storageFingerprint(): array
    {
        $disk = Storage::disk('kyc_private');

        return collect($disk->allFiles())
            ->sort()
            ->mapWithKeys(fn (string $path): array => [
                $path => hash('sha256', $disk->get($path)),
            ])
            ->all();
    }

    /**
     * @param  array<int, string>  $observedOperations
     */
    private function activateDmlGuard(array &$observedOperations): void
    {
        DB::connection()->beforeExecuting(function (string $query) use (&$observedOperations): void {
            $operation = strtoupper((string) strtok(ltrim($query), " \t\n\r"));
            $observedOperations[] = $operation;

            if (in_array($operation, ['INSERT', 'UPDATE', 'DELETE', 'REPLACE'], true)) {
                throw new \LogicException("Unexpected {$operation} during guarded validation.");
            }
        });
    }

    private function rawNiumErrorSecrets(User $user, string $rawDescription): array
    {
        return [
            $rawDescription,
            'Alice Applicant',
            (string) $user->email,
            (string) $user->phone,
            '1988-04-12',
            '2 Applicant Street',
            self::APPLICANT_FILE_ID,
            '1234567890',
            'sandbox-api-key',
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
