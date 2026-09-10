<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Nium\NiumBeneficiaryPayoutMethodResolver;
use App\Services\Nium\NiumBeneficiaryService;
use App\Services\Nium\NiumTransferPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class NiumBeneficiaryLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.nium', array_replace_recursive((array) config('services.nium'), [
            'base_url' => 'https://gateway.nium.test',
            'client_id' => 'lifecycle-client-id',
            'auth' => ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'lifecycle-key'],
            'webhook' => ['static_header_name' => 'x-partner-key', 'static_header_value' => 'lifecycle-partner-key'],
            'health_endpoint' => '/api/v1/client/{clientHashId}',
            'customer_create_endpoint' => '/api/v1/client/{clientHashId}/customer',
            'customer_list_endpoint' => '/api/v1/client/{clientHashId}/customers',
            'customer_get_endpoint' => '/api/v1/client/{clientHashId}/customer/{customerHashId}',
            'beneficiary_endpoint' => '/api/v2/client/{clientHashId}/customer/{customerHashId}/beneficiaries',
            'supported_corridors' => [[
                'destinationCountry' => 'HK',
                'destinationCurrency' => 'USD',
                'payoutMethod' => 'SWIFT',
                'beneficiaryAccountType' => 'CORPORATE',
                'customerType' => 'CORPORATE',
                'routingCodeType' => ['SWIFT'],
            ]],
        ]));
    }

    public function test_successful_controller_create_persists_authoritative_corridor_and_safe_operational_metadata(): void
    {
        [$user, $provider, $token] = $this->customer();
        Http::fake(['*' => Http::response([
            'beneficiaryHashId' => 'verified-beneficiary-id',
            'status' => 'ACTIVE',
            'requestId' => 'safe-provider-request-id',
            'authenticationCode' => 'must-not-persist',
        ], 200)]);

        $response = $this->withToken($token)
            ->postJson("/api/user/users/{$user->id}/beneficiaries", $this->beneficiaryPayload($provider))
            ->assertCreated()
            ->assertJsonPath('payout_method', 'SWIFT');

        $beneficiary = Beneficiary::query()->sole();
        $this->assertSame('SWIFT', $beneficiary->payout_method);
        $this->assertSame('SWIFT', data_get($beneficiary->raw_data, 'nium.provider_verified.payout_method'));
        $this->assertSame('safe-provider-request-id', $beneficiary->raw_data['provider_request_id']);
        $this->assertSame('verified-beneficiary-id', $beneficiary->raw_data['beneficiary_id']);
        $this->assertStringNotContainsString('must-not-persist', json_encode($beneficiary->raw_data, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('must-not-persist', $response->getContent());
        $this->assertSame('verified-beneficiary-id', ApiRequestLog::query()->sole()->response_body['beneficiary_id']);

        app(NiumTransferPolicy::class)->normalizeCreate([
            'transfer_type' => 'payout',
            'source_currency' => 'USD',
            'target_currency' => 'USD',
            'purpose_code' => 'IR01811',
            'source_amount' => '25.00',
        ], $user, $provider, $beneficiary->fresh());
        $this->addToAssertionCount(1);

        Http::assertSent(function ($request): bool {
            return $request->data() === [
                'clientLegalEntity' => 'HK',
                'beneficiaryAccountType' => 'Corporate',
                'destinationCountry' => 'HK',
                'destinationCurrency' => 'USD',
                'payoutMethod' => 'SWIFT',
                'beneficiaryName' => 'Verified Corporate Beneficiary',
                'beneficiaryPostcode' => '999077',
                'beneficiaryAddress' => '1 Beneficiary Road',
                'beneficiaryCity' => 'Hong Kong',
                'beneficiaryCountryCode' => 'HK',
                'beneficiaryAccountNumber' => '123456789',
                'beneficiaryBankName' => 'Verified Bank',
                'routingCodeType1' => 'SWIFT',
                'routingCodeValue1' => 'HKBCHKHH',
            ];
        });
    }

    public function test_client_raw_data_cannot_forge_authoritative_payout_method(): void
    {
        [$user, $provider] = $this->customer();
        $beneficiary = $user->beneficiaries()->create([
            ...$this->beneficiaryAttributes($provider),
            'external_beneficiary_id' => 'unsafely-claimed-beneficiary',
            'status' => 'active',
            'raw_data' => ['nium' => ['payoutMethod' => 'SWIFT']],
        ]);

        $this->expectException(RuntimeException::class);
        app(NiumTransferPolicy::class)->normalizeCreate([
            'transfer_type' => 'payout',
            'source_currency' => 'USD',
            'target_currency' => 'USD',
            'purpose_code' => 'IR01811',
        ], $user, $provider, $beneficiary);
    }

    public function test_failed_provider_create_does_not_verify_payout_method(): void
    {
        [$user, $provider] = $this->customer();
        $beneficiary = $user->beneficiaries()->create($this->beneficiaryAttributes($provider));
        Http::fake(['*' => Http::response([
            'status' => 'FAILED',
            'code' => 'invalid_input',
            'message' => 'Rejected',
        ], 422)]);

        try {
            app(NiumBeneficiaryService::class)->createBeneficiary($provider, $beneficiary->fresh('user'));
            $this->fail('Failed provider creation must throw.');
        } catch (RuntimeException) {
            // The failed response is expected and must not produce authoritative corridor state.
        }

        $beneficiary->refresh();
        $this->assertNull($beneficiary->payout_method);
        $this->assertSame('create_failed', $beneficiary->status);
        $this->assertSame('invalid_input', $beneficiary->raw_data['provider_error_code']);
        $this->assertArrayNotHasKey('provider_verified', (array) data_get($beneficiary->raw_data, 'nium', []));
    }

    public function test_legacy_fallback_and_explicit_repair_require_matching_successful_request_evidence(): void
    {
        [$user, $provider] = $this->customer();
        $beneficiary = $user->beneficiaries()->create([
            ...$this->beneficiaryAttributes($provider),
            'external_beneficiary_id' => 'legacy-beneficiary-id',
            'status' => 'active',
            'raw_data' => ['nium' => ['payoutMethod' => 'LOCAL']],
        ]);
        $log = ApiRequestLog::query()->create([
            'provider_id' => $provider->id,
            'user_id' => $user->id,
            'operation' => 'beneficiary_create',
            'external_reference' => (string) $beneficiary->id,
            'request_method' => 'POST',
            'request_url' => '/beneficiaries',
            'request_body' => ['payout_method' => 'SWIFT'],
            'response_body' => ['beneficiary_id' => 'legacy-beneficiary-id'],
            'response_status' => 200,
            'transport_outcome' => 'response_received',
            'is_success' => true,
        ]);

        app(NiumTransferPolicy::class)->normalizeCreate([
            'transfer_type' => 'payout',
            'source_currency' => 'USD',
            'target_currency' => 'USD',
            'purpose_code' => 'IR01811',
        ], $user, $provider, $beneficiary);
        $this->addToAssertionCount(1);

        $arguments = [
            'beneficiary' => $beneficiary->id,
            '--user' => $user->id,
            '--provider' => $provider->id,
            '--external-beneficiary-id' => 'legacy-beneficiary-id',
            '--payout-method' => 'SWIFT',
        ];
        $this->artisan('nium:repair-beneficiary-payout-method', $arguments)
            ->expectsOutput("Beneficiary {$beneficiary->id} verified (dry run): SWIFT from ApiRequestLog {$log->id}.")
            ->assertSuccessful();
        $this->assertNull($beneficiary->fresh()->payout_method);

        $this->artisan('nium:repair-beneficiary-payout-method', [
            ...$arguments,
            '--apply' => true,
            '--approve' => 'REPAIR_NIUM_BENEFICIARY_PAYOUT_METHOD',
            '--operator' => 'OPS-123 lifecycle repair test',
        ])->assertSuccessful();
        $this->assertSame('SWIFT', $beneficiary->fresh()->payout_method);

        $audit = AuditLog::query()->where('action', 'beneficiary.nium_payout_method_repaired')->sole();
        $this->assertSame((string) $beneficiary->id, $audit->entity_id);
        $this->assertSame([
            'beneficiary_id' => $beneficiary->id,
            'provider_id' => $provider->id,
            'user_id' => $user->id,
            'api_request_log_id' => $log->id,
            'external_beneficiary_id' => 'legacy-beneficiary-id',
            'payout_method' => 'SWIFT',
            'operator_context' => 'OPS-123 lifecycle repair test',
        ], $audit->new_data);
        $this->assertStringNotContainsString('lifecycle-key', json_encode($audit->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_missing_or_mismatched_response_beneficiary_evidence_cannot_resolve_or_apply(): void
    {
        [$user, $provider] = $this->customer();

        foreach ([
            'missing response evidence' => [],
            'mismatched response evidence' => ['beneficiary_id' => 'different-beneficiary-id'],
        ] as $case => $responseBody) {
            $beneficiary = $this->legacyBeneficiary($user, $provider, 'legacy-'.str_replace(' ', '-', $case));
            $this->beneficiaryEvidenceLog($beneficiary, $responseBody);

            $this->assertRepairFailsWithoutMutation($beneficiary, $case);
        }
    }

    public function test_correct_request_and_response_with_wrong_scope_cannot_resolve_or_apply(): void
    {
        [$user, $provider] = $this->customer();
        $otherProvider = IntegrationProvider::query()->create(['code' => 'other', 'name' => 'Other', 'status' => 'active']);
        $otherUser = User::factory()->create();

        foreach ([
            'provider_id' => $otherProvider->id,
            'user_id' => $otherUser->id,
            'external_reference' => '999999',
        ] as $field => $wrongValue) {
            $beneficiary = $this->legacyBeneficiary($user, $provider, "scoped-{$field}-{$user->id}");
            $this->beneficiaryEvidenceLog($beneficiary, [
                'beneficiary_id' => $beneficiary->external_beneficiary_id,
            ], [$field => $wrongValue]);

            $this->assertRepairFailsWithoutMutation($beneficiary, "wrong {$field}");
        }
    }

    private function assertRepairFailsWithoutMutation(Beneficiary $beneficiary, string $case): void
    {
        $this->assertNull(
            app(NiumBeneficiaryPayoutMethodResolver::class)->successfulRequestEvidence($beneficiary),
            $case,
        );

        $this->artisan('nium:repair-beneficiary-payout-method', [
            'beneficiary' => $beneficiary->id,
            '--user' => $beneficiary->user_id,
            '--provider' => $beneficiary->provider_id,
            '--external-beneficiary-id' => $beneficiary->external_beneficiary_id,
            '--payout-method' => 'SWIFT',
            '--apply' => true,
            '--approve' => 'REPAIR_NIUM_BENEFICIARY_PAYOUT_METHOD',
            '--operator' => "OPS-FAIL {$case}",
        ])->assertFailed();

        $this->assertNull($beneficiary->fresh()->payout_method, $case);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'beneficiary.nium_payout_method_repaired',
            'entity_id' => (string) $beneficiary->id,
        ]);
    }

    private function legacyBeneficiary(User $user, IntegrationProvider $provider, string $externalId): Beneficiary
    {
        return $user->beneficiaries()->create([
            ...$this->beneficiaryAttributes($provider),
            'external_beneficiary_id' => $externalId,
            'status' => 'active',
        ]);
    }

    private function beneficiaryEvidenceLog(Beneficiary $beneficiary, array $responseBody, array $overrides = []): ApiRequestLog
    {
        return ApiRequestLog::query()->create([
            'provider_id' => $beneficiary->provider_id,
            'user_id' => $beneficiary->user_id,
            'operation' => 'beneficiary_create',
            'external_reference' => (string) $beneficiary->id,
            'request_method' => 'POST',
            'request_url' => '/beneficiaries',
            'request_body' => ['payout_method' => 'SWIFT'],
            'response_status' => 200,
            'response_body' => $responseBody,
            'transport_outcome' => 'response_received',
            'is_success' => true,
            ...$overrides,
        ]);
    }

    private function customer(): array
    {
        $provider = IntegrationProvider::query()->create(['code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        $user = User::factory()->create(['kyc_status' => 'verified']);
        $user->profile()->create(['user_type' => 'business', 'country_code' => 'HK']);
        $user->kycProfile()->create([
            'status' => 'approved',
            'applicant_type' => 'business',
            'legal_name' => 'Lifecycle Customer',
            'registered_country_code' => 'HK',
            'address_line1' => '1 Customer Road',
            'city' => 'Hong Kong',
            'country_code' => 'HK',
            'metadata' => ['nium_region' => 'HK'],
        ]);
        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => 'lifecycle-customer-id',
            'external_account_id' => 'lifecycle-wallet-id',
            'status' => 'active',
            'provider_status' => 'clear',
            'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(),
            'provider_ids_verified_at' => now(),
        ]);
        $plainToken = Str::random(80);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'beneficiary-lifecycle-token',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        return [$user, $provider, $plainToken];
    }

    private function beneficiaryPayload(IntegrationProvider $provider): array
    {
        return [
            'provider_id' => $provider->id,
            ...$this->beneficiaryAttributes($provider),
        ];
    }

    private function beneficiaryAttributes(IntegrationProvider $provider): array
    {
        return [
            'provider_id' => $provider->id,
            'beneficiary_type' => 'business',
            'full_name' => 'Verified Corporate Beneficiary',
            'country_code' => 'HK',
            'currency' => 'USD',
            'bank_name' => 'Verified Bank',
            'account_number' => '123456789',
            'swift_bic' => 'HKBCHKHH',
            'address_line1' => '1 Beneficiary Road',
            'city' => 'Hong Kong',
            'postal_code' => '999077',
            'raw_data' => ['nium' => ['payoutMethod' => 'SWIFT']],
            'status' => 'pending',
        ];
    }
}
