<?php

namespace Tests\Concerns;

use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Models\Balance;
use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\NiumApiExchangeEvidence;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Nium\NiumPayoutQuoteContract;
use App\Services\Nium\NiumRejectedPayoutQuoteRecovery;
use App\Services\Nium\NiumSgdLocalCugScope;
use App\Services\Nium\NiumSgdLocalZeroFeePolicy;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/** Synthetic recreation of the hotfix incident; never reads a real provider artifact. */
trait CreatesRejectedSgdQuoteIncident
{
    protected string $rejectedProviderArtifact = 'SYNTHETIC-PROVIDER-CLEARANCE-NOT-PRODUCTION';

    protected string $sgdFenceDirectory;

    protected function rejectedIncident(): array
    {
        $this->freezeTime();
        $this->sgdFenceDirectory = sys_get_temp_dir().'/sgd-rejected-quote-'.Str::uuid();
        mkdir($this->sgdFenceDirectory, 0700);
        file_put_contents($this->sgdFenceDirectory.'/drain.lock', '');
        file_put_contents($this->sgdFenceDirectory.'/state', "CLOSED v1\n");
        $this->beforeApplicationDestroyed(function (): void {
            unlink($this->sgdFenceDirectory.'/state');
            unlink($this->sgdFenceDirectory.'/drain.lock');
            rmdir($this->sgdFenceDirectory);
        });
        config([
            'sgd_admission.directory' => $this->sgdFenceDirectory,
            'services.nium.base_url' => 'https://nium.test',
            'services.nium.client_id' => 'synthetic-client',
            'services.nium.auth' => ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'synthetic-only'],
            'services.nium.webhook.static_header_name' => 'x-partner-key',
            'services.nium.webhook.static_header_value' => 'synthetic-only',
            'services.nium.health_endpoint' => '/api/v1/client/{clientHashId}',
            'services.nium.customer_create_endpoint' => '/api/v1/client/{clientHashId}/customer',
            'services.nium.customer_list_endpoint' => '/api/v1/client/{clientHashId}/customers',
            'services.nium.customer_get_endpoint' => '/api/v1/client/{clientHashId}/customer/{customerHashId}',
            'services.nium.quote_endpoint' => '/api/v1/client/{clientHashId}/quotes',
            'services.nium.beneficiary_validation_schema_endpoint' => '/validationSchemas',
            'services.nium.payout_fx_enabled' => true,
            'services.nium.supported_corridors' => [[
                'destinationCountry' => 'SG', 'destinationCurrency' => 'SGD', 'payoutMethod' => 'LOCAL',
                'beneficiaryAccountType' => 'INDIVIDUAL', 'customerType' => 'CORPORATE', 'routingCodeType' => 'SWIFT',
            ]],
            'services.nium.sgd_local_zero_fee.enabled' => false,
            'wallet.ledger.enabled' => true,
        ]);
        Http::swap(new Factory(app('events')));
        Http::preventStrayRequests();

