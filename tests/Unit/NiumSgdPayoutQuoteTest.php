<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Admin\TransferController;
use App\Models\Beneficiary;
use App\Models\FxQuote;
use App\Models\IntegrationProvider;
use App\Models\KycProfile;
use App\Models\Transfer;
use App\Models\TransferApproval;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Integrations\ProviderHttpClient;
use App\Services\Nium\NiumBeneficiaryRequirementsResult;
use App\Services\Nium\NiumBeneficiaryService;
use App\Services\Nium\NiumPayoutFxLockContract;
use App\Services\Nium\NiumPayoutQuoteContract;
use App\Services\Nium\NiumProviderAccountStateService;
use App\Services\Nium\NiumQuoteService;
use App\Services\Nium\NiumService;
use App\Services\Nium\NiumSgdLocalPayoutAmounts;
use App\Services\Nium\NiumTransferPolicy;
use App\Services\Nium\NiumTransferReviewService;
use App\Services\Wallet\TransferApprovalService;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Pure model snapshots and mocked transport. No database schema or migrations. */
class NiumSgdPayoutQuoteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02T01:40:00Z');
        Http::preventStrayRequests();
        // Any accidental database use fails, even if an operator has local credentials.
        DB::shouldReceive('connection')->never();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function response(array $changes = []): array
    {
        return array_replace([
            'id' => 'synthetic-payout-quote', 'quoteType' => 'payout', 'quoteIntent' => 'EXECUTABLE',
            'conversionSchedule' => 'immediate', 'executionType' => 'at_conversion_time', 'lockPeriod' => '15_mins',
            'sourceCurrencyCode' => 'USD', 'destinationCurrencyCode' => 'SGD', 'sourceAmount' => '5.00',
            'netExchangeRate' => '1.276738080', 'expiryTime' => '2026-11-02T01:43:46Z',
        ], $changes);
    }

    private function binding(): array
    {
        return ['client' => 'test-client', 'customer' => 'test-customer', 'wallet' => 'test-wallet', 'base_url' => 'https://nium.test'];
    }

    private function snapshots(): array
    {
        $contract = NiumPayoutQuoteContract::parse($this->response(), 'USD', 'SGD', '5');
        $quote = new FxQuote([
            'user_id' => 12, 'provider_id' => 10, 'quote_ref' => $contract['quote_id'],
            'source_currency' => 'USD', 'target_currency' => 'SGD', 'source_amount' => '5',
            'net_rate' => $contract['rate'], 'expires_at' => $contract['expires_at'],
            'raw_data' => ['provider_fx_type' => 'modern_quote', 'quote_type' => 'payout', 'quote_intent' => 'EXECUTABLE',
                'provider_quote_id' => $contract['quote_id'], 'provider_context' => $this->binding(), 'payout_quote_contract' => $contract],
        ]);
        $transfer = new Transfer(['user_id' => 12, 'provider_id' => 10, 'source_currency' => 'USD',
            'target_currency' => 'SGD', 'source_amount' => '5.00000000', 'fx_rate' => $contract['rate']]);

        return [$quote, $transfer];
    }

    public function test_fixed_source_amount_rate_precision_and_estimate_do_not_claim_final_credit(): void
    {
        [$quote, $transfer] = $this->snapshots();
        NiumPayoutQuoteContract::assertMatchesTransfer($quote, $transfer, $this->binding());
        $contract = $quote->raw_data['payout_quote_contract'];
        $this->assertSame('payout', $contract['quote_type']);
        $this->assertSame('5', $contract['source_amount']);
        $this->assertSame('1.27673808', $contract['rate']);
        $this->assertSame('6.383690400000000000', bcmul('5', $contract['rate'], 18));
        $this->assertNull($contract['conversion_amount']);
        $this->assertArrayNotHasKey('beneficiary_credit', $contract);
    }

    private function acceptedTransfer(): Transfer
    {
        [$quote, $transfer] = $this->snapshots();
        config()->set('wallet.ledger.enabled', true);
        config()->set('services.nium.payout_fx_enabled', true);
        config()->set('services.nium.client_id', 'test-client');
        config()->set('services.nium.base_url', 'https://nium.test');
        config()->set('services.nium.supported_corridors', [[
            'destinationCountry' => 'SG', 'destinationCurrency' => 'SGD', 'payoutMethod' => 'LOCAL',
            'beneficiaryAccountType' => 'INDIVIDUAL', 'customerType' => 'CORPORATE', 'routingCodeType' => 'SWIFT',
        ]]);
        $provider = (new IntegrationProvider(['code' => 'nium', 'status' => 'active']))->forceFill(['id' => 10]);
        $user = (new User(['kyc_status' => 'verified']))->forceFill(['id' => 12]);
        $user->setRelation('kycProfile', new KycProfile(['status' => 'approved', 'applicant_type' => 'business',
            'registered_country_code' => 'HK', 'country_code' => 'HK']));
        $beneficiary = (new Beneficiary([
            'user_id' => 12, 'provider_id' => 10, 'external_beneficiary_id' => 'synthetic-beneficiary',
            'beneficiary_type' => 'individual', 'full_name' => 'Synthetic Individual', 'country_code' => 'SG',
            'currency' => 'SGD', 'payout_method' => 'LOCAL', 'account_number' => '123456789', 'swift_bic' => 'DBSSSGSG', 'status' => 'active',
        ]))->forceFill(['id' => 9]);
        $this->mock(NiumProviderAccountStateService::class)->shouldReceive('assertEligible')->andReturn(new UserProviderAccount([
            'provider_id' => 10, 'external_customer_id' => 'test-customer', 'external_account_id' => 'test-wallet',
            'reconciliation_status' => 'reconciled',
        ]));
        $beneficiaryService = $this->mock(NiumBeneficiaryService::class);
        $beneficiaryService->shouldReceive('requirements')->andReturn([[],
            NiumBeneficiaryRequirementsResult::trusted([], [], [], now()->toISOString(), 'synthetic-schema'),
        ]);
        $beneficiaryService->shouldReceive('validatedPayload')->andReturn([]);
        $quote->forceFill(['id' => 81]);
        $transfer->fill(['beneficiary_id' => 9, 'transfer_type' => 'payout', 'fx_quote_id' => 81,
            'fee_amount' => '0', 'fee_currency' => 'USD', 'purpose_code' => 'TRUTHFUL', 'reference_text' => 'Invoice 50',
            'client_reference' => 'synthetic-identity', 'status' => 'approval_required']);
        $transfer->setRelations(['provider' => $provider, 'user' => $user, 'beneficiary' => $beneficiary, 'fxQuote' => $quote]);
        $instruction = [
            'user_id' => 12, 'provider_id' => 10, 'beneficiary_id' => 9,
            'beneficiary_fingerprint' => hash('sha256', json_encode($beneficiary->only([
                'external_beneficiary_id', 'beneficiary_type', 'country_code', 'currency', 'payout_method',
                'full_name', 'account_number', 'iban', 'bank_name', 'bank_code', 'branch_code', 'swift_bic', 'raw_data',
            ]), JSON_THROW_ON_ERROR)),
            'source_amount' => '5', 'source_currency' => 'USD', 'target_currency' => 'SGD',
            'purpose_code' => 'TRUTHFUL', 'reference_text' => 'Invoice 50', 'client_reference' => 'synthetic-identity', 'fx_quote_id' => 81,
        ];
        $quote->raw_data = [...$quote->raw_data, 'payment_review' => ['instruction' => $instruction, 'terms' => [
            'provider_verified' => true, 'reference' => 'SYNTHETIC-PRICE', 'currency' => 'USD',
            'fee_amount' => '0', 'total_wallet_debit' => '5', 'expires_at' => $quote->expires_at->toISOString(),
        ]]];
        $transfer->raw_data = ['payment_consent' => ['user_id' => 12, 'fx_quote_id' => 81,
            'purpose_confirmed' => true, 'accepted_at' => now()->toISOString()]];

        return $transfer;
    }

    public function test_actual_sgd_payload_uses_payout_quote_id_and_fixed_source_amount(): void
    {
        $transfer = $this->acceptedTransfer();
        $payload = app(NiumTransferPolicy::class)->providerPayload($transfer, [['code' => 'TRUTHFUL']]);
        $this->assertSame('synthetic-payout-quote', $payload['payout']['quoteId']);
        $this->assertSame(5.0, $payload['payout']['sourceAmount']);
        $this->assertSame('LOCAL', $payload['payout']['payoutMethod']);
        foreach (['auditId', 'destinationAmount', 'swiftFeeType'] as $field) {
            $this->assertArrayNotHasKey($field, $payload['payout']);
        }
        Http::assertNothingSent();
    }

    public function test_standard_quote_request_uses_conversion_time_and_generic_sgd_quotes_are_denied(): void
    {
        $payload = NiumPayoutQuoteContract::request('test-customer', 'USD', 'SGD', '5.00');
        $this->assertSame('payout', $payload['quoteType']);
        $this->assertSame('EXECUTABLE', $payload['quoteIntent']);
        $this->assertSame('at_conversion_time', $payload['executionType']);
        $this->assertSame('immediate', $payload['conversionSchedule']);
        $this->assertSame('15_mins', $payload['lockPeriod']);
        $this->assertSame(5, $payload['sourceAmount']);
        $this->assertArrayNotHasKey('destinationAmount', $payload);
        config()->set('services.nium.payout_fx_enabled', true);
        $nium = $this->mock(NiumService::class);
        $nium->shouldNotReceive('post');
        $this->expectException(RuntimeException::class);
        app(NiumQuoteService::class)->createQuote(new IntegrationProvider, new User, [
            'source_currency' => 'USD', 'target_currency' => 'SGD', 'source_amount' => '5',
        ]);
    }

    public static function unsafeFinancialTerms(): array
    {
        return [
            [['provider_verified' => false]], [['fee_amount' => null]], [['fee_amount' => '1']],
            [['total_wallet_debit' => '6']], [['total_wallet_debit' => null]], [['currency' => 'SGD']],
            [['expires_at' => '2026-11-02T01:39:59Z']],
        ];
    }

    #[DataProvider('unsafeFinancialTerms')]
    public function test_zero_fee_and_full_principal_debit_invariants_cannot_be_relaxed(array $change): void
    {
        $transfer = $this->acceptedTransfer();
        $raw = $transfer->fxQuote->raw_data;
        $raw['payment_review']['terms'] = array_replace($raw['payment_review']['terms'], $change);
        $transfer->fxQuote->raw_data = $raw;
        $this->expectException(RuntimeException::class);
        app(NiumTransferReviewService::class)->assertAccepted($transfer);
    }

    public function test_projection_uses_same_reviewed_zero_fee_and_debit_and_labels_the_estimate(): void
    {
        $transfer = $this->acceptedTransfer();
        app(NiumTransferReviewService::class)->assertAccepted($transfer);
        $amounts = app(NiumSgdLocalPayoutAmounts::class)->forTransfer($transfer);
        $this->assertSame(['amount' => '0', 'currency' => 'USD'], $amounts['transfer_fee']);
        $this->assertSame('5', $amounts['total_wallet_debit']);
        $this->assertSame('6.383690400000000000', $amounts['estimated_conversion_amount']);
        $this->assertNull($amounts['beneficiary_credit']);
        $this->assertNull($amounts['conversion_amount']);
        Carbon::setTestNow('2026-11-02T01:43:46Z');
        $history = app(NiumSgdLocalPayoutAmounts::class)->forTransfer($transfer);
        $this->assertSame($amounts, $history); // Stored terms remain visible with their original expiry.
        $this->expectException(RuntimeException::class);
        app(NiumTransferReviewService::class)->assertAccepted($transfer);
    }

    public function test_expired_review_cannot_be_accepted_or_dispatched_even_with_customer_consent(): void
    {
        $transfer = $this->acceptedTransfer();
        Carbon::setTestNow('2026-11-02T01:43:46Z');
        $this->expectException(RuntimeException::class);
        app(NiumTransferReviewService::class)->assertAccepted($transfer);
    }

    public function test_changed_receiving_account_requires_new_customer_review(): void
    {
        $transfer = $this->acceptedTransfer();
        $transfer->beneficiary->account_number = 'changed-account';
        $this->expectException(RuntimeException::class);
        app(NiumTransferReviewService::class)->assertAccepted($transfer);
    }

    public function test_sgd_approval_is_mandatory_and_dispatch_stays_held_even_with_global_flag_disabled(): void
    {
        $transfer = $this->acceptedTransfer();
        config()->set('wallet.transfer_controls.require_admin_approval', false);
        $service = app(TransferApprovalService::class);
        $this->assertTrue($service->requiresApproval($transfer));
        foreach ([[], [new TransferApproval(['action' => 'approved', 'approver_user_id' => 12])],
            [new TransferApproval(['action' => 'approved', 'approver_user_id' => 20])]] as $approvals) {
            $transfer->setRelation('approvals', collect($approvals));
            try {
                $service->ensureApprovedForSubmission($transfer);
                $this->fail('Change Set 1 cannot authorize SGD remittance.');
            } catch (RuntimeException $exception) {
                $this->assertSame(\App\Services\Nium\NiumSgdAdmissionFence::DISPATCH_HOLD, $exception->getMessage());
            }
        }
    }

    public function test_existing_hk_usd_swift_payload_is_unchanged(): void
    {
        $transfer = $this->acceptedTransfer();
        $transfer->fill(['target_currency' => 'USD', 'fx_quote_id' => null, 'fx_rate' => null]);
        $transfer->setRelation('fxQuote', null);
        $transfer->beneficiary->fill(['country_code' => 'HK', 'currency' => 'USD', 'payout_method' => 'SWIFT']);
        $payload = app(NiumTransferPolicy::class)->providerPayload($transfer, [['code' => 'TRUTHFUL']]);
        $this->assertSame(['sourceAmount' => 5.0, 'sourceCurrency' => 'USD', 'destinationCurrency' => 'USD',
            'payoutMethod' => 'SWIFT', 'tradeOrderID' => 'Invoice 50', 'swiftFeeType' => 'SHA'], $payload['payout']);
        Http::assertNothingSent();
    }

    public function test_admin_generic_update_and_delete_cannot_rewrite_authorized_sgd_history(): void
    {
        $transfer = $this->acceptedTransfer();
        $controller = app(TransferController::class);
        foreach (['update', 'destroy'] as $action) {
            try {
                if ($action === 'update') {
                    $controller->update(Request::create('/synthetic', 'PATCH', ['status' => 'draft']), $transfer);
                } else {
                    $controller->destroy($transfer);
                }
                $this->fail('Generic edits must not erase customer authorization or dispatch history.');
            } catch (HttpException $exception) {
                $this->assertSame(422, $exception->getStatusCode());
            }
        }
    }

    public static function invalidResponses(): array
    {
        return [
            'balance transfer' => [['quoteType' => 'balance_transfer']],
            'indicative quote' => [['quoteIntent' => 'INDICATIVE']],
            'automatic execution' => [['executionType' => 'automatic']],
            'manual execution' => [['executionType' => 'manual']],
            'wrong lock period' => [['lockPeriod' => '1_hour']],
            'deferred conversion' => [['conversionSchedule' => 'scheduled']],
            'nonzero markup' => [['markupRate' => '0.1']],
            'destination markup' => [['destinationMarkupAmount' => '0.01']],
            'unsupported fees' => [['feeAmount' => '0']],
            'different amount' => [['sourceAmount' => '5.00000001']],
            'missing amount' => [['sourceAmount' => null]],
            'different source' => [['sourceCurrencyCode' => 'EUR']],
            'different destination' => [['destinationCurrencyCode' => 'EUR']],
            'missing id' => [['id' => null]],
            'unsafe id' => [['id' => '../quote']],
            'missing rate' => [['netExchangeRate' => null]],
            'zero rate' => [['netExchangeRate' => '0']],
            'truncated rate' => [['netExchangeRate' => '1.27673808001']],
            'relative expiry' => [['expiryTime' => '+10 minutes']],
            'invalid date' => [['expiryTime' => '2026-02-30T01:43:46Z']],
            'expired at boundary' => [['expiryTime' => '2026-11-02T01:40:00Z']],
        ];
    }

    #[DataProvider('invalidResponses')]
    public function test_invalid_provider_terms_fail_closed(array $changes): void
    {
        $this->expectException(RuntimeException::class);
        NiumPayoutQuoteContract::parse($this->response($changes), 'USD', 'SGD', '5');
    }

    public function test_customer_submit_admin_approval_and_delayed_dispatch_all_reject_expiry(): void
    {
        [$quote, $transfer] = $this->snapshots();
        foreach (['2026-11-02T01:41:00Z', '2026-11-02T01:43:45Z'] as $time) {
            Carbon::setTestNow($time);
            NiumPayoutQuoteContract::assertMatchesTransfer($quote, $transfer, $this->binding());
        }
        foreach (['2026-11-02T01:43:46Z', '2026-11-02T01:50:00Z'] as $time) {
            Carbon::setTestNow($time);
            try {
                NiumPayoutQuoteContract::assertMatchesTransfer($quote, $transfer, $this->binding());
                $this->fail('Expired accepted terms cannot be dispatched.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('fresh quote and customer authorization', $exception->getMessage());
            }
        }
        // Read-only historical presentation is a separate capability from submission.
        NiumPayoutQuoteContract::assertMatchesTransfer($quote, $transfer, $this->binding(), false);
        Http::assertNothingSent();
    }

    public static function invalidBindings(): array
    {
        return [
            [['source_amount' => '6']], [['user_id' => 99]], [['provider_id' => 99]],
            [['target_currency' => 'EUR']], [['net_rate' => '1.99']],
            [['raw_data' => ['provider_fx_type' => 'payout_fx_lock', 'audit_id' => '112']]],
        ];
    }

    #[DataProvider('invalidBindings')]
    public function test_quote_cannot_be_rebound_to_another_instruction_or_legacy_lock(array $change): void
    {
        [$quote, $transfer] = $this->snapshots();
        $quote->fill($change);
        $this->expectException(RuntimeException::class);
        NiumPayoutQuoteContract::assertMatchesTransfer($quote, $transfer, $this->binding());
    }

    public function test_transport_holds_sgd_before_authentication_and_preserves_explicit_hk_classification(): void
    {
        $fence = new \App\Services\Nium\NiumSgdAdmissionFence;
        $this->assertNull($fence->wireKind('POST', '/remittance', ['payout' => [
            'sourceCurrency' => 'USD', 'destinationCurrency' => 'USD', 'payoutMethod' => 'SWIFT',
        ]], null));
        $client = new ProviderHttpClient(new IntegrationProvider(['code' => 'nium']), 'nium');
        try {
            $client->post('/remittance', ['payout' => ['quoteId' => 'synthetic-payout-quote', 'sourceAmount' => 5]]);
            $this->fail('SGD remittance must be held before authentication.');
        } catch (RuntimeException $exception) {
            $this->assertSame(\App\Services\Nium\NiumSgdAdmissionFence::DISPATCH_HOLD, $exception->getMessage());
            Http::assertNothingSent();
        }
    }

    public function test_modern_response_decimal_tokens_and_actual_shorter_expiry_are_preserved(): void
    {
        $body = '{"id":"quote-exact","quoteType":"payout","sourceAmount":5.00,"netExchangeRate":1.276738080,"expiryTime":"2026-11-02T01:43:46Z"}';
        $contract = NiumPayoutQuoteContract::parse(NiumPayoutQuoteContract::decodeResponse($body), 'USD', 'SGD', '5');
        $this->assertSame('1.27673808', $contract['rate']);
        $this->assertSame('2026-11-02T01:43:46.000000Z', $contract['expires_at']);
        $this->expectException(RuntimeException::class);
        NiumPayoutQuoteContract::parse($this->response(['executionType' => 'manual']), 'USD', 'SGD', '5');
    }

    public function test_legacy_hk_audit_contract_still_parses_without_a_modern_quote(): void
    {
        $contract = NiumPayoutFxLockContract::parse([
            'audit_id' => '112', 'fx_rate' => '0.915', 'hold_expiry_at' => '2026-11-02T01:43:46Z',
        ], 'USD', 'EUR', '5');
        $this->assertSame('112', $contract['audit_id']);
        $this->assertArrayNotHasKey('quote_id', $contract);
    }

    public function test_quote_correction_uses_the_requested_currency_pair_without_a_new_corridor_allowlist(): void
    {
        // Request serialization alone never grants corridor admission or dispatch authority.
        $request = NiumPayoutQuoteContract::request('test-customer', 'EUR', 'GBP', '123.45');
        $this->assertSame('EUR', $request['sourceCurrencyCode']);
        $this->assertSame('GBP', $request['destinationCurrencyCode']);
        $this->assertSame('immediate', $request['conversionSchedule']);
        $this->assertSame('at_conversion_time', $request['executionType']);
        $this->assertSame('15_mins', $request['lockPeriod']);
    }

    public function test_numeric_destination_markup_is_preserved_exactly_and_rejected_when_nonzero(): void
    {
        $body = json_encode($this->response(), JSON_THROW_ON_ERROR);
        $body = substr($body, 0, -1).',"destinationMarkupAmount":1e-8}';
        $response = NiumPayoutQuoteContract::decodeResponse($body);
        $this->assertSame('0.00000001', $response['destinationMarkupAmount']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unapproved FX markup');
        NiumPayoutQuoteContract::parse($response, 'USD', 'SGD', '5');
    }
}
