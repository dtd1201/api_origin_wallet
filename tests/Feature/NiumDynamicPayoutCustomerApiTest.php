<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Wallet\Concurrency\OwnerFirst;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutAmountMode;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutInstruction;
use App\Models\ApiToken;
use App\Models\FxQuote;
use App\Models\LedgerEntry;
use App\Models\NiumDynamicIntent;
use App\Models\NiumDynamicPayoutOutbox;
use App\Models\NiumDynamicReservation;
use App\Models\User;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutEvidenceSource;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutDispatcher;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutTransport;
use App\Services\Nium\DynamicConfirmation\DynamicTransferConfirmationService;
use App\Services\Nium\DynamicConfirmation\LockedPayoutRecords;
use App\Services\Nium\DynamicConfirmation\OriginDynamicPayoutEvidenceSource;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesDynamicConfirmationFixtures;
use Tests\Concerns\CreatesFinalizedUsdConfirmationFixtures;
use Tests\Support\DynamicConfirmationDatabaseTestCase;
use Tests\Support\DynamicPayoutTransportStub;

/** Offline only. The success-path evidence stub is installed solely in explicit tests below. */
final class NiumDynamicPayoutCustomerApiTest extends DynamicConfirmationDatabaseTestCase
{
    use CreatesDynamicConfirmationFixtures;
    use CreatesFinalizedUsdConfirmationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDynamicFixtures('2000');
        // These boundary cases exercise unavailable acquisition, independent of operator env.
        config(['services.nium.payout_fx_enabled' => false,
            'services.nium.base_url' => 'https://gatewaysandbox.nium.com',
            'nium_dynamic_confirmation.automatic_dispatch_enabled' => true,
            'nium_dynamic_payouts.reconciliation_enabled' => true]);
        $this->wallet->update(['currency' => 'USD']);
        $this->source->rule = 'LOCAL_SINGLE_INTENT_PER_QUOTE';
        $this->source->versions['remittance_instruction'] = 'fixture-purpose-codes-v1';
        $this->source->review = $this->usdReview(changes: [
            'instruction' => new PayoutInstruction('IR005', 'Salary', 'Fixture invoice')]);
        ApiToken::query()->create(['user_id' => $this->owner->id, 'name' => 'offline-customer',
            'token_hash' => hash('sha256', 'offline-dynamic-customer'), 'expires_at' => now()->addDay()]);
        $this->withToken('offline-dynamic-customer');
    }

    private function path(string $suffix = ''): string
    {
        return '/api/user/users/'.$this->owner->id.'/nium/dynamic-payouts'.$suffix;
    }

    private function submission(): array
    {
        return ['provider_account_id' => $this->account->id, 'beneficiary_id' => $this->beneficiary->id,
            'balance_id' => $this->wallet->id, 'client_reference' => 'customer-dynamic-1',
            'accepted_fingerprint' => $this->source->review->fingerprint()];
    }

    private function noReservation(): void
    {
        $this->assertSame(0, NiumDynamicIntent::query()->count());
        $this->assertSame(0, NiumDynamicReservation::query()->count());
        $this->assertSame(0, NiumDynamicPayoutOutbox::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
        $this->assertSame('0.00000000', $this->wallet->fresh()->reserved_balance);
    }

    public function test_owned_options_report_disabled_quotes_without_inventing_executable_corridors(): void
    {
        $this->getJson($this->path('/options?beneficiary_id='.$this->beneficiary->id))->assertOk()
            ->assertJsonPath('beneficiary.id', $this->beneficiary->id)
            ->assertJsonPath('status', 'UNAVAILABLE')->assertJsonPath('supported_corridors', [])
            ->assertJsonPath('destination_currencies', [])
            ->assertJsonPath('reason_codes', ['dynamic_provider_quote_disabled'])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->noReservation();
    }

    public function test_authentication_route_owner_and_beneficiary_owner_are_enforced(): void
    {
        $other = User::factory()->create();
        $foreign = $this->beneficiary->replicate();
        $foreign->user_id = $other->id;
        $foreign->external_beneficiary_id = 'other-beneficiary';
        $foreign->save();
        $this->getJson($this->path('/options?beneficiary_id='.$foreign->id))->assertNotFound();
        $this->getJson('/api/user/users/'.$other->id.'/nium/dynamic-payouts/options?beneficiary_id='.$foreign->id)->assertForbidden();
        $this->getJson('/api/user/users/0'.$this->owner->id.'/nium/dynamic-payouts/options?beneficiary_id='.$this->beneficiary->id)->assertForbidden();
        $this->withHeader('Authorization', '')->getJson($this->path('/options?beneficiary_id='.$this->beneficiary->id))->assertUnauthorized();
        $this->noReservation();
    }

    public static function modes(): array
    {
        return [['SOURCE_AMOUNT', '1000'], ['DESTINATION_AMOUNT', '784']];
    }

    #[DataProvider('modes')]
    public function test_both_amount_modes_deny_missing_authority_without_a_fake_quote(string $mode, string $amount): void
    {
        $response = $this->postJson($this->path('/quotes'), ['beneficiary_id' => $this->beneficiary->id,
            'destination_currency' => 'GBP', 'amount_mode' => $mode, 'entered_amount' => $amount]);
        $response->assertStatus(503)->assertJsonPath('status', 'UNAVAILABLE')
            ->assertJsonPath('reason_codes', OriginDynamicPayoutEvidenceSource::MISSING_DEPENDENCIES);
        $this->assertArrayNotHasKey('quote_id', $response->json());
        $this->assertArrayNotHasKey('fx_rate', $response->json());
        $this->assertSame(0, FxQuote::query()->count());
        $this->noReservation();
    }

    public function test_currency_precision_client_financial_terms_and_insufficient_funds_are_rejected(): void
    {
        $input = ['beneficiary_id' => $this->beneficiary->id, 'destination_currency' => 'GBP',
            'amount_mode' => 'SOURCE_AMOUNT', 'entered_amount' => '1000'];
        foreach ([['entered_amount' => 1000], ['entered_amount' => '1000.001'], ['entered_amount' => '20'],
            ['destination_currency' => 'EUR'], ['fx_rate' => '0.8']] as $change) {
            $this->postJson($this->path('/quotes'), array_replace($input, $change))->assertUnprocessable();
        }
        $this->wallet->update(['available_balance' => '999']);
        $this->postJson($this->path('/quotes'), $input)->assertStatus(409)
            ->assertJsonPath('reason_codes', ['dynamic_insufficient_available_funds']);
        $this->noReservation();
    }

    public static function excludedLanes(): array
    {
        return [['USD', 'SWIFT', 'dynamic_provider_quote_disabled'],
            ['SGD', 'LOCAL', 'dynamic_provider_quote_disabled'], ['GBP', 'SWIFT', 'dynamic_provider_quote_disabled']];
    }

    #[DataProvider('excludedLanes')]
    public function test_unverified_dynamic_options_have_precise_reasons_while_legacy_remains_separate(string $currency, string $rail, string $reason): void
    {
        $this->beneficiary->update(['currency' => $currency, 'payout_method' => $rail]);
        $this->getJson($this->path('/options?beneficiary_id='.$this->beneficiary->id))->assertOk()
            ->assertJsonPath('destination_currencies', [])->assertJsonPath('reason_codes', [$reason]);
        $this->noReservation();
    }

    public function test_wrong_wallet_and_ineligible_beneficiary_remain_unavailable(): void
    {
        $this->wallet->update(['external_account_id' => 'other-wallet']);
        $this->getJson($this->path('/options?beneficiary_id='.$this->beneficiary->id))->assertOk()
            ->assertJsonPath('reason_codes', ['dynamic_usd_wallet_unavailable']);
        $this->beneficiary->update(['status' => 'blocked']);
        $this->getJson($this->path('/options?beneficiary_id='.$this->beneficiary->id))->assertOk()
            ->assertJsonPath('reason_codes', ['dynamic_beneficiary_or_customer_ineligible']);
        $this->noReservation();
    }

    public function test_review_rejects_expiry_and_never_promotes_generic_quote_metadata_to_authority(): void
    {
        $quote = FxQuote::query()->create(['user_id' => $this->owner->id, 'provider_id' => $this->account->provider_id,
            'quote_ref' => 'legacy-quote', 'source_currency' => 'USD', 'target_currency' => 'GBP',
            'source_amount' => '980', 'target_amount' => '784', 'fee_amount' => '20', 'net_rate' => '0.8',
            'expires_at' => now()->addHour(), 'raw_data' => ['execution_type' => 'manual',
                'calculationReference' => 'client-forged', 'verified' => true]]);
        $input = ['beneficiary_id' => $this->beneficiary->id, 'fx_quote_id' => $quote->id,
            'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']];
        $this->postJson($this->path('/reviews'), $input)->assertStatus(503)
            ->assertJsonPath('reason_codes', OriginDynamicPayoutEvidenceSource::MISSING_DEPENDENCIES)
            ->assertJsonMissingPath('errors');
        $this->postJson($this->path('/reviews'), array_replace($input, [
            'instruction' => array_replace($input['instruction'], ['comment' => str_repeat('é', 128)]),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['instruction']);
        $quote->update(['target_currency' => 'EUR']);
        $this->postJson($this->path('/reviews'), $input)->assertStatus(409)
            ->assertJsonPath('reason_codes', ['dynamic_quote_binding_changed']);
        $quote->update(['target_currency' => 'GBP']);
        $quote->update(['expires_at' => now()->subMinute()]);
        $this->postJson($this->path('/reviews'), $input)->assertStatus(409)->assertJsonPath('reason_codes', ['dynamic_quote_expired']);
        $quote->update(['user_id' => User::factory()->create()->id]);
        $this->postJson($this->path('/reviews'), $input)->assertNotFound();
        $this->noReservation();
    }

    public static function invalidReviewQuoteSelectors(): array
    {
        $uuid = '11111111-1111-4111-8111-111111111111';

        return [
            'both populated' => [['fx_quote_id' => 1, 'dynamic_quote_id' => $uuid], ['fx_quote_id', 'dynamic_quote_id']],
            'neither supplied' => [[], ['fx_quote_id', 'dynamic_quote_id']],
            'null legacy sibling' => [['fx_quote_id' => null, 'dynamic_quote_id' => $uuid], ['fx_quote_id']],
            'empty legacy sibling' => [['fx_quote_id' => '', 'dynamic_quote_id' => $uuid], ['fx_quote_id']],
            'null dynamic sibling' => [['fx_quote_id' => 1, 'dynamic_quote_id' => null], ['dynamic_quote_id']],
            'empty dynamic sibling' => [['fx_quote_id' => 1, 'dynamic_quote_id' => ''], ['dynamic_quote_id']],
            'legacy identifier is an array' => [['fx_quote_id' => [1]], ['fx_quote_id']],
            'legacy identifier is not positive' => [['fx_quote_id' => 0], ['fx_quote_id']],
            'legacy identifier is not an integer' => [['fx_quote_id' => 'invalid'], ['fx_quote_id']],
            'dynamic identifier is not a uuid' => [['dynamic_quote_id' => 'invalid'], ['dynamic_quote_id']],
            'dynamic identifier is an array' => [['dynamic_quote_id' => [$uuid]], ['dynamic_quote_id']],
        ];
    }

    #[DataProvider('invalidReviewQuoteSelectors')]
    public function test_review_quote_selectors_return_validation_errors_before_resource_lookup(array $selectors, array $errors): void
    {
        $this->postJson($this->path('/reviews'), [
            'beneficiary_id' => $this->beneficiary->id,
            ...$selectors,
            'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice'],
        ])->assertUnprocessable()->assertJsonValidationErrors($errors);
        $this->noReservation();
    }

    public function test_review_rejects_unexpected_top_level_and_instruction_fields(): void
    {
        $input = ['beneficiary_id' => $this->beneficiary->id, 'fx_quote_id' => 1,
            'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']];
        $this->postJson($this->path('/reviews'), [...$input, 'fee' => '0'])
            ->assertUnprocessable()->assertJsonValidationErrors(['request']);
        $this->postJson($this->path('/reviews'), [...$input,
            'instruction' => [...$input['instruction'], 'unexpected' => 'value']])
            ->assertUnprocessable()->assertJsonValidationErrors(['instruction']);
        $this->noReservation();
    }

    public function test_production_binding_denies_missing_authority_even_with_a_valid_fixture_fingerprint(): void
    {
        $this->assertInstanceOf(OriginDynamicPayoutEvidenceSource::class, app(DynamicPayoutEvidenceSource::class));
        $this->postJson($this->path(), $this->submission())->assertStatus(503)
            ->assertJsonPath('reason_codes', OriginDynamicPayoutEvidenceSource::MISSING_DEPENDENCIES);
        $this->noReservation();
    }

    public function test_origin_evidence_checks_transaction_and_exact_local_ownership(): void
    {
        $records = new LockedPayoutRecords($this->owner, $this->account->provider, $this->account,
            $this->beneficiary, $this->wallet, $this->owner->kycProfile);
        try {
            app(DynamicPayoutEvidenceSource::class)->revalidate($records, $this->at());
            $this->fail('Evidence was accepted outside a transaction.');
        } catch (RuntimeException $exception) {
            $this->assertInstanceOf(\App\Services\Wallet\Concurrency\OwnerLockContractViolation::class, $exception);
        }
        $this->wallet->user_id = User::factory()->create()->id;
        $this->expectExceptionMessage('dynamic_local_identity_unverified');
        OwnerFirst::users([$this->owner->id], fn () => app(DynamicPayoutEvidenceSource::class)->revalidate($records, $this->at()));
    }

    public function test_disabled_flags_do_not_reserve_and_owned_status_remains_readable(): void
    {
        config(['nium_dynamic_quotes.quote_contract_enabled' => false, 'nium_dynamic_confirmation.enabled' => false]);
        $this->getJson($this->path('/options?beneficiary_id='.$this->beneficiary->id))->assertOk()
            ->assertJsonPath('reason_codes', ['dynamic_quote_contract_disabled']);
        $this->postJson($this->path(), $this->submission())->assertStatus(503)
            ->assertJsonPath('reason_codes', ['dynamic_payout_submission_disabled']);
        $this->noReservation();
    }

    public static function disabledProcessing(): array
    {
        return [['nium_dynamic_confirmation.automatic_dispatch_enabled'], ['nium_dynamic_payouts.reconciliation_enabled']];
    }

    #[DataProvider('disabledProcessing')]
    public function test_customer_submit_cannot_strand_a_reservation_when_automatic_processing_is_disabled(string $flag): void
    {
        $this->app->instance(DynamicTransferConfirmationService::class, $this->confirmation);
        config([$flag => false]);
        $this->postJson($this->path(), $this->submission())->assertStatus(503)
            ->assertJsonPath('reason_codes', ['dynamic_payout_submission_disabled']);
        $this->noReservation();
    }

    #[DataProvider('modes')]
    public function test_spend_get_recovery_compatibility_cannot_replace_authoritative_customer_review(string $mode, string $amount): void
    {
        // A synthetic fingerprint and verified GET cannot replace the real Origin
        // evidence source. Do not install the success-path confirmation stub here.
        $this->assertInstanceOf(OriginDynamicPayoutEvidenceSource::class, app(DynamicPayoutEvidenceSource::class));
        $this->source->review = $this->usdReview(PayoutAmountMode::from($mode), [
            'instruction' => new PayoutInstruction('IR005', 'Salary', 'Invoice')]);
        config(['services.nium.base_url' => 'https://api.spend.nium.com',
            'services.nium.transfer_endpoint' => '/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/remittance',
            'nium_dynamic_payouts.live_automatic_dispatch_authorized' => true,
            'nium_dynamic_payouts.operator_authorization_reference' => 'synthetic-operator-reference']);

        $this->postJson($this->path(), $this->submission())->assertStatus(503)
            ->assertJsonPath('reason_codes', OriginDynamicPayoutEvidenceSource::MISSING_DEPENDENCIES);
        $this->noReservation();
        // The database test base also asserts no HTTP, jobs or queued dispatch.
    }

    #[DataProvider('modes')]
    public function test_server_fixture_confirmation_reserves_once_without_controller_dispatch_or_admin_approval(string $mode, string $amount): void
    {
        $this->source->review = $this->usdReview(PayoutAmountMode::from($mode), ['instruction' => new PayoutInstruction('IR005', 'Salary', 'Invoice')]);
        // Test-only authenticated evidence stand-in; application binding remains the rejecting Origin source.
        $this->app->instance(DynamicTransferConfirmationService::class, $this->confirmation);
        config(['services.nium.base_url' => 'https://gatewaysandbox.nium.com',
            'nium_dynamic_confirmation.automatic_dispatch_enabled' => true,
            'nium_dynamic_payouts.reconciliation_enabled' => true]);
        $input = $this->submission();
        $first = $this->postJson($this->path(), $input)->assertCreated()
            ->assertJsonPath('status', 'queued')->assertJsonPath('total_usd_debit', '1000.00000000')
            ->assertJsonPath('principal.amount', '980')->assertJsonPath('included_fee.amount', '20')
            ->assertJsonPath('amount_mode', $mode)->assertJsonPath('financial_reconciled', false);
        $this->postJson($this->path(), $input)->assertOk()->assertJsonPath('id', $first->json('id'));
        $this->assertSame(1, NiumDynamicIntent::query()->count());
        $this->assertSame(1, NiumDynamicReservation::query()->count());
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'hold')->count());
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame(0, NiumDynamicPayoutOutbox::query()->sole()->attempts);
        $this->assertSame('1000.00000000', $this->wallet->fresh()->reserved_balance);
        $this->postJson($this->path(), [...$input, 'accepted_fingerprint' => str_repeat('a', 64)])->assertStatus(409);
        config(['nium_dynamic_confirmation.enabled' => false, 'nium_dynamic_quotes.quote_contract_enabled' => false]);
        $status = $this->getJson($this->path('/'.$first->json('id')))->assertOk()->assertJsonPath('status', 'queued');
        foreach (['terms_fingerprint', 'consent_binding', 'admission_evidence', 'confirmed_terms', 'claim_token',
            'provider_reference', 'financial_evidence', 'exception_code'] as $private) {
            $this->assertArrayNotHasKey($private, $status->json());
        }
        $this->withToken('offline-dynamic-customer')->getJson($this->path('/00000000-0000-4000-8000-000000000001'))->assertNotFound();
    }

    public function test_changed_remittance_instruction_cannot_use_old_customer_consent(): void
    {
        $input = $this->submission();
        $this->app->instance(DynamicTransferConfirmationService::class, $this->confirmation);
        $this->source->review = $this->usdReview(changes: ['instruction' => new PayoutInstruction('IR005', 'Salary', 'Changed invoice')]);
        $this->postJson($this->path(), $input)->assertStatus(409)
            ->assertJsonPath('reason_codes', ['payout_confirmation_binding_mismatch']);
        $this->noReservation();
    }

    #[DataProvider('modes')]
    public function test_customer_submit_is_automatically_dispatched_by_existing_worker_without_admin_approval(string $mode, string $amount): void
    {
        // This tests the HTTP -> durable outbox -> automatic worker path with the
        // existing test-only evidence source. It does not supply a production Review.
        $this->source->review = $this->usdReview(PayoutAmountMode::from($mode), [
            'instruction' => new PayoutInstruction('IR005', 'Salary', 'Invoice')]);
        $this->app->instance(DynamicTransferConfirmationService::class, $this->confirmation);
        $wire = new DynamicPayoutTransportStub;
        $wire->batch = ['records' => [], 'response_sha256' => [str_repeat('a', 64)], 'observed_at' => now()->toISOString()];
        $this->app->instance(DynamicPayoutTransport::class, $wire);
        $this->app->instance(DynamicPayoutDispatcher::class, new DynamicPayoutDispatcher($this->confirmation, $wire));
        config(['services.nium.base_url' => 'https://gatewaysandbox.nium.com',
            'services.nium.client_id' => 'fixture-client', 'wallet.transfer_controls.require_admin_approval' => true,
            'nium_dynamic_confirmation.automatic_dispatch_enabled' => true,
            'nium_dynamic_payouts.reconciliation_enabled' => true]);

        $input = $this->submission();
        $response = $this->postJson($this->path(), $input)->assertCreated()->assertJsonPath('status', 'queued');
        $this->postJson($this->path(), $input)->assertOk()->assertJsonPath('id', $response->json('id'));
        $this->artisan('nium:process-dynamic-payouts', ['--limit' => 1])->assertSuccessful();
        $this->artisan('nium:process-dynamic-payouts', ['--limit' => 1])->assertSuccessful();
        $this->postJson($this->path(), $input)->assertOk()->assertJsonPath('id', $response->json('id'));

        $this->assertSame(1, $wire->submissions);
        $this->assertSame(1, NiumDynamicIntent::query()->count());
        $this->assertSame(1, NiumDynamicPayoutOutbox::query()->sole()->attempts);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'hold')->count());
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame('1000.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertDatabaseCount('transfers', 0);
    }

    public function test_lost_response_or_browser_identity_recovers_owned_transfer_without_new_admission(): void
    {
        $intent = $this->confirmDynamic('recover-exact-reference');
        config(['nium_dynamic_confirmation.enabled' => false,
            'nium_dynamic_confirmation.automatic_dispatch_enabled' => false,
            'nium_dynamic_quotes.quote_contract_enabled' => false,
            'nium_dynamic_quotes.confirmation_contract_enabled' => false,
            'nium_dynamic_payouts.reconciliation_enabled' => false]);
        $this->travelTo($this->at('02:00:00')); // Recovery outlives quote and Review expiry.
        $expected = $this->getJson($this->path('/'.$intent->id))->assertOk()->json();
        $recovered = $this->getJson($this->path('?client_reference=recover-exact-reference'))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $intent->id)
            ->assertJsonPath('has_more', false)->assertJsonPath('next_page', null);
        $this->assertSame($expected, $recovered->json('data.0'));
        foreach (['terms_fingerprint', 'accepted_fingerprint', 'reference_sha256', 'client_reference', 'consent_binding',
            'admission_evidence', 'confirmed_terms', 'claim_token', 'provider_reference', 'financial_evidence', 'exception_code'] as $private) {
            $this->assertArrayNotHasKey($private, $recovered->json('data.0'));
        }
        $this->getJson($this->path())->assertOk()->assertJsonPath('data.0.id', $intent->id);
        $this->getJson($this->path('?client_reference=missing-reference'))->assertOk()->assertJsonPath('data', []);
        $this->getJson($this->path('?client_reference=Recover-exact-reference'))->assertOk()->assertJsonPath('data', []);
        $this->assertSame(1, NiumDynamicIntent::query()->count());
        $this->assertSame(1, NiumDynamicReservation::query()->count());
        $this->assertSame(1, NiumDynamicPayoutOutbox::query()->count());
        $this->assertSame(0, NiumDynamicPayoutOutbox::query()->sole()->attempts);
        $this->assertSame(1, LedgerEntry::query()->count());
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'hold')->count());
        $this->assertSame('1000.00000000', $this->wallet->fresh()->reserved_balance);
    }

    public function test_recovery_collection_paginates_without_duplicates_for_equal_creation_times(): void
    {
        $first = $this->confirmDynamic('recovery-page-one');
        $this->source->review = $this->usdReview(changes: [
            'response' => ['id' => 'fixture-finalized-quote-two'],
            'instruction' => new PayoutInstruction('IR005', 'Salary', 'Second invoice')]);
        $second = $this->confirmDynamic('recovery-page-two');
        $pageOne = $this->getJson($this->path('?per_page=1'))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('page', 1)->assertJsonPath('per_page', 1)
            ->assertJsonPath('has_more', true)->assertJsonPath('next_page', 2);
        $pageTwo = $this->getJson($this->path('?per_page=1&page=2'))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('page', 2)->assertJsonPath('has_more', false)->assertJsonPath('next_page', null);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], [$pageOne->json('data.0.id'), $pageTwo->json('data.0.id')]);
        $this->getJson($this->path('?per_page=1&page=3'))->assertOk()->assertJsonPath('data', []);
        $this->assertSame(2, NiumDynamicIntent::query()->count());
        $this->assertSame(2, LedgerEntry::query()->where('entry_type', 'hold')->count());
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame(0, (int) NiumDynamicPayoutOutbox::query()->sum('attempts'));
    }

    public function test_recovery_preserves_unknown_provider_outcome_without_dispatch_or_another_debit(): void
    {
        $intent = $this->confirmDynamic('recover-unknown-outcome');
        $wire = new DynamicPayoutTransportStub;
        $wire->afterWire = static fn () => throw new RuntimeException('synthetic response lost after dispatch');
        $dispatcher = new DynamicPayoutDispatcher($this->confirmation, $wire);
        $outbox = NiumDynamicPayoutOutbox::query()->sole();
        $dispatcher->dispatch($outbox->id);
        $this->assertSame('ambiguous', $outbox->fresh()->state);
        config(['nium_dynamic_confirmation.automatic_dispatch_enabled' => false]);
        for ($read = 0; $read < 2; $read++) {
            $this->getJson($this->path('?client_reference=recover-unknown-outcome'))->assertOk()
                ->assertJsonPath('data.0.id', $intent->id)
                ->assertJsonPath('data.0.status', 'pending_provider_confirmation')
                ->assertJsonPath('data.0.financial_reconciled', false);
        }
        $this->assertSame(1, $wire->submissions);
        $this->assertSame(0, $wire->reads);
        $this->assertSame(1, $outbox->fresh()->attempts);
        $this->assertNull($outbox->fresh()->provider_reference);
        $this->assertSame(1, NiumDynamicIntent::query()->count());
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'hold')->count());
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame('1000.00000000', $this->wallet->fresh()->reserved_balance);
    }

    public static function invalidRecoveryQueries(): array
    {
        return [['page=0'], ['page=1000001'], ['per_page=0'], ['per_page=51'], ['per_page=1.5'],
            ['client_reference='], ['client_reference=bad%20reference'], ['user_id=999'], ['accepted_fingerprint=untrusted']];
    }

    #[DataProvider('invalidRecoveryQueries')]
    public function test_recovery_rejects_unbounded_or_unexpected_query_fields(string $query): void
    {
        $this->getJson($this->path('?'.$query))->assertUnprocessable();
        $this->noReservation();
    }

    public function test_recovery_authentication_and_canonical_route_owner_are_enforced(): void
    {
        $this->getJson('/api/user/users/0'.$this->owner->id.'/nium/dynamic-payouts')->assertForbidden();
        $this->withHeader('Authorization', '')->getJson($this->path())->assertUnauthorized();
        $this->noReservation();
    }

    public function test_customer_cannot_read_another_customers_existing_intent(): void
    {
        $intent = $this->confirmDynamic();
        $other = User::factory()->create();
        ApiToken::query()->create(['user_id' => $other->id, 'name' => 'offline-other-customer',
            'token_hash' => hash('sha256', 'offline-other-dynamic-customer'), 'expires_at' => now()->addDay()]);
        $this->withToken('offline-other-dynamic-customer');
        $otherPath = '/api/user/users/'.$other->id.'/nium/dynamic-payouts';
        $this->getJson($otherPath)->assertOk()->assertJsonPath('data', []);
        $this->getJson($otherPath.'?client_reference=fixture-client-reference')->assertOk()->assertJsonPath('data', []);
        $this->getJson($this->path())->assertForbidden();
        $this->getJson('/api/user/users/'.$other->id.'/nium/dynamic-payouts/'.$intent->id)->assertNotFound();
        $this->getJson($this->path('/'.$intent->id))->assertForbidden();
    }
}