        IntegrationProvider::query()->forceCreate(['id' => 10, 'code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        $maker = User::factory()->create(['id' => 12, 'status' => 'active', 'kyc_status' => 'verified']);
        $maker->profile()->create(['user_type' => 'business', 'country_code' => 'HK']);
        $maker->kycProfile()->create([
            'status' => 'approved', 'applicant_type' => 'business', 'registered_country_code' => 'HK',
            'country_code' => 'HK', 'legal_name' => 'Synthetic Corporate', 'address_line1' => 'Synthetic Road', 'city' => 'Hong Kong',
        ]);
        $maker->providerAccounts()->forceCreate([
            'id' => 6, 'user_id' => $maker->id, 'provider_id' => 10,
            'external_customer_id' => 'synthetic-customer', 'external_account_id' => 'synthetic-wallet',
            'status' => 'active', 'provider_status' => 'clear', 'reconciliation_status' => 'reconciled',
            'customer_id_verified_at' => now(), 'wallet_id_verified_at' => now(), 'provider_ids_verified_at' => now(),
        ]);
        Beneficiary::query()->forceCreate([
            'id' => 9, 'user_id' => 12, 'provider_id' => 10, 'external_beneficiary_id' => 'synthetic-local-beneficiary',
            'beneficiary_type' => 'individual', 'full_name' => 'Synthetic Individual', 'country_code' => 'SG', 'currency' => 'SGD',
            'payout_method' => 'LOCAL', 'swift_bic' => 'DBSSSGSG', 'account_number' => '123456789', 'status' => 'active',
        ]);
        Balance::query()->create([
            'user_id' => 12, 'provider_id' => 10, 'external_account_id' => 'synthetic-wallet', 'currency' => 'USD',
            'available_balance' => 85, 'ledger_balance' => 85, 'reserved_balance' => 0, 'as_of' => now(),
        ]);
        // The incident maker has an explicit ID, which does not advance PostgreSQL's
        // sequence. RefreshDatabase rolls rows back between datasets, not nextval().
        // Allocate from this isolated fixture's rows before any workers are forked.
        $operator = User::factory()->create(['id' => User::query()->max('id') + 1, 'status' => 'active']);
        $operator->roles()->create(['role_code' => 'admin']);
        $context = ['client' => 'synthetic-client', 'customer' => 'synthetic-customer',
            'wallet' => 'synthetic-wallet', 'base_url' => 'https://nium.test'];
        $intent = [
            'maker_user_id' => 12, 'provider_id' => 10, 'beneficiary_id' => 9,
            'external_beneficiary_id' => 'synthetic-local-beneficiary', 'provider_context' => $context,
            'source_currency' => 'USD', 'target_currency' => 'SGD', 'source_amount' => '5',
            'purpose_code' => 'IR008', 'expected_fee_amount' => '0', 'expected_fee_currency' => 'USD',
        ];
        // Older synthetic fixture rows must precede the original request's audit window.
        $this->travel(2)->seconds();
        $transfer = Transfer::query()->forceCreate([
            'id' => 5, 'user_id' => 12, 'provider_id' => 10, 'beneficiary_id' => 9, 'transfer_no' => 'TRF-SYNTHETIC-INCIDENT',
            'transfer_type' => 'payout', 'source_currency' => 'USD', 'target_currency' => 'SGD', 'source_amount' => '5',
            'fee_amount' => 0, 'fee_currency' => 'USD', 'status' => 'draft', 'purpose_code' => 'IR008',
            'client_reference' => NiumRejectedPayoutQuoteRecovery::REFERENCE, 'reference_text' => 'SYNTHETIC-INCIDENT',
            'raw_data' => ['sgd_local_workflow' => [
                'version' => 1, 'release' => NiumSgdLocalCugScope::RELEASE, 'state' => 'lock_unknown',
                'maker_user_id' => 12, 'intent' => $intent,
                'lock_request_id' => NiumRejectedPayoutQuoteRecovery::REQUEST, 'lock_requested_at' => now()->toISOString(),
                'expected_fee' => ['amount' => '0', 'currency' => 'USD', 'basis' => 'user_expectation'],
                'provider_fee_status' => 'unavailable',
            ]],
        ]);
        $request = NiumPayoutQuoteContract::request($context['customer'], 'USD', 'SGD', '5');
        $request['executionType'] = 'manual'; // Historical rejected request only; never dispatched.
        $request = json_encode($request, JSON_THROW_ON_ERROR);
        $response = json_encode(['errors' => [NiumRejectedPayoutQuoteRecovery::ERROR]], JSON_THROW_ON_ERROR);
        $log = ApiRequestLog::query()->forceCreate([
            'id' => 4763, 'provider_id' => 10, 'user_id' => 12, 'operation' => 'create_payout_fx_lock',
            'client_hash_id' => $context['client'], 'request_method' => 'POST',
            'request_url' => 'https://nium.test/api/v1/client/[REDACTED]/quotes',
            'request_headers' => ['x-request-id' => NiumRejectedPayoutQuoteRecovery::REQUEST],
            'response_status' => 400, 'is_success' => false, 'transport_outcome' => 'response_received',
            'response_body' => ['response_received' => true], 'request_started_at' => now(), 'request_finished_at' => now(),
        ]);
        $evidence = NiumApiExchangeEvidence::query()->create([
            'api_request_log_id' => $log->id, 'request_method' => 'POST', 'request_url' => $log->request_url,
            'capture_version' => 1, 'response_received' => true,
            'request_headers' => ['x-request-id' => [NiumRejectedPayoutQuoteRecovery::REQUEST]],
            'raw_request_body' => $request, 'raw_response_body' => $response,
            'request_body_sha256' => hash('sha256', $request), 'response_body_sha256' => hash('sha256', $response),
        ]);
        $clearance = [
            'transfer_id' => 5, 'audit_id' => 4763, 'request_uuid' => NiumRejectedPayoutQuoteRecovery::REQUEST,
            'reviewed_by' => $operator->id, 'request_body_sha256' => $evidence->request_body_sha256,
            'response_body_sha256' => $evidence->response_body_sha256,
            'provider_review' => ['reference' => 'SYNTHETIC-PROVIDER-REVIEW-NOT-REAL',
                'artifact_sha256' => hash('sha256', $this->rejectedProviderArtifact),
                'no_quote_created' => true, 'no_conversion' => true, 'no_fx_hold' => true, 'no_debit_or_fee' => true],
        ];

        return [$transfer, $operator, $clearance, $log, $evidence];
    }

    /** JSONB object key order is immaterial; scalar types, keys and list order are strict. */
    protected function assertSameRecoveryJson(array $expected, array $actual): void
    {
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (! is_array($value)) {
                return $value;
            }
            $isList = array_is_list($value);
            foreach ($value as $key => $child) {
                $value[$key] = $normalize($child);
            }
            if (! $isList) {
                ksort($value, SORT_STRING);
            }

            return $value;
        };

        $this->assertSame($normalize($expected), $normalize($actual));
    }

