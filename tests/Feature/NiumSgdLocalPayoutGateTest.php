<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\Balance;
use App\Models\Beneficiary;
use App\Models\FxQuote;
use App\Models\IntegrationProvider;
use App\Models\NiumApiExchangeEvidence;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Nium\NiumBeneficiaryPayoutMethodResolver;
use App\Services\Nium\NiumPayoutFxLockContract;
use App\Services\Nium\NiumPayoutFxLockService;
use App\Services\Nium\NiumSgdLocalCugScope;
use App\Services\Nium\NiumSgdLocalPayoutAmounts;
use App\Services\Nium\NiumSgdLocalPayoutAuthorization;
use App\Services\Nium\NiumSgdLocalPayoutGate;
use App\Services\Nium\NiumSgdLocalRemittancePayload;
use App\Services\Nium\NiumTransferPolicy;
use App\Services\Nium\NiumTransferService;
use App\Services\Wallet\TransferApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class NiumSgdLocalPayoutGateTest extends TestCase
{
    use RefreshDatabase;

    private function authorizedChecker(): User
    {
        $checker = User::factory()->create(['status' => 'active']);
        $checker->roles()->create(['role_code' => 'admin']);

        return $checker;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('services.nium.base_url', 'https://nium.test');
        config()->set('services.nium.client_id', 'client-test');
        config()->set('services.nium.beneficiary_endpoint', '/api/v2/client/{clientHashId}/customer/{customerHashId}/beneficiaries');
        config()->set('services.nium.auth', ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'test-only']);
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'test-only');
        config()->set('services.nium.supported_corridors', []);
        config()->set('wallet.transfer_controls.require_admin_approval', false);
    }

    private function corridor(array $overrides = []): array
    {
        return array_replace([
            'destinationCountry' => 'SG', 'destinationCurrency' => 'SGD', 'payoutMethod' => 'LOCAL',
            'beneficiaryAccountType' => 'INDIVIDUAL', 'customerType' => 'CORPORATE', 'routingCodeType' => 'SWIFT',
        ], $overrides);
    }

    private function candidate(int $beneficiaryId = 9): array
    {
        $provider = IntegrationProvider::query()->create(['code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        $user = User::factory()->create(['kyc_status' => 'verified']);
        // No general profile: approved KYC, not a user-editable profile, identifies Corporate.
        $user->kycProfile()->create([
            'status' => 'approved', 'applicant_type' => 'business', 'registered_country_code' => 'HK',
            'country_code' => 'HK', 'metadata' => ['nium_region' => 'HK'],
            'legal_name' => 'Test HK Corporate', 'address_line1' => '1 Test Road',
            'city' => 'Hong Kong', 'postal_code' => '999077',
        ]);
        $user->providerAccounts()->create([
            'provider_id' => $provider->id, 'external_customer_id' => 'customer-test', 'external_account_id' => 'wallet-test',
            'status' => 'active', 'provider_status' => 'clear', 'reconciliation_status' => 'reconciled',
            'customer_id_verified_at' => now(), 'wallet_id_verified_at' => now(), 'provider_ids_verified_at' => now(),
        ]);
        $beneficiary = Beneficiary::query()->forceCreate([
            'id' => $beneficiaryId,
            'user_id' => $user->id, 'provider_id' => $provider->id, 'external_beneficiary_id' => 'dynamic-provider-beneficiary',
            'beneficiary_type' => 'personal', 'full_name' => 'Test Individual', 'country_code' => 'SG', 'currency' => 'SGD',
            'payout_method' => 'LOCAL', 'swift_bic' => 'DBSSSGSGXXX', 'status' => 'active',
        ]);
        $response = json_encode(['beneficiaryHashId' => $beneficiary->external_beneficiary_id, 'status' => 'ACTIVE']);
        $log = ApiRequestLog::query()->create([
            'provider_id' => $provider->id, 'user_id' => $user->id, 'operation' => 'beneficiary_create',
            'external_reference' => (string) $beneficiary->id, 'client_hash_id' => 'client-test',
            'request_method' => 'POST', 'request_url' => 'https://nium.test/api/v2/client/[REDACTED]/customer/[REDACTED]/beneficiaries',
            'request_body' => ['payout_method' => 'LOCAL'], 'response_status' => 200, 'is_success' => true,
            'response_body' => ['beneficiary_id' => $beneficiary->external_beneficiary_id],
        ]);
        // Synthetic encrypted audit evidence; no provider request is made.
        NiumApiExchangeEvidence::query()->create([
            'api_request_log_id' => $log->id, 'request_method' => 'POST', 'request_url' => $log->request_url,
            'raw_response_body' => $response, 'response_body_sha256' => hash('sha256', $response),
            'response_received' => true, 'capture_version' => 1,
        ]);

        return [$user, $provider, $beneficiary];
    }

    public static function invalidRegistrations(): array
    {
        return [
            'missing audit' => ['missing'], 'wrong client' => ['client'], 'wrong endpoint URL' => ['url'],
            'HTTP failure' => ['http'], 'missing raw evidence' => ['evidence'], 'no response' => ['unknown'],
            'incomplete status-less response' => ['status'], 'pending status' => ['pending'], 'different external ID' => ['id'],
            'tampered response' => ['hash'], 'invalid JSON' => ['json'], 'wrong method' => ['method'],
        ];
    }

    #[DataProvider('invalidRegistrations')]
    public function test_registration_must_have_bound_successful_provider_evidence(string $failure): void
    {
        [$user, $provider, $beneficiary] = $this->candidate();
        $log = ApiRequestLog::query()->sole();
        $evidence = $log->niumExchangeEvidence;
        $body = ['beneficiaryHashId' => $beneficiary->external_beneficiary_id, 'status' => 'ACTIVE'];
        match ($failure) {
            'missing' => $log->delete(),
            'client' => $log->update(['client_hash_id' => 'different-client']),
            'url' => $log->update(['request_url' => 'https://nium.test/another-customer/beneficiaries']),
            'http' => $log->update(['response_status' => 400, 'is_success' => false]),
            'evidence' => $evidence->delete(),
            'unknown' => $evidence->update(['response_received' => false]),
            'status' => $body = ['beneficiaryHashId' => $beneficiary->external_beneficiary_id],
            'pending' => $body['status'] = 'PENDING',
            'id' => $body['beneficiaryHashId'] = 'another-beneficiary',
            'hash' => $evidence->update(['response_body_sha256' => 'incorrect']),
            'json' => $evidence->update(['raw_response_body' => '{']),
            'method' => $log->update(['request_body' => ['payout_method' => 'SWIFT']]),
        };
        if (in_array($failure, ['status', 'pending', 'id'], true)) {
            $raw = json_encode($body);
            $evidence->update(['raw_response_body' => $raw, 'response_body_sha256' => hash('sha256', $raw)]);
        }
        // Client metadata cannot replace provider evidence.
        $beneficiary->raw_data = ['provider_status' => 'ACTIVE', 'nium' => ['provider_verified' => ['payout_method' => 'LOCAL']]];
        Http::fake();
        $this->expectException(RuntimeException::class);
        try {
            app(NiumSgdLocalPayoutGate::class)->assertCandidate($user, $provider, $beneficiary, 'SGD');
        } finally {
            Http::assertNothingSent();
            $this->assertNoMoneyMovement();
        }
    }

    private function statuslessRegistration(Beneficiary $beneficiary): array
    {
        return ['beneficiaryHashId' => $beneficiary->external_beneficiary_id, 'destinationCountry' => 'SG',
            'destinationCurrency' => 'SGD', 'payoutMethod' => 'LOCAL', 'payoutHashId' => 'synthetic-local-payout-id'];
    }

    private function registrationResponse(ApiRequestLog $log, array $body): void
    {
        $raw = json_encode($body, JSON_THROW_ON_ERROR);
        $log->niumExchangeEvidence->update(['raw_response_body' => $raw, 'response_body_sha256' => hash('sha256', $raw)]);
    }

    public static function verifiedRegistrationSchemas(): array
    {
        return [['root', false], ['data', false], ['beneficiary', false], ['root', true]];
    }

    #[DataProvider('verifiedRegistrationSchemas')]
    public function test_verified_statusless_registration_enables_preparation_without_provider_requests(string $wrapper, bool $activeStatus): void
    {
        [, , $beneficiary] = $this->candidate();
        $log = ApiRequestLog::query()->sole();
        $body = $this->statuslessRegistration($beneficiary);
        if ($activeStatus) {
            $body['status'] = 'ACTIVE';
        }
        $this->registrationResponse($log, $wrapper === 'root' ? $body : [$wrapper => $body]);
        $registration = app(NiumBeneficiaryPayoutMethodResolver::class)->successfulRequestEvidence($beneficiary);
        $this->assertSame($log->id, $registration?->apiRequestLogId);
        $this->assertSame('LOCAL', $registration?->payoutMethod);
        $this->assertSame($beneficiary->external_beneficiary_id, $registration?->externalBeneficiaryId);
        Http::fake();
        $this->assertTrue(app(NiumSgdLocalCugScope::class)->canPrepare($beneficiary));
        $this->assertTrue(NiumSgdLocalPayoutGate::admission($beneficiary)['preparation_allowed']);
        $this->assertFalse(NiumSgdLocalPayoutGate::admission($beneficiary)['allowed']);
        Http::assertNothingSent();
        $this->assertNoMoneyMovement();
    }

    public static function invalidStatuslessRegistrations(): array
    {
        return [
            'external ID' => ['beneficiaryHashId', 'different-beneficiary'],
            'conflicting ID alias' => ['id', 'different-beneficiary'],
            'country' => ['destinationCountry', 'HK'],
            'currency' => ['destinationCurrency', 'USD'],
            'method' => ['payoutMethod', 'SWIFT'],
            'missing country' => ['destinationCountry', null],
            'missing currency' => ['destinationCurrency', null],
            'missing method' => ['payoutMethod', null],
            'missing payout ID' => ['payoutHashId', null],
            'blank payout ID' => ['payoutHashId', ' '],
            'inactive' => ['status', 'INACTIVE'],
            'rejected' => ['status', 'REJECTED'],
            'unknown' => ['status', 'UNKNOWN'],
            'pending' => ['status', 'PENDING'],
            'blank status' => ['status', ''],
            'null status' => ['status', null],
        ];
    }

    #[DataProvider('invalidStatuslessRegistrations')]
    public function test_statusless_schema_rejects_mismatched_corridor_identity_and_explicit_nonactive_status(string $field, mixed $value): void
    {
        [$user, $provider, $beneficiary] = $this->candidate();
        $body = $this->statuslessRegistration($beneficiary);
        $body[$field] = $value;
        $this->registrationResponse(ApiRequestLog::query()->sole(), $body);
        Http::fake();
        $this->assertFalse(app(NiumSgdLocalCugScope::class)->canPrepare($beneficiary));
        $this->expectException(RuntimeException::class);
        try {
            app(NiumSgdLocalPayoutGate::class)->assertLocalCandidate($user, $provider, $beneficiary, 'SGD');
        } finally {
            Http::assertNothingSent();
        }
    }

    public static function forgedRegistrationBindings(): array
    {
        return array_map(fn ($failure) => [$failure], ['response hash', 'request capture method', 'request capture URL',
            'capture version', 'request LOCAL method', 'normalized response ID', 'customer owner', 'provider owner',
            'registration reference', 'request operation', 'request HTTP method', 'older evidence fallback']);
    }

    #[DataProvider('forgedRegistrationBindings')]
    public function test_statusless_response_cannot_bypass_registration_evidence_or_request_binding(string $failure): void
    {
        [$user, $provider, $beneficiary] = $this->candidate();
        $log = ApiRequestLog::query()->sole();
        if ($failure === 'older evidence fallback') {
            $copy = $log->replicate();
            $copy->response_body = ['beneficiary_id' => 'different-beneficiary'];
            $copy->save();
            $capture = $log->niumExchangeEvidence->replicate();
            $capture->api_request_log_id = $copy->id;
            $capture->save();
            $log = $copy;
        }
        $this->registrationResponse($log, $this->statuslessRegistration($beneficiary));
        match ($failure) {
            'response hash' => $log->niumExchangeEvidence->update(['response_body_sha256' => 'forged']),
            'request capture method' => $log->niumExchangeEvidence->update(['request_method' => 'GET']),
            'request capture URL' => $log->niumExchangeEvidence->update(['request_url' => 'https://another-origin.test/beneficiaries']),
            'capture version' => $log->niumExchangeEvidence->update(['capture_version' => 99]),
            'request LOCAL method' => $log->update(['request_body' => ['payout_method' => 'SWIFT']]),
            'normalized response ID' => $log->update(['response_body' => ['beneficiary_id' => 'different-beneficiary']]),
            'customer owner' => $log->update(['user_id' => null]),
            'provider owner' => $log->update(['provider_id' => IntegrationProvider::query()->create([
                'code' => 'other-provider', 'name' => 'Other provider', 'status' => 'active',
            ])->id]),
            'registration reference' => $log->update(['external_reference' => 'another-local-beneficiary']),
            'request operation' => $log->update(['operation' => 'beneficiary_list']),
            'request HTTP method' => $log->update(['request_method' => 'GET']),
            'older evidence fallback' => null,
        };
        if ($failure === 'older evidence fallback') {
            $registration = app(NiumBeneficiaryPayoutMethodResolver::class)->successfulRequestEvidence($beneficiary);
            $this->assertNotSame($log->id, $registration?->apiRequestLogId);
            $this->assertSame('LOCAL', $registration?->payoutMethod);
        }
        Http::fake();
        $this->expectException(RuntimeException::class);
        try {
            app(NiumSgdLocalPayoutGate::class)->assertLocalCandidate($user, $provider, $beneficiary, 'SGD');
        } finally {
            Http::assertNothingSent();
            $this->assertNoMoneyMovement();
        }
    }

    public static function registeredBeneficiaryIds(): array
    {
        return ['verified beneficiary 9' => [9], 'another registered beneficiary' => [37]];
    }

    #[DataProvider('registeredBeneficiaryIds')]
    public function test_verified_hk_corporate_to_sg_individual_matches_exact_provider_corridor(int $beneficiaryId): void
    {
        [$user, $provider, $beneficiary] = $this->candidate($beneficiaryId);
        $this->assertSame($beneficiaryId, $beneficiary->id);
        Http::fake(['*supportedCorridors*' => Http::response(['content' => [$this->corridor()]])]);
        $match = app(NiumSgdLocalPayoutGate::class)->assertCandidate($user, $provider, $beneficiary, 'SGD');
        $this->assertSame($this->corridor(), $match);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request['customerType'] === 'CORPORATE'
            && $request['beneficiaryAccountType'] === 'INDIVIDUAL'
            && $request['payoutMethod'] === 'LOCAL'
            && $request['destinationCountry'] === 'SG'
            && $request['destinationCurrency'] === 'SGD');
        $this->assertNoMoneyMovement();
    }

    public static function invalidCandidates(): array
    {
        return [
            'inactive provider' => ['provider', ['status' => 'inactive']],
            'wrong provider rail' => ['provider', ['code' => 'another-provider']],
            'inactive' => ['beneficiary', ['status' => 'inactive']],
            'missing provider ID' => ['beneficiary', ['external_beneficiary_id' => null]],
            'blank provider ID' => ['beneficiary', ['external_beneficiary_id' => ' ']],
            'cross user' => ['beneficiary', ['user_id' => 999]],
            'cross provider' => ['beneficiary', ['provider_id' => 999]],
            'corporate beneficiary' => ['beneficiary', ['beneficiary_type' => 'business']],
            'unknown beneficiary type' => ['beneficiary', ['beneficiary_type' => 'unknown']],
            'wrong country' => ['beneficiary', ['country_code' => 'US']],
            'wrong currency' => ['beneficiary', ['currency' => 'USD']],
            'wrong method' => ['beneficiary', ['payout_method' => 'SWIFT']],
            'unverified method' => ['beneficiary', ['payout_method' => null, 'raw_data' => ['nium' => ['payoutMethod' => 'LOCAL']]]],
            'missing routing' => ['beneficiary', ['swift_bic' => null]],
            'malformed routing' => ['beneficiary', ['swift_bic' => '12345']],
            'individual sender' => ['kyc', ['applicant_type' => 'individual']],
            'unapproved sender' => ['kyc', ['status' => 'pending']],
            'non HK sender' => ['kyc', ['registered_country_code' => 'SG', 'country_code' => 'SG', 'metadata' => []]],
            'blocked account' => ['account', ['provider_status' => 'blocked']],
            'unreconciled account' => ['account', ['reconciliation_status' => 'pending']],
            'missing customer' => ['account', ['external_customer_id' => null]],
            'missing wallet' => ['account', ['external_account_id' => null]],
            'unverified wallet' => ['account', ['wallet_id_verified_at' => null]],
        ];
    }

    #[DataProvider('invalidCandidates')]
    public function test_invalid_candidate_fails_before_corridor_or_money_movement(string $model, array $attributes): void
    {
        [$user, $provider, $beneficiary] = $this->candidate();
        match ($model) {
            'beneficiary' => $beneficiary->forceFill($attributes),
            'provider' => $provider->forceFill($attributes),
            'kyc' => $user->kycProfile->forceFill($attributes),
            'account' => $user->providerAccounts()->firstOrFail()->update($attributes),
        };
        if ($model === 'beneficiary' && array_key_exists('payout_method', $attributes) && $attributes['payout_method'] === null) {
            ApiRequestLog::query()->delete();
        }
        Http::fake();
        try {
            app(NiumSgdLocalPayoutGate::class)->assertCandidate($user, $provider, $beneficiary, 'SGD');
            $this->fail('Invalid candidate must be rejected.');
        } catch (RuntimeException) {
            Http::assertNothingSent();
            $this->assertNoMoneyMovement();
        }
    }

    public static function unsupportedCorridors(): array
    {
        return [
            'lookup failure' => [503, null],
            'no match' => [200, null],
            'different country' => [200, ['destinationCountry' => 'HK']],
            'different currency' => [200, ['destinationCurrency' => 'USD']],
            'different method' => [200, ['payoutMethod' => 'SWIFT']],
            'different customer type' => [200, ['customerType' => 'INDIVIDUAL']],
            'different beneficiary type' => [200, ['beneficiaryAccountType' => 'CORPORATE']],
            'different routing' => [200, ['routingCodeType' => 'BANK CODE']],
            'missing routing evidence' => [200, ['routingCodeType' => []]],
        ];
    }

    #[DataProvider('unsupportedCorridors')]
    public function test_corridor_failures_fail_closed(int $status, ?array $overrides): void
    {
        [$user, $provider, $beneficiary] = $this->candidate();
        Http::fake(['*supportedCorridors*' => Http::response(['content' => $overrides === null ? [] : [$this->corridor($overrides)]], $status)]);
        $this->expectException(RuntimeException::class);
        try {
            app(NiumSgdLocalPayoutGate::class)->assertCandidate($user, $provider, $beneficiary, 'SGD');
        } finally {
            $this->assertNoMoneyMovement();
        }
    }

    public function test_transfer_currency_must_match_beneficiary_before_lookup(): void
    {
        [$user, $provider, $beneficiary] = $this->candidate();
        Http::fake();
        $this->expectException(RuntimeException::class);
        try {
            app(NiumSgdLocalPayoutGate::class)->assertCandidate($user, $provider, $beneficiary, 'USD');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_global_fx_flag_cannot_bypass_route_hold_for_creation_payload_submission_or_direct_lock(): void
    {
        [$user, $provider, $beneficiary] = $this->candidate();
        Balance::query()->create([
            'user_id' => $user->id, 'provider_id' => $provider->id, 'currency' => 'USD',
            'available_balance' => 1000, 'ledger_balance' => 1000, 'as_of' => now(),
        ]);
        $transfer = Transfer::query()->create([
            'transfer_no' => 'SG-TEST', 'user_id' => $user->id, 'provider_id' => $provider->id,
            'beneficiary_id' => $beneficiary->id, 'transfer_type' => 'payout', 'source_currency' => 'USD', 'target_currency' => 'SGD',
            'source_amount' => 1, 'fee_amount' => 0, 'fee_currency' => 'USD', 'status' => 'draft',
            'purpose_code' => 'TEST_PURPOSE', 'reference_text' => 'Test reference',
        ]);
        $purposes = [['code' => 'TEST_PURPOSE', 'label' => 'Mock only, not an approved CUG purpose']];
        foreach ([false, true] as $fxEnabled) {
            config()->set('services.nium.payout_fx_enabled', $fxEnabled);
            Http::fake([
                '*supportedCorridors*' => Http::response(['content' => [$this->corridor()]]),
                '*purposeCodes' => Http::response([['purposeCode' => 'TEST_PURPOSE', 'description' => 'Mock only']]),
            ]);
            $policy = app(NiumTransferPolicy::class);
            foreach ([
                fn () => $policy->normalizeCreate($transfer->toArray(), $user, $provider, $beneficiary, $purposes),
                fn () => $policy->providerPayload($transfer->fresh(['user', 'provider', 'beneficiary']), $purposes),
                fn () => app(NiumTransferService::class)->submitTransfer($provider, $transfer),
                fn () => app(ProviderTransferManager::class)->submitTransfer($provider, $transfer->fresh()),
                fn () => app(NiumPayoutFxLockService::class)->createLock($provider, $user, 'USD', 'SGD', 1),
            ] as $operation) {
                try {
                    $operation();
                    $this->fail('SGD route must stay closed even with the global FX flag enabled.');
                } catch (RuntimeException $exception) {
                    $this->assertTrue(str_contains($exception->getMessage(), 'not enabled')
                        || $exception->getMessage() === NiumSgdLocalPayoutGate::BLOCK_REASON
                        || str_contains($exception->getMessage(), 'requires Admin Approval'), $exception->getMessage());
                }
            }
            $this->assertSame('draft', $transfer->fresh()->status);
            $this->assertSame('1000.00000000', Balance::query()->sole()->available_balance);
            $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
            $this->assertNoMoneyMovement();
        }
    }

    public function test_five_usd_controlled_request_without_a_current_lock_cannot_be_approved(): void
    {
        // Synthetic local fixture based on the proposed amount and observed
        // balance. This is not a current Production balance or provider response.
        [$user, $provider, $beneficiary] = $this->candidate(9);
        config()->set('services.nium.payout_fx_enabled', true);
        config()->set('wallet.transfer_controls.require_admin_approval', true);
        config()->set('wallet.transfer_controls.approval_threshold_amount', 0);
        Balance::query()->create([
            'user_id' => $user->id, 'provider_id' => $provider->id, 'currency' => 'USD',
            'available_balance' => 85, 'ledger_balance' => 85, 'as_of' => now(),
        ]);
        $transfer = Transfer::query()->create([
            'transfer_no' => 'SG-FIVE-USD', 'user_id' => $user->id, 'provider_id' => $provider->id,
            'beneficiary_id' => $beneficiary->id, 'transfer_type' => 'payout',
            'source_currency' => 'USD', 'target_currency' => 'SGD', 'source_amount' => '5.00',
            'fee_amount' => 0, 'fee_currency' => 'USD', 'status' => 'approval_required',
            'purpose_code' => 'TEST_PURPOSE', 'reference_text' => 'Synthetic controlled request',
            'client_reference' => 'OW-synthetic-five-usd',
        ]);
        Http::fake([
            '*supportedCorridors*' => Http::response(['content' => [$this->corridor()]]),
            '*purposeCodes' => Http::response([['purposeCode' => 'TEST_PURPOSE', 'description' => 'Mock only, not a CUG purpose']]),
        ]);
        try {
            app(TransferApprovalService::class)->approve(
                $transfer, $this->authorizedChecker(), null, app(ProviderTransferManager::class),
            );
            $this->fail('Admin approval must not authorize an unverified USD/SGD contract.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('requires a fresh attached FX lock', $exception->getMessage());
        }
        $this->assertSame('approval_required', $transfer->fresh()->status);
        $this->assertSame('5.00000000', $transfer->fresh()->source_amount);
        $this->assertSame(9, $transfer->fresh()->beneficiary_id);
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->assertSame('85.00000000', Balance::query()->sole()->available_balance);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertDatabaseCount('beneficiaries', 1);
        $this->assertDatabaseCount('bank_accounts', 0);
        $this->assertNoMoneyMovement();
    }

    private function lockedFiveDollarCandidate(int $beneficiaryId = 37): Transfer
    {
        [$user, $provider, $beneficiary] = $this->candidate($beneficiaryId);
        Http::fake([
            '*supportedCorridors*' => Http::response(['content' => [$this->corridor()]]),
            '*purposeCodes' => Http::response([['purposeCode' => 'TEST_PURPOSE', 'description' => 'Synthetic only']]),
        ]);
        $contract = NiumPayoutFxLockContract::parse([
            'audit_id' => 4242001, 'fx_hold_id' => 'synthetic-only', 'fx_rate' => '1.275939120',
            'source_currency' => 'USD', 'destination_currency' => 'SGD', 'status' => 'ACTIVE',
            'hold_expiry_at' => now()->addMinutes(10)->toISOString(),
        ], 'USD', 'SGD', '5');
        $quote = FxQuote::query()->create([
            'user_id' => $user->id, 'provider_id' => $provider->id, 'quote_ref' => '4242001',
            'source_currency' => 'USD', 'target_currency' => 'SGD', 'source_amount' => '5',
            'target_amount' => '6.37969560', 'net_rate' => $contract['rate'], 'fee_amount' => 0,
            'expires_at' => $contract['expires_at'],
            'raw_data' => [
                'provider_fx_type' => 'payout_fx_lock', 'audit_id' => '4242001',
                'provider_context' => app(NiumPayoutFxLockService::class)->context($provider, $user),
                'payout_fx_lock_contract' => $contract, 'provider_fee_status' => 'unavailable',
            ],
        ]);

        return Transfer::query()->create([
            'transfer_no' => 'SG-CANDIDATE', 'user_id' => $user->id, 'provider_id' => $provider->id,
            'beneficiary_id' => $beneficiary->id, 'transfer_type' => 'payout', 'source_currency' => 'USD',
            'target_currency' => 'SGD', 'source_amount' => '5', 'fee_amount' => 0, 'fee_currency' => 'USD',
            'fx_quote_id' => $quote->id, 'fx_rate' => $quote->net_rate, 'status' => 'approval_required',
            'purpose_code' => 'TEST_PURPOSE', 'reference_text' => 'Synthetic review only',
        ])->fresh(['user', 'provider', 'beneficiary', 'fxQuote']);
    }

    #[DataProvider('registeredBeneficiaryIds')]
    public function test_source_funded_candidate_uses_exact_external_id_integer_audit_and_no_swift_fields(int $beneficiaryId): void
    {
        $transfer = $this->lockedFiveDollarCandidate($beneficiaryId);
        $payload = app(NiumSgdLocalRemittancePayload::class)->forReview($transfer, [['code' => 'TEST_PURPOSE']]);
        $this->assertSame([
            'beneficiary' => ['id' => 'dynamic-provider-beneficiary'],
            'payout' => ['sourceAmount' => 5, 'sourceCurrency' => 'USD', 'auditId' => 4242001],
            'purposeCode' => 'TEST_PURPOSE', 'sourceOfFunds' => 'Corporate Account',
        ], $payload);
        $this->assertIsInt(json_decode(json_encode($payload), true)['payout']['auditId']);
        $this->assertSame('1.275939120', $transfer->fxQuote->raw_data['payout_fx_lock_contract']['rate']);
        $amounts = app(NiumSgdLocalPayoutAmounts::class)->forTransfer($transfer);
        $this->assertSame('1.275939120', $amounts['fx_rate']);
        $this->assertNull($amounts['transfer_fee']);
        $this->assertNull($amounts['total_wallet_debit']);
        $this->assertNull($amounts['conversion_amount']);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' || str_contains($request->url(), '/lockExchangeRate'));
    }

    public static function invalidReviewPurposes(): array
    {
        return ['missing' => [''], 'unsupported' => ['UNSUPPORTED']];
    }

    #[DataProvider('invalidReviewPurposes')]
    public function test_review_candidate_rejects_missing_or_unsupported_purpose(string $purpose): void
    {
        $transfer = $this->lockedFiveDollarCandidate();
        $transfer->purpose_code = $purpose;
        $this->expectException(RuntimeException::class);
        app(NiumSgdLocalRemittancePayload::class)->forReview($transfer, [['code' => 'TEST_PURPOSE']]);
    }

    public function test_expired_sgd_lock_rejects_approval_without_relocking_or_saving_approval(): void
    {
        $transfer = $this->lockedFiveDollarCandidate();
        $transfer->fxQuote->update(['expires_at' => now()->subSecond()]);
        try {
            $this->syntheticApproval($transfer);
            $this->fail('An expired SGD hold must reject approval itself.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('expiry', $exception->getMessage());
        }
        $this->assertSame('approval_required', $transfer->fresh()->status);
        $this->assertDatabaseCount('transfer_approvals', 0);
        Http::assertNothingSent();
    }

    public function test_valid_sgd_lock_cannot_persist_approval_without_financial_admission(): void
    {
        $transfer = $this->lockedFiveDollarCandidate();
        $this->expectExceptionMessage(NiumSgdLocalPayoutGate::BLOCK_REASON);
        try {
            app(TransferApprovalService::class)->approve($transfer, $this->authorizedChecker());
        } finally {
            $this->assertDatabaseCount('transfer_approvals', 0);
            Http::assertNothingSent();
        }
    }

    /** Synthetic local history for authorization-tamper regressions; never provider fee evidence. */
    private function syntheticApproval(Transfer $transfer, ?User $approver = null): Transfer
    {
        $transfer->raw_data = array_merge($transfer->raw_data ?? [], [
            'sgd_local_approval' => app(NiumSgdLocalPayoutAuthorization::class)->snapshot($transfer),
        ]);
        $transfer->status = 'approved';
        $transfer->save();
        $transfer->approvals()->create(['approver_user_id' => ($approver ?? User::factory()->create())->id, 'action' => 'approved']);

        return $transfer->fresh(['provider', 'user', 'beneficiary', 'fxQuote']);
    }

    public function test_repeating_approval_after_expiry_does_not_refresh_the_hold_or_approval(): void
    {
        $transfer = $this->lockedFiveDollarCandidate();
        $approver = User::factory()->create();
        $approved = $this->syntheticApproval($transfer, $approver);
        $approved->fxQuote()->update(['expires_at' => now()->subSecond()]);
        $this->expectException(RuntimeException::class);
        try {
            app(TransferApprovalService::class)->approve($approved, $approver);
        } finally {
            $this->assertDatabaseCount('transfer_approvals', 1);
            Http::assertNothingSent();
        }
    }

    public static function changedApprovedFields(): array
    {
        return [
            'purpose' => ['purpose_code', 'CHANGED'], 'principal' => ['source_amount', '6'],
            'source currency' => ['source_currency', 'SGD'], 'rate' => ['fx_rate', '1.2'],
            'external beneficiary' => ['external_beneficiary_id', 'different-external-id'],
        ];
    }

    #[DataProvider('changedApprovedFields')]
    public function test_approval_does_not_authorize_changed_payment_fields(string $field, string $value): void
    {
        $transfer = $this->lockedFiveDollarCandidate();
        $approved = $this->syntheticApproval($transfer);
        if ($field === 'external_beneficiary_id') {
            $approved->beneficiary->external_beneficiary_id = $value;
        } else {
            $approved->$field = $value;
        }
        $this->expectException(RuntimeException::class);
        app(NiumSgdLocalPayoutAuthorization::class)->assertApproved($approved);
    }

    public function test_synthetic_approval_cannot_consume_an_audit_without_reviewed_financial_evidence(): void
    {
        $transfer = $this->lockedFiveDollarCandidate();
        $approved = $this->syntheticApproval($transfer);
        $this->expectExceptionMessage(NiumSgdLocalPayoutGate::BLOCK_REASON);
        try {
            DB::transaction(fn () => app(NiumPayoutFxLockService::class)->consumeForSubmission($approved, 'synthetic-operation'));
        } finally {
            $this->assertArrayNotHasKey('consumed_at', $approved->fxQuote->fresh()->raw_data);
            Http::assertNothingSent();
        }
    }

    public function test_duplicate_audit_without_financial_evidence_cannot_be_consumed(): void
    {
        $transfer = $this->lockedFiveDollarCandidate();
        $approved = $this->syntheticApproval($transfer);
        $copy = $approved->replicate();
        $copy->transfer_no = 'SG-DUPLICATE';
        $copy->save();
        $this->expectExceptionMessage(NiumSgdLocalPayoutGate::BLOCK_REASON);
        DB::transaction(fn () => app(NiumPayoutFxLockService::class)->consumeForSubmission($approved, 'synthetic-operation'));
    }

    private function assertNoMoneyMovement(): void
    {
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' || str_contains($request->url(), '/lockExchangeRate'));
        $this->assertSame(0, FxQuote::query()->count());
    }
}
