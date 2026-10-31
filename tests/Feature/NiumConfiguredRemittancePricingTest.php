<?php

namespace Tests\Feature;

use App\Contracts\NiumRemittancePricing;
use App\Models\ApiRequestLog;
use App\Models\Balance;
use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Nium\NiumConfiguredRemittancePricing;
use App\Services\Nium\NiumService;
use App\Services\Nium\NiumSgdLocalPayoutGate;
use App\Services\Nium\NiumSgdLocalPayoutWorkflow;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesSgdLocalExecutionFixtures;
use Tests\Concerns\RefreshesCommittedPayoutDatabase;
use Tests\TestCase;

class NiumConfiguredRemittancePricingTest extends TestCase
{
    use CreatesSgdLocalExecutionFixtures, RefreshesCommittedPayoutDatabase;

    private function fakePricing(array $response): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://nium.test/api/v2/client/synthetic-client/fees' => Http::response($response)]);
    }

    private function zeroPlan(): array
    {
        // Schema-shaped synthetic configuration, never an authentic pricing assignment.
        return [['default' => true, 'segment' => 'Synthetic', 'status' => 'Active', 'fees' => [
            ['feeName' => 'REMIT_BANK_FEE', 'feeValue' => 0, 'fixed' => true, 'authCurrency' => 'USD',
                'transactionCurrency' => 'SGD', 'feeCurrency' => 'USD', 'slabFrom' => 0, 'slabTo' => 100,
                'thresholdFeeValue' => 0, 'status' => 'Active'],
        ]]];
    }

    public function test_reading_or_requesting_approval_never_acquires_pricing_or_fabricates_zero(): void
    {
        [, $transfer] = $this->sgdExecutionFixture(false);
        $this->assertInstanceOf(NiumConfiguredRemittancePricing::class, app(NiumRemittancePricing::class));
        $this->fakePricing($this->zeroPlan());
        $presentation = app(NiumSgdLocalPayoutWorkflow::class)->presentation($transfer);
        $this->assertSame('unavailable', $presentation['provider_fee_status']);
        $this->assertFalse($presentation['approval_request_allowed']);
        $this->assertNull($presentation['financial_terms_token']);
        Http::assertNothingSent();
        $this->expectExceptionMessage(NiumSgdLocalPayoutGate::BLOCK_REASON);
        try {
            $this->submitSgd($transfer);
        } finally {
            Http::assertNothingSent();
            $this->assertDatabaseCount('ledger_entries', 0);
        }
    }

    public function test_explicit_refresh_only_reads_client_configuration_and_cannot_prove_total_debit_even_with_zero_fee(): void
    {
        [, $transfer] = $this->sgdExecutionFixture(false);
        $this->fakePricing($this->zeroPlan());
        $this->withToken($this->sgdToken($transfer->user))
            ->postJson("/api/user/users/12/transfers/{$transfer->id}/sgd-local-refresh-pricing")
            ->assertOk()->assertJsonPath('payout_workflow.provider_fee_status', 'unavailable')
            ->assertJsonPath('payout_amounts.transfer_fee', null)->assertJsonPath('payout_amounts.total_wallet_debit', null);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_ends_with($r->url(), '/fees'));
        $quote = app(NiumRemittancePricing::class)->quote($transfer);
        $this->assertSame('effective_customer_pricing_and_total_debit_unverified', $quote['reason']);
        $log = ApiRequestLog::query()->where('operation', 'fee_details_v2')->sole();
        $this->assertSame($log->id, $quote['configuration_reference']['api_request_log_id']);
        $this->assertSame(hash('sha256', $log->niumExchangeEvidence->raw_response_body), $quote['configuration_reference']['response_sha256']);
        $this->assertNull($transfer->fresh()->provider_operation_key);
        $this->assertDatabaseCount('fx_quotes', 1);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('transfer_approvals', 0);
    }

    public static function unverifiableCaptures(): array
    {
        return [['stale'], ['digest'], ['client'], ['host'], ['method'], ['response'], ['schema'], ['evidence_url'],
            ['uuid'], ['header_digest'], ['length'], ['status'], ['version']];
    }

    #[DataProvider('unverifiableCaptures')]
    public function test_stale_cross_context_or_invalid_captures_do_not_establish_financial_terms(string $mutation): void
    {
        [, $transfer] = $this->sgdExecutionFixture(false);
        $this->fakePricing($mutation === 'schema' ? ['inventedQuote' => ['fee' => 0]] : $this->zeroPlan());
        $pricing = app(NiumRemittancePricing::class);
        $pricing->refresh($transfer);
        $log = ApiRequestLog::query()->where('operation', 'fee_details_v2')->sole();
        match ($mutation) {
            'stale' => $log->update(['request_finished_at' => now()->subHour()]),
            'digest' => $log->niumExchangeEvidence->update(['response_body_sha256' => str_repeat('0', 64)]),
            'client' => $log->update(['client_hash_id' => 'other-client']),
            'host' => $log->update(['request_url' => 'https://other.test/api/v2/client/[REDACTED]/fees']),
            'method' => $log->update(['request_method' => 'POST']),
            'response' => $log->niumExchangeEvidence->update(['response_received' => false]),
            'evidence_url' => $log->niumExchangeEvidence->update(['request_url' => 'https://other.test/fees']),
            'uuid' => $log->update(['request_headers' => ['x-request-id' => Str::uuid()->toString()]]),
            'header_digest' => $log->niumExchangeEvidence->update(['request_headers_sha256' => str_repeat('0', 64)]),
            'length' => $log->niumExchangeEvidence->update(['response_byte_length' => 1]),
            'status' => $log->update(['response_status' => 201]),
            'version' => $log->niumExchangeEvidence->update(['capture_version' => 2]),
            default => null,
        };
        $this->assertSame('unavailable', $pricing->quote($transfer)['status']);
        $this->expectExceptionMessage(NiumSgdLocalPayoutGate::BLOCK_REASON);
        NiumSgdLocalPayoutGate::assertSubmissionAllowed($transfer);
    }

    public function test_refresh_is_refused_within_financial_transaction_and_for_unsupported_endpoint(): void
    {
        [, $transfer] = $this->sgdExecutionFixture(false);
        $this->fakePricing($this->zeroPlan());
        DB::beginTransaction();
        try {
            app(NiumRemittancePricing::class)->refresh($transfer);
            $this->fail('Pricing I/O inside financial transaction is prohibited');
        } catch (RuntimeException) {
            Http::assertNothingSent();
        } finally {
            DB::rollBack();
        }
        config()->set('services.nium.fee_details_endpoint', '/invented-quote-endpoint');
        $this->expectException(RuntimeException::class);
        try {
            app(NiumRemittancePricing::class)->refresh($transfer);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_refresh_is_owned_and_rejected_after_approval_without_any_provider_io(): void
    {
        [, $transfer] = $this->sgdExecutionFixture(false);
        $this->fakePricing($this->zeroPlan());
        $other = User::factory()->create(['status' => 'active']);
        $url = "/api/user/users/12/transfers/{$transfer->id}/sgd-local-refresh-pricing";
        $this->withToken($this->sgdToken($other))->postJson($url)->assertForbidden();
        $transfer->update(['status' => 'approved']);
        $this->withToken($this->sgdToken($transfer->user))->postJson($url)->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_changed_displayed_terms_and_insufficient_all_in_funds_cannot_persist_review(): void
    {
        [, $transfer] = $this->sgdExecutionFixture();
        $token = $this->reviewToken($transfer);
        $this->installSyntheticPricing($transfer, ['pricing_version' => 'CHANGED-SYNTHETIC-PRICING']);
        $url = "/api/user/users/12/transfers/{$transfer->id}/sgd-local-request-approval";
        $this->withToken($this->sgdToken($transfer->user))->postJson($url, $token)->assertUnprocessable();
        Balance::query()->update(['available_balance' => '4.50', 'ledger_balance' => '4.50']);
        $this->withToken($this->sgdToken($transfer->user))->postJson($url, $this->reviewToken($transfer))->assertUnprocessable();
        $this->assertSame('draft', $transfer->fresh()->status);
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    private static function fxRule(array $changes = []): array
    {
        return array_replace(['feeName' => 'FX_MARKUP', 'feeValue' => '0.13', 'fixed' => false,
            'authCurrency' => 'USD', 'transactionCurrency' => 'SGD', 'feeCurrency' => 'ANY',
            'slabFrom' => '0', 'slabTo' => '999999999', 'status' => 'ACTIVE'], $changes);
    }

    private function capturePricingAndCustomer(Transfer $transfer, mixed $segment, ?array $plans = null,
        ?string $feeOperation = 'fee_details_v2', ?string $customerOperation = 'customer_details'): ApiRequestLog
    {
        // Synthetic transport captures exercise the real encrypted evidence store, with no network.
        $plans ??= [['segment' => 'DEFAULT', 'default' => true, 'status' => 'ACTIVE', 'fees' => [self::fxRule()]]];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://nium.test/api/v2/client/synthetic-client/fees' => Http::response($plans),
            'https://nium.test/api/v1/client/synthetic-client/customer/synthetic-customer' => Http::response([
                'customerHashId' => 'synthetic-customer', 'clientHashId' => 'synthetic-client',
                'walletHashId' => 'synthetic-wallet', 'segment' => $segment,
            ]),
        ]);
        app(NiumService::class)->get('/api/v2/client/synthetic-client/fees', user: $transfer->user, operation: $feeOperation);
        app(NiumService::class)->get('/api/v1/client/synthetic-client/customer/synthetic-customer',
            user: $transfer->user, operation: $customerOperation);

        return ApiRequestLog::query()->where('request_url', 'https://nium.test/api/v1/client/[REDACTED]/customer/[REDACTED]')->sole();
    }

    public function test_null_customer_segment_and_observed_markup_never_establish_default_assignment_or_zero_wallet_charge(): void
    {
        [, $transfer] = $this->sgdExecutionFixture(false, ['amount' => '17.25']);
        $this->capturePricingAndCustomer($transfer, null);
        $quote = app(NiumRemittancePricing::class)->quote($transfer);
        $inspection = $quote['pricing_inspection'];
        $this->assertSame('unavailable', $quote['status']);
        $this->assertSame('confirmed', $quote['client_configuration_status']);
        $this->assertSame('unverified', $quote['total_wallet_debit_status']);
        $this->assertSame('unverified', $quote['additional_charges_status']);
        $this->assertSame('null_segment_default_fallback_unverified', $inspection['customer_assignment']);
        $this->assertFalse($inspection['plan_candidate']['customer_assignment_verified']);
        $this->assertNull($inspection['effective_segment']);
        $this->assertSame('0.13', $inspection['fx_rule_candidate']['configured_value']);
        $this->assertSame('source_principal_candidate_only_not_authorization', $inspection['fx_rule_candidate']['slab_basis']);
        $this->assertSame('absent_row_does_not_establish_zero', $inspection['payout_fee_status']);
        $this->assertNull($inspection['all_in_source_wallet_debit']);
        $rate = $transfer->fx_rate;
        $this->withToken($this->sgdToken($transfer->user))
            ->postJson("/api/user/users/12/transfers/{$transfer->id}/sgd-local-request-approval", ['submission_token' => str_repeat('0', 64), 'accept_payment_terms' => true, 'purpose_confirmed' => true])
            ->assertUnprocessable();
        $this->assertSame($rate, $transfer->fresh()->fx_rate);
        $this->assertNull($transfer->provider_operation_key);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET' || str_contains($request->url(), 'lockExchangeRate'));
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('transfer_approvals', 0);
    }

    public function test_explicit_customer_segment_selects_active_plan_but_does_not_authorize_even_explicit_zero_payout_fee(): void
    {
        [, $transfer] = $this->sgdExecutionFixture(false);
        $plans = $this->zeroPlan();
        $plans[0]['fees'][] = self::fxRule();
        $plans[] = ['segment' => 'DEFAULT', 'default' => true, 'status' => 'Active', 'fees' => [self::fxRule(['feeValue' => '8'])]];
        $this->capturePricingAndCustomer($transfer, 'Synthetic', $plans);
        $quote = app(NiumRemittancePricing::class)->quote($transfer);
        $this->assertSame('unavailable', $quote['status']);
        $this->assertSame('explicit_segment', $quote['pricing_inspection']['customer_assignment']);
        $this->assertSame('Synthetic', $quote['pricing_inspection']['effective_segment']);
        $this->assertTrue($quote['pricing_inspection']['plan_candidate']['customer_assignment_verified']);
        $this->assertSame('0.13', $quote['pricing_inspection']['fx_rule_candidate']['configured_value']);
        $this->assertSame('configured_rows_do_not_establish_complete_debit', $quote['pricing_inspection']['payout_fee_status']);
        $this->assertNull($quote['pricing_inspection']['all_in_source_wallet_debit']);
    }

    public static function customerCaptureMutations(): array
    {
        return array_map(fn ($mutation) => [$mutation], ['stale', 'future', 'hash', 'uuid', 'header_hash', 'customer', 'client', 'wallet',
            'host', 'user', 'provider', 'log_client', 'method', 'status', 'received', 'version', 'length', 'segment_missing']);
    }

    #[DataProvider('customerCaptureMutations')]
    public function test_customer_capture_must_be_recent_and_bound_to_exact_customer_context(string $mutation): void
    {
        [, $transfer] = $this->sgdExecutionFixture(false);
        $log = $this->capturePricingAndCustomer($transfer, 'DEFAULT');
        $evidence = $log->niumExchangeEvidence;
        if (in_array($mutation, ['customer', 'client', 'wallet', 'segment_missing'], true)) {
            $body = json_decode($evidence->raw_response_body, true);
            if ($mutation === 'segment_missing') {
                unset($body['segment']);
            } else {
                $body[$mutation.'HashId'] = 'another-'.$mutation;
            }
            $raw = json_encode($body, JSON_THROW_ON_ERROR);
            $evidence->update(['raw_response_body' => $raw, 'response_body_sha256' => hash('sha256', $raw), 'response_byte_length' => strlen($raw)]);
        } else {
            match ($mutation) {
                'stale' => $log->update(['request_finished_at' => now()->subHour()]),
                'future' => $log->update(['request_finished_at' => now()->addHour()]),
                'hash' => $evidence->update(['response_body_sha256' => str_repeat('0', 64)]),
                'uuid' => $log->update(['request_headers' => ['x-request-id' => Str::uuid()->toString()]]),
                'header_hash' => $evidence->update(['request_headers_sha256' => str_repeat('0', 64)]),
                'host' => $log->update(['request_url' => 'https://another.test/customer/[REDACTED]']),
                'user' => $log->update(['user_id' => null]),
                'provider' => $log->update(['provider_id' => IntegrationProvider::query()->create(['code' => 'other', 'name' => 'Other', 'status' => 'active'])->id]),
                'log_client' => $log->update(['client_hash_id' => 'another-client']),
                'method' => $log->update(['request_method' => 'POST']),
                'status' => $log->update(['response_status' => 201]),
                'received' => $evidence->update(['response_received' => false]),
                'version' => $evidence->update(['capture_version' => 2]),
                'length' => $evidence->update(['response_byte_length' => 1]),
            };
        }
        $quote = app(NiumRemittancePricing::class)->quote($transfer);
        $this->assertSame('unavailable', $quote['status']);
        $this->assertNull($quote['pricing_inspection']['effective_segment']);
        $this->assertFalse($quote['pricing_inspection']['plan_candidate']['customer_assignment_verified']);
        Http::assertSentCount(2);
    }

    public static function unmatchedPlans(): array
    {
        return [['missing'], ['inactive'], ['duplicate']];
    }

    #[DataProvider('unmatchedPlans')]
    public function test_explicit_customer_segment_never_falls_back_to_default_when_unmatched(string $mutation): void
    {
        [, $transfer] = $this->sgdExecutionFixture(false);
        $plans = [['segment' => 'DEFAULT', 'default' => true, 'status' => 'Active', 'fees' => [self::fxRule()]]];
        if ($mutation !== 'missing') {
            $plans[] = ['segment' => 'CUSTOM', 'default' => false, 'status' => $mutation === 'inactive' ? 'Inactive' : 'Active', 'fees' => [self::fxRule()]];
            if ($mutation === 'duplicate') {
                $plans[] = $plans[1];
            }
        }
        $this->capturePricingAndCustomer($transfer, 'CUSTOM', $plans);
        $inspection = app(NiumRemittancePricing::class)->quote($transfer)['pricing_inspection'];
        $this->assertNull($inspection['effective_segment']);
        $this->assertArrayNotHasKey('plan_candidate', $inspection);
        $this->assertContains('customer_segment_missing_inactive_or_ambiguous', $inspection['blockers']);
    }

    public function test_fx_rule_uses_pair_precedence_dynamic_slabs_and_exact_decimal_values(): void
    {
        $rules = [self::fxRule(['authCurrency' => 'ANY', 'transactionCurrency' => 'ANY', 'feeValue' => '2']),
            self::fxRule(['slabTo' => '10', 'feeValue' => '0.13000000000000000001']),
            self::fxRule(['slabFrom' => '10.00000001', 'feeValue' => '0.07']),
            self::fxRule(['status' => 'INACTIVE', 'feeValue' => '9']),
            self::fxRule(['transactionCurrency' => 'JPY', 'feeValue' => '7'])];
        $this->assertSame('0.13000000000000000001', NiumConfiguredRemittancePricing::matchFxRule($rules, 'USD', 'SGD', '5')['configured_value']);
        $this->assertSame('0.13000000000000000001', NiumConfiguredRemittancePricing::matchFxRule($rules, 'USD', 'SGD', '10')['configured_value']);
        $match = NiumConfiguredRemittancePricing::matchFxRule($rules, 'USD', 'SGD', '17.25');
        $this->assertSame('0.07', $match['configured_value']);
        $this->assertNull($match['wallet_debit_amount']);
        $this->assertSame('use_locked_effective_rate_unchanged', $match['fx_rate_treatment']);
        $this->assertSame('2', NiumConfiguredRemittancePricing::matchFxRule($rules, 'EUR', 'GBP', '17.25')['configured_value']);
    }

    public function test_numeric_json_markup_is_preserved_exactly_without_binary_float_rounding(): void
    {
        [, $transfer] = $this->sgdExecutionFixture(false);
        $raw = '[{"segment":"DEFAULT","default":true,"status":"ACTIVE","fees":[{"feeName":"FX_MARKUP","feeValue":0.13000000000000000001,"fixed":false,"authCurrency":"USD","transactionCurrency":"SGD","slabFrom":0,"slabTo":999999999,"status":"ACTIVE"}]}]';
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://nium.test/api/v2/client/synthetic-client/fees' => Http::response($raw, 200, ['Content-Type' => 'application/json'])]);
        $pricing = app(NiumRemittancePricing::class);
        $pricing->refresh($transfer);
        $quote = $pricing->quote($transfer);
        $this->assertSame('unavailable', $quote['status']);
        $this->assertSame('0.13000000000000000001', $quote['pricing_inspection']['fx_rule_candidate']['configured_value']);
        $this->assertFalse($quote['pricing_inspection']['plan_candidate']['customer_assignment_verified']);
        $this->assertSame(hash('sha256', $raw), $quote['configuration_reference']['response_sha256']);
        Http::assertSentCount(1);
    }

    public static function pricingOperationAliases(): array
    {
        return [['fee_details_v2', 'customer_details'], [null, 'customer_details'], ['fee_details_v2', null], [null, null]];
    }

    #[DataProvider('pricingOperationAliases')]
    public function test_endpoint_bound_canonical_and_inferred_get_operations_confirm_only_client_configuration(?string $fees, ?string $customer): void
    {
        [, $transfer] = $this->sgdExecutionFixture(false);
        $log = $this->capturePricingAndCustomer($transfer, 'DEFAULT', feeOperation: $fees, customerOperation: $customer);
        $quote = app(NiumRemittancePricing::class)->quote($transfer);
        $this->assertSame('confirmed', $quote['client_configuration_status']);
        $this->assertSame($fees ?? 'get_nium_api', $quote['configuration_reference']['operation']);
        $this->assertSame($log->id, $quote['pricing_inspection']['customer_reference']['api_request_log_id']);
        $this->assertSame($customer ?? 'get_nium_api', $quote['pricing_inspection']['customer_reference']['operation']);
        $this->assertSame('explicit_segment', $quote['pricing_inspection']['customer_assignment']);
        $this->assertSame('0.13', $quote['pricing_inspection']['fx_rule_candidate']['configured_value']);
        $this->assertSame('unavailable', $quote['status']);
        $this->assertSame('unverified', $quote['total_wallet_debit_status']);
        $this->assertSame('unverified', $quote['additional_charges_status']);
        $this->assertNull($quote['pricing_inspection']['all_in_source_wallet_debit']);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('transfer_approvals', 0);
    }

    public static function aliasFreshnessBoundaries(): array
    {
        return [['fees', 299, true], ['fees', 300, false], ['fees', 301, false],
            ['customer', 299, true], ['customer', 300, false], ['customer', 301, false]];
    }

    #[DataProvider('aliasFreshnessBoundaries')]
    public function test_inferred_aliases_retain_the_existing_strict_freshness_boundary(string $capture, int $age, bool $valid): void
    {
        $this->travelTo(now()->startOfSecond());
        config()->set('services.nium.fee_details_max_age_seconds', 300);
        [, $transfer] = $this->sgdExecutionFixture(false);
        $customer = $this->capturePricingAndCustomer($transfer, 'DEFAULT', feeOperation: null, customerOperation: null);
        $log = $capture === 'customer' ? $customer : ApiRequestLog::query()->where('request_url', 'https://nium.test/api/v2/client/[REDACTED]/fees')->sole();
        $log->update(['request_finished_at' => now()->subSeconds($age)]);
        $quote = app(NiumRemittancePricing::class)->quote($transfer);
        if ($capture === 'fees' && ! $valid) {
            $this->assertSame('configured_pricing_stale_or_unverifiable', $quote['reason']);
            $this->assertArrayNotHasKey('configuration_reference', $quote);
        } else {
            $this->assertSame('confirmed', $quote['client_configuration_status']);
            $this->assertSame($valid ? 'DEFAULT' : null, $quote['pricing_inspection']['effective_segment']);
        }
        $this->assertSame('unavailable', $quote['status']);
        Http::assertSentCount(2);
    }

    public function test_unknown_operation_at_fee_or_customer_endpoint_is_never_approved_as_an_alias(): void
    {
        [, $transfer] = $this->sgdExecutionFixture(false);
        $customer = $this->capturePricingAndCustomer($transfer, 'DEFAULT', feeOperation: 'unrelated_fee_report', customerOperation: 'beneficiary_list');
        $quote = app(NiumRemittancePricing::class)->quote($transfer);
        $this->assertSame('configured_pricing_not_acquired', $quote['reason']);
        $this->assertArrayNotHasKey('configuration_reference', $quote);
        ApiRequestLog::query()->where('operation', 'unrelated_fee_report')->update(['operation' => 'fee_details_v2']);
        $quote = app(NiumRemittancePricing::class)->quote($transfer);
        $this->assertSame('confirmed', $quote['client_configuration_status']);
        $this->assertNull($quote['pricing_inspection']['effective_segment']);
        $this->assertArrayNotHasKey('customer_reference', $quote['pricing_inspection']);
        $this->assertNotNull($customer->niumExchangeEvidence);
        Http::assertSentCount(2);
    }

    public function test_unrelated_endpoint_with_inferred_alias_cannot_mask_fee_or_customer_captures(): void
    {
        [, $transfer] = $this->sgdExecutionFixture(false);
        $customer = $this->capturePricingAndCustomer($transfer, 'DEFAULT', feeOperation: null, customerOperation: null);
        $fees = ApiRequestLog::query()->where('request_url', 'https://nium.test/api/v2/client/[REDACTED]/fees')->sole();
        Http::assertSentCount(2);
        Http::fake(['https://nium.test/api/v1/client/synthetic-client/customer/synthetic-customer/wallet/synthetic-wallet' => Http::response(['unrelated' => true])]);
        app(NiumService::class)->get('/api/v1/client/synthetic-client/customer/synthetic-customer/wallet/synthetic-wallet', user: $transfer->user);
        $quote = app(NiumRemittancePricing::class)->quote($transfer);
        $this->assertSame($fees->id, $quote['configuration_reference']['api_request_log_id']);
        $this->assertSame($customer->id, $quote['pricing_inspection']['customer_reference']['api_request_log_id']);
        Http::assertSentCount(1); // Installing the new fake resets transport history, not captured logs.
    }

    public static function newerInvalidAliases(): array
    {
        return [['fees'], ['customer']];
    }

    #[DataProvider('newerInvalidAliases')]
    public function test_newer_invalid_alias_never_falls_back_to_older_canonical_success(string $capture): void
    {
        [, $transfer] = $this->sgdExecutionFixture(false);
        $this->capturePricingAndCustomer($transfer, 'DEFAULT');
        $path = $capture === 'fees' ? '/api/v2/client/synthetic-client/fees' : '/api/v1/client/synthetic-client/customer/synthetic-customer';
        app(NiumService::class)->get($path, user: $transfer->user);
        $newer = ApiRequestLog::query()->latest('id')->firstOrFail();
        $this->assertSame('get_nium_api', $newer->operation);
        $newer->niumExchangeEvidence->update(['response_body_sha256' => str_repeat('0', 64)]);
        $quote = app(NiumRemittancePricing::class)->quote($transfer);
        if ($capture === 'fees') {
            $this->assertArrayNotHasKey('configuration_reference', $quote);
        } else {
            $this->assertSame('confirmed', $quote['client_configuration_status']);
            $this->assertNull($quote['pricing_inspection']['effective_segment']);
            $this->assertArrayNotHasKey('customer_reference', $quote['pricing_inspection']);
        }
        $this->assertSame('unavailable', $quote['status']);
        Http::assertSentCount(3);
    }

    public function test_explicit_fx_rule_is_selected_before_unused_any_any_fallback_is_validated(): void
    {
        $fallback = self::fxRule(['authCurrency' => 'ANY', 'transactionCurrency' => 'ANY', 'feeValue' => null, 'fixed' => true]);
        $explicit = self::fxRule();
        $this->assertSame('0.13', NiumConfiguredRemittancePricing::matchFxRule([$fallback, $explicit], 'USD', 'SGD', '5')['configured_value']);
        $this->assertSame('0.13', NiumConfiguredRemittancePricing::matchFxRule([$explicit, $fallback], 'USD', 'SGD', '5')['configured_value']);
        $this->expectExceptionMessage('active_fx_markup_rule_ambiguous');
        NiumConfiguredRemittancePricing::matchFxRule([$fallback, $explicit, $explicit], 'USD', 'SGD', '5');
    }

    public function test_any_any_fallback_is_used_only_without_an_applicable_explicit_slab(): void
    {
        $rules = [self::fxRule(['authCurrency' => 'ANY', 'transactionCurrency' => 'ANY', 'feeValue' => '0.9']),
            self::fxRule(['slabTo' => '10'])];
        $this->assertSame('0.13', NiumConfiguredRemittancePricing::matchFxRule($rules, 'USD', 'SGD', '5')['configured_value']);
        $this->assertSame('0.9', NiumConfiguredRemittancePricing::matchFxRule($rules, 'USD', 'SGD', '17.25')['configured_value']);
    }

    public static function unsafeFxRules(): array
    {
        $rule = self::fxRule();

        return [
            'overlap' => [[$rule, $rule], 'active_fx_markup_rule_ambiguous'],
            'wildcard_tie' => [[self::fxRule(['authCurrency' => 'ANY']), self::fxRule(['transactionCurrency' => 'ANY'])], 'active_fx_markup_rule_ambiguous'],
            'inactive' => [[self::fxRule(['status' => 'INACTIVE'])], 'active_fx_markup_rule_missing'],
            'outside_slab' => [[self::fxRule(['slabFrom' => '10'])], 'active_fx_markup_rule_missing'],
            'wrong_pair' => [[self::fxRule(['transactionCurrency' => 'JPY'])], 'active_fx_markup_rule_missing'],
            'inverted_slab' => [[self::fxRule(['slabFrom' => '10', 'slabTo' => '1'])], 'fx_markup_slab_invalid'],
            'fixed' => [[self::fxRule(['fixed' => true])], 'fx_markup_percentage_contract_unverified'],
            'float' => [[self::fxRule(['feeValue' => 0.13])], 'pricing_decimal_unverifiable'],
        ];
    }

    #[DataProvider('unsafeFxRules')]
    public function test_ambiguous_missing_or_unverifiable_fx_rules_are_rejected(array $rules, string $reason): void
    {
        $this->expectExceptionMessage($reason);
        NiumConfiguredRemittancePricing::matchFxRule($rules, 'USD', 'SGD', '5');
    }
}