    protected function authorizeSyntheticRecoveredReview(): void
    {
        config()->set('services.nium.sgd_local_zero_fee', [
            'enabled' => true, 'version' => 'synthetic-v1', 'confirmation_reference' => 'SYNTHETIC-CONFIRMATION',
            'confirmed_by' => 'synthetic-business-owner', 'confirmed_at' => now()->subMinute()->toISOString(),
            'effective_from' => now()->subMinute()->toISOString(), 'expires_at' => now()->addHour()->toISOString(),
            'provider_context' => ['base_url' => 'https://nium.test', 'client' => 'synthetic-client',
                'customer' => 'synthetic-customer', 'wallet' => 'synthetic-wallet'],
            'terms' => NiumSgdLocalZeroFeePolicy::TERMS,
        ]);
    }

    protected function fakeSgdProvider(): void
    {
        Http::swap(new Factory(app('events')));
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'purposeCodes')) {
                return Http::response([['purposeCode' => 'IR008', 'description' => 'Synthetic incident purpose']]);
            }
            if (str_contains($request->url(), 'validationSchemas')) {
                return Http::response([['type' => 'object', 'required' => ['beneficiaryAccountNumber'],
                    'properties' => ['beneficiaryAccountNumber' => ['type' => 'string', 'minLength' => 6]]]]);
            }
            if (str_ends_with($request->url(), '/quotes')) {
                $this->assertSame('POST', $request->method());
                $this->assertSame('payout', $request['quoteType']);
                $this->assertSame('EXECUTABLE', $request['quoteIntent']);
                $this->assertSame('immediate', $request['conversionSchedule']);
                $this->assertSame('at_conversion_time', $request['executionType']);
                $this->assertSame('15_mins', $request['lockPeriod']);

                return Http::response([
                    'id' => 'synthetic-recovered-quote', 'quoteType' => 'payout', 'quoteIntent' => 'EXECUTABLE',
                    'executionType' => 'at_conversion_time', 'conversionSchedule' => 'immediate', 'lockPeriod' => '15_mins',
                    'sourceAmount' => $request['sourceAmount'], 'sourceCurrencyCode' => $request['sourceCurrencyCode'],
                    'destinationCurrencyCode' => $request['destinationCurrencyCode'], 'netExchangeRate' => '1.275939120',
                    'expiryTime' => now()->addMinutes(5)->toISOString(),
                ]);
            }
            throw new RuntimeException('Unexpected synthetic provider request; remittance is not authorized.');
        });
    }

    protected function sgdToken(User $user): string
    {
        $token = Str::random(80);
        ApiToken::query()->create(['user_id' => $user->id, 'name' => 'synthetic', 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay()]);

        return $token;
    }
}
