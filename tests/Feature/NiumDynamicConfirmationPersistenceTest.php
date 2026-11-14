<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutAmountMode;
use App\Models\AmlScreening;
use App\Models\Balance;
use App\Models\LedgerEntry;
use App\Models\NiumDynamicIntent;
use App\Models\NiumDynamicPayoutOutbox;
use App\Models\NiumDynamicReservation;
use App\Models\NiumRfiCase;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Nium\DynamicConfirmation\DynamicReservationLedger;
use App\Services\Nium\DynamicConfirmation\DynamicTransferConfirmationService;
use App\Services\Nium\NiumTransferService;
use App\Services\Wallet\TransferApprovalService;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesDynamicConfirmationFixtures;
use Tests\Support\DynamicConfirmationDatabaseTestCase;
use TypeError;

final class NiumDynamicConfirmationPersistenceTest extends DynamicConfirmationDatabaseTestCase
{
    use CreatesDynamicConfirmationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDynamicFixtures();
    }

    public static function modes(): array
    {
        return [[PayoutAmountMode::Source], [PayoutAmountMode::Destination]];
    }

    public function test_shared_fixture_persists_required_kyc_address_and_linked_clear_screening(): void
    {
        // Read from PostgreSQL rather than relying on the relation's in-memory attributes.
        $kyc = $this->owner->kycProfile()->sole();
        $this->assertSame('approved', $kyc->status);
        $this->assertSame('individual', $kyc->applicant_type);
        $this->assertSame('Fixture Customer', $kyc->legal_name);
        $this->assertSame('1 Fixture Road', $kyc->address_line1);
        $this->assertSame('London', $kyc->city);
        $this->assertSame('SW1A 1AA', $kyc->postal_code);
        $this->assertSame('GB', $kyc->country_code);
        $screening = AmlScreening::query()->where('kyc_profile_id', $kyc->id)->sole();
        $this->assertSame($this->owner->id, $screening->user_id);
        $this->assertSame('completed', $screening->status);
        $this->assertSame('clear', $screening->compliance_decision);
        $this->assertEmptyDynamicState();
    }

    #[DataProvider('modes')]
    public function test_atomic_confirmation_uses_exact_total_debit_with_fees(PayoutAmountMode $mode): void
    {
        $this->source->review = $this->dynamicReview(mode: $mode);
        $fingerprint = $this->source->review->fingerprint();
        $intent = $this->confirmDynamic();

        $this->assertSame(1, NiumDynamicIntent::query()->count());
        $this->assertSame(1, NiumDynamicReservation::query()->count());
        $this->assertSame(1, NiumDynamicPayoutOutbox::query()->count());
        $this->assertSame(0, Transfer::query()->count());
        $this->assertSame('148.00000000', $this->wallet->fresh()->available_balance);
        $this->assertSame('102.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame('250.00000000', $this->wallet->fresh()->ledger_balance);
        $this->assertSame('-102.00000000', LedgerEntry::query()->sole()->amount);
        $this->assertSame($fingerprint, $intent->fresh()->terms_fingerprint);
        $this->assertSame($fingerprint, hash('sha256', json_encode($intent->fresh()->confirmed_terms, JSON_THROW_ON_ERROR)));
        $this->assertSame('2', $intent->confirmed_terms['financial_terms']['transaction_fee']['amount']);
        $this->assertSame($mode->value, $intent->amount_mode);
        $this->assertSame((string) $this->owner->id, $intent->consent_binding['actor']);
        $this->assertSame('fixture-v1', $intent->admission_evidence['versions']['quote_consumption']);
        $this->assertFalse(NiumDynamicPayoutOutbox::query()->sole()->isDispatchable());
        $this->assertFalse(NiumDynamicPayoutOutbox::query()->sole()->dispatchable);
    }

    public function test_duplicate_confirmation_is_read_only_even_after_expiry(): void
    {
        $intent = $this->confirmDynamic();
        $this->travelTo(new DateTimeImmutable('2030-01-02T00:00:00Z'));
        $retry = $this->confirmDynamic();

        $this->assertSame($intent->id, $retry->id);
        $this->assertSame(1, $this->source->calls);
        $this->assertSame(1, LedgerEntry::query()->count());
        $this->assertSame(1, NiumDynamicReservation::query()->count());
        $this->assertSame('102.00000000', $this->wallet->fresh()->reserved_balance);
    }

    public function test_conflicting_retry_cannot_replace_terms_or_reserve_again(): void
    {
        $intent = $this->confirmDynamic();
        $this->source->review = $this->dynamicReview(fee: '3');

        $this->rejected(fn () => $this->confirmDynamic(), 'dynamic_confirmation_idempotency_conflict');

        $this->assertSame($intent->terms_fingerprint, $intent->fresh()->terms_fingerprint);
        $this->assertSame(1, LedgerEntry::query()->count());
        $this->assertSame('102.00000000', $this->wallet->fresh()->reserved_balance);
    }

    public function test_quote_cannot_be_consumed_by_another_reference_even_after_cancellation(): void
    {
        $intent = $this->confirmDynamic();
        $this->confirmation->cancel($this->owner, $intent->id);

        $this->rejected(fn () => $this->confirmDynamic('another-reference'), 'dynamic_quote_already_consumed');

        $this->assertSame(1, NiumDynamicIntent::query()->count());
        $this->assertSame(2, LedgerEntry::query()->count());
        $this->assertSame('0.00000000', $this->wallet->fresh()->reserved_balance);
    }

    public function test_insufficient_funds_roll_back_intent_and_every_reservation_mutation(): void
    {
        $this->wallet->update(['available_balance' => '101.99', 'ledger_balance' => '101.99']);

        $this->rejected(fn () => $this->confirmDynamic(), 'dynamic_insufficient_available_funds');

        $this->assertEmptyDynamicState('101.99000000');
    }

    public function test_expired_quote_cannot_be_confirmed(): void
    {
        // Keep admission, ownership, beneficiary, corridor and pricing evidence
        // valid until 01:00; only the quote expires at 00:30.
        $this->source->review = $this->dynamicReview(quoteExpiry: '2030-01-01T00:30:00Z');
        $review = $this->source->review;
        $control = $review->confirm((string) $this->owner->id, $review->fingerprint(),
            new DateTimeImmutable('2030-01-01T00:29:59Z'));
        $this->assertNotNull($control->confirmedAt);
        $this->travelTo(new DateTimeImmutable('2030-01-01T00:30:00Z'));

        $this->rejected(fn () => $this->confirmDynamic(), 'payout_quote_expired');

        $this->assertSame(1, $this->source->calls);
        $this->assertEmptyDynamicState();
    }

    public function test_quote_expiring_during_validation_cannot_be_persisted(): void
    {
        $this->source->duringValidation = fn () => $this->travelTo(new DateTimeImmutable('2030-01-01T01:00:00Z'));

        $this->rejected(fn () => $this->confirmDynamic(), 'dynamic_confirmation_evidence_expired');

        $this->assertEmptyDynamicState();
    }

    public function test_changed_financial_terms_require_new_consent(): void
    {
        $oldFingerprint = $this->source->review->fingerprint();
        $this->source->review = $this->dynamicReview(fee: '3');

        $this->rejected(fn () => $this->confirmDynamic(fingerprint: $oldFingerprint), 'payout_confirmation_binding_mismatch');

        $this->assertEmptyDynamicState();
    }

    public static function ineligibleChanges(): array
    {
        return [
            'blocked beneficiary' => ['beneficiary', ['status' => 'blocked']],
            'changed registered bank account' => ['beneficiary', ['account_number' => 'CHANGED']],
            'changed registration id' => ['beneficiary', ['external_beneficiary_id' => 'CHANGED']],
            'changed provider routing detail' => ['beneficiary', ['raw_data' => ['routing' => 'CHANGED']]],
            'wrong wallet currency' => ['wallet', ['currency' => 'USD']],
            'wrong wallet binding' => ['wallet', ['external_account_id' => 'OTHER-WALLET']],
            'missing authoritative wallet amount' => ['wallet', ['raw_data' => []]],
            'blocked account' => ['account', ['status' => 'blocked']],
            'account RFI' => ['account', ['rfi_status' => 'requested']],
            'missing RFI' => ['account', ['rfi_status' => null]],
            'unknown RFI' => ['account', ['rfi_status' => 'unknown']],
            'pending RFI' => ['account', ['rfi_status' => 'pending']],
            'failed RFI' => ['account', ['rfi_status' => 'failed']],
            'responded RFI' => ['account', ['rfi_status' => 'responded']],
            'account ODD' => ['account', ['odd_status' => 'pending']],
            'unknown ODD' => ['account', ['odd_status' => 'unknown']],
            'missing ODD' => ['account', ['odd_status' => null]],
            'failed ODD' => ['account', ['odd_status' => 'failed']],
            'unverified ODD ineligible' => ['account', ['odd_status' => 'odd_ineligible']],
            'unverified ODD not due' => ['account', ['odd_status' => 'odd_not_due']],
            'unverified ODD completed' => ['account', ['odd_status' => 'completed']],
            'account compliance' => ['account', ['compliance_status' => 'blocked']],
            'missing compliance' => ['account', ['compliance_status' => null]],
            'unknown compliance' => ['account', ['compliance_status' => 'unknown']],
            'pending compliance' => ['account', ['compliance_status' => 'pending']],
            'failed compliance' => ['account', ['compliance_status' => 'failed']],
            'unverified compliance completed' => ['account', ['compliance_status' => 'completed']],
            'unreconciled account' => ['account', ['reconciliation_status' => 'pending']],
            'revoked customer KYC' => ['owner', ['kyc_status' => 'unverified']],
        ];
    }

    #[DataProvider('ineligibleChanges')]
    public function test_beneficiary_wallet_and_account_rejections_are_atomic(string $record, array $changes): void
    {
        $this->{$record}->update($changes);

        $this->rejected(fn () => $this->confirmDynamic());

        $this->assertEmptyDynamicState();
    }

    public function test_wrong_beneficiary_owner_is_rejected(): void
    {
        $other = User::factory()->create();
        $this->beneficiary->update(['user_id' => $other->id]);

        $this->rejected(fn () => $this->confirmDynamic());

        $this->assertEmptyDynamicState();
    }

    public function test_current_aml_hold_is_rejected(): void
    {
        AmlScreening::query()->update(['compliance_decision' => 'pending_review']);

        $this->rejected(fn () => $this->confirmDynamic(), 'dynamic_compliance_hold_or_evidence_unavailable');

        $this->assertEmptyDynamicState();
    }

    public function test_current_rfi_hold_is_rejected(): void
    {
        NiumRfiCase::query()->create(['provider_id' => $this->account->provider_id,
            'user_provider_account_id' => $this->account->id, 'scope' => 'customer',
            'provider_reference_fingerprint' => str_repeat('a', 64), 'status' => 'requested', 'evidence' => []]);

        $this->rejected(fn () => $this->confirmDynamic(), 'dynamic_compliance_hold_or_evidence_unavailable');

        $this->assertEmptyDynamicState();
    }

    public function test_canonical_cleared_rfi_with_an_authoritatively_resolved_case_allows_confirmation(): void
    {
        $this->account->update(['rfi_status' => 'cleared']);
        NiumRfiCase::query()->create(['provider_id' => $this->account->provider_id,
            'user_provider_account_id' => $this->account->id, 'scope' => 'customer',
            'provider_reference_fingerprint' => str_repeat('a', 64), 'status' => 'resolved_authoritative_clear',
            'reconciled_at' => now(), 'evidence' => []]);
        $intent = $this->confirmDynamic();
        $this->assertSame($this->owner->id, $intent->user_id);
        $this->assertSame(1, NiumDynamicReservation::query()->count());
        $this->assertSame(1, NiumDynamicPayoutOutbox::query()->count());
        $this->assertSame(0, Transfer::query()->count());
    }

    public static function unresolvedRfiCases(): array
    {
        return [
            ['requested', true, false], ['responded_under_review', true, false],
            ['historical_webhook_evidence', true, false], ['unknown', true, false],
            ['resolved_authoritative_clear', false, false], ['resolved_authoritative_clear', true, true],
        ];
    }

    #[DataProvider('unresolvedRfiCases')]
    public function test_cleared_account_does_not_hide_an_unverified_or_unresolved_rfi_case(string $status, bool $observed, bool $future): void
    {
        $this->account->update(['rfi_status' => 'cleared']);
        NiumRfiCase::query()->create(['provider_id' => $this->account->provider_id,
            'user_provider_account_id' => $this->account->id, 'scope' => 'customer',
            'provider_reference_fingerprint' => str_repeat('a', 64), 'status' => $status,
            'reconciled_at' => $observed ? ($future ? now()->addMinute() : now()) : null, 'evidence' => []]);
        $this->rejected(fn () => $this->confirmDynamic(), 'dynamic_compliance_hold_or_evidence_unavailable');
        $this->assertEmptyDynamicState();
    }

    public function test_open_aml_match_cannot_be_hidden_by_a_clear_screening(): void
    {
        AmlScreening::query()->sole()->matches()->create([
            'list_type' => 'sanctions', 'source' => 'fixture', 'matched_name' => 'Fixture Match', 'status' => 'open',
        ]);

        $this->rejected(fn () => $this->confirmDynamic(), 'dynamic_compliance_hold_or_evidence_unavailable');

        $this->assertEmptyDynamicState();
    }

    public function test_missing_quote_consumption_or_fee_evidence_fails_closed(): void
    {
        unset($this->source->versions['fees']);
        $this->rejected(fn () => $this->confirmDynamic(), 'dynamic_authoritative_admission_evidence_unavailable');
        $this->source->versions['fees'] = 'fixture-v1';
        $this->source->rule = 'UNKNOWN';
        $this->rejected(fn () => $this->confirmDynamic(), 'dynamic_authoritative_admission_evidence_unavailable');
        $this->assertEmptyDynamicState();
    }

    public function test_synthetic_review_is_not_durable_authority(): void
    {
        $this->source->review = $this->dynamicReview(synthetic: true);

        $this->rejected(fn () => $this->confirmDynamic(), 'dynamic_authoritative_admission_evidence_unavailable');

        $this->assertEmptyDynamicState();
    }

    public function test_enabled_flags_without_a_trusted_adapter_fail_closed(): void
    {
        $this->confirmation = new DynamicTransferConfirmationService(new DynamicReservationLedger);

        $this->rejected(fn () => $this->confirmDynamic(), 'dynamic_authoritative_evidence_source_unavailable');

        $this->assertEmptyDynamicState();
    }

    public function test_outbox_failure_rolls_back_intent_hold_wallet_and_reservation(): void
    {
        $event = 'eloquent.creating: '.NiumDynamicPayoutOutbox::class;
        Event::listen($event, static fn () => throw new RuntimeException('injected_outbox_failure'));
        try {
            $this->rejected(fn () => $this->confirmDynamic(), 'injected_outbox_failure');
        } finally {
            Event::forget($event);
        }

        $this->assertEmptyDynamicState();
    }

    public function test_outbox_save_veto_also_rolls_back(): void
    {
        $event = 'eloquent.creating: '.NiumDynamicPayoutOutbox::class;
        Event::listen($event, static fn () => false);
        try {
            $this->rejected(fn () => $this->confirmDynamic(), 'dynamic_outbox_or_admission_evidence_unavailable');
        } finally {
            Event::forget($event);
        }

        $this->assertEmptyDynamicState();
    }

    public function test_wallet_save_veto_cannot_create_an_unfunded_outbox(): void
    {
        $event = 'eloquent.updating: '.Balance::class;
        Event::listen($event, static fn () => false);
        try {
            $this->rejected(fn () => $this->confirmDynamic(), 'dynamic_wallet_reservation_not_persisted');
        } finally {
            Event::forget($event);
        }

        $this->assertEmptyDynamicState();
    }

    public function test_cancellation_failure_rolls_back_release_and_retains_held_funds(): void
    {
        $intent = $this->confirmDynamic();
        $event = 'eloquent.updating: '.NiumDynamicPayoutOutbox::class;
        Event::listen($event, static fn () => false);
        try {
            $this->rejected(fn () => $this->confirmation->cancel($this->owner, $intent->id), 'dynamic_cancellation_not_persisted');
        } finally {
            Event::forget($event);
        }

        $this->assertSame('148.00000000', $this->wallet->fresh()->available_balance);
        $this->assertSame('102.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame('held', NiumDynamicReservation::query()->sole()->state);
        $this->assertSame('held', NiumDynamicPayoutOutbox::query()->sole()->state);
        $this->assertSame(1, LedgerEntry::query()->count());
    }

    public function test_cancellation_releases_only_its_owned_reservation_and_is_idempotent(): void
    {
        $first = $this->confirmDynamic();
        $this->source->review = $this->dynamicReview(quoteId: 'fixture-quote-2');
        $second = $this->confirmDynamic('second-reference');
        config(['nium_dynamic_confirmation.enabled' => false]);

        $this->confirmation->cancel($this->owner, $first->id);
        $this->confirmation->cancel($this->owner, $first->id);

        $this->assertSame('148.00000000', $this->wallet->fresh()->available_balance);
        $this->assertSame('102.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'release')->count());
        $this->assertSame('held', NiumDynamicReservation::query()->where('intent_id', $second->id)->sole()->state);
        $this->assertSame('cancelled', NiumDynamicPayoutOutbox::query()->where('intent_id', $first->id)->sole()->state);
        $this->assertSame(2, NiumDynamicIntent::query()->count());
    }

    public function test_another_actor_cannot_cancel(): void
    {
        $intent = $this->confirmDynamic();
        $other = User::factory()->create();

        $this->rejected(fn () => $this->confirmation->cancel($other, $intent->id));

        $this->assertSame('102.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'release')->count());
    }

    public static function ambiguousStates(): array
    {
        return [[['state' => 'ambiguous']], [['attempts' => 1]],
            [['claimed_at' => '2030-01-01 00:10:00']], [['submission_started_at' => '2030-01-01 00:10:00']],
            [['provider_reference' => 'potentially-submitted']]];
    }

    #[DataProvider('ambiguousStates')]
    public function test_potentially_submitted_or_ambiguous_payout_never_releases(array $changes): void
    {
        $intent = $this->confirmDynamic();
        NiumDynamicPayoutOutbox::query()->where('intent_id', $intent->id)->update($changes);

        $this->rejected(fn () => $this->confirmation->cancel($this->owner, $intent->id),
            'dynamic_cancellation_requires_proof_of_no_submission');

        $this->assertSame('102.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame(1, LedgerEntry::query()->count());
    }

    public function test_corrupted_reservation_ownership_never_releases(): void
    {
        $intent = $this->confirmDynamic();
        NiumDynamicReservation::query()->where('intent_id', $intent->id)->update(['amount' => '101']);

        $this->rejected(fn () => $this->confirmation->cancel($this->owner, $intent->id),
            'dynamic_reservation_release_ownership_unverified');

        $this->assertSame('102.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame('held', NiumDynamicPayoutOutbox::query()->sole()->state);
    }

    public function test_historically_overwritten_wallet_requires_audited_repair_before_reserve_or_release(): void
    {
        $this->wallet->update(['available_balance' => '150', 'ledger_balance' => '150',
            'raw_data' => ['provider_available_balance' => '150']]);
        $intent = $this->confirmDynamic();
        $this->wallet->refresh()->update(['available_balance' => '150']);
        $this->source->review = $this->dynamicReview(quoteId: 'fixture-quote-2');

        $this->rejected(fn () => $this->confirmDynamic('second-reference'), 'dynamic_reservation_balance_inconsistent');

        $this->rejected(fn () => $this->confirmation->cancel($this->owner, $intent->id),
            'dynamic_reservation_balance_inconsistent');
        $this->assertSame('150.00000000', $this->wallet->fresh()->available_balance);
        $this->assertSame('102.00000000', $this->wallet->fresh()->reserved_balance);
    }

    public function test_high_precision_total_debit_never_rounds_through_float(): void
    {
        $this->wallet->update(['available_balance' => '999999999999.99999999',
            'ledger_balance' => '999999999999.99999999',
            'raw_data' => ['provider_available_balance' => '999999999999.99999999']]);
        $this->source->review = $this->dynamicReview(fee: '0.00000001', principal: '999999999997.99999998');

        $intent = $this->confirmDynamic();

        $this->assertSame('999999999997.99999999', $intent->total_debit);
        $this->assertSame('2.00000000', $this->wallet->fresh()->available_balance);
        $this->assertSame('999999999997.99999999', $this->wallet->fresh()->reserved_balance);
    }

    public function test_database_enforces_intent_immutability_and_non_dispatchable_outbox(): void
    {
        $intent = $this->confirmDynamic();
        $this->databaseRejects(fn () => DB::table('nium_dynamic_intents')->where('id', $intent->id)->update(['total_debit' => '1']));
        $this->databaseRejects(fn () => DB::table('nium_dynamic_intents')->where('id', $intent->id)->delete());
        $this->databaseRejects(fn () => DB::table('nium_dynamic_payout_outbox')->update(['dispatchable' => true]));
        $this->assertSame('102.00000000', $intent->fresh()->total_debit);
    }

    public function test_legacy_hk_services_cannot_accept_dynamic_intent_models(): void
    {
        $intent = $this->confirmDynamic();
        $provider = $this->account->provider;
        $calls = [
            fn () => app(ProviderTransferManager::class)->submitTransfer($provider, $intent),
            fn () => app(NiumTransferService::class)->submitTransfer($provider, $intent),
            fn () => app(NiumTransferService::class)->syncTransferStatus($provider, $intent),
            fn () => app(TransferApprovalService::class)->approve($intent, $this->owner),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('A legacy transfer entry point accepted a dynamic intent.');
            } catch (TypeError) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertFalse($intent instanceof Transfer);
        $this->assertSame(0, Transfer::query()->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_historical_transfer_five_hold_is_untouched(): void
    {
        $historical = Transfer::query()->forceCreate([
            'id' => 5, 'transfer_no' => 'FIXTURE-HISTORICAL-HOLD-5', 'user_id' => $this->owner->id,
            'provider_id' => $this->account->provider_id, 'beneficiary_id' => $this->beneficiary->id,
            'transfer_type' => 'payout', 'source_currency' => 'USD', 'target_currency' => 'USD',
            'source_amount' => '10', 'status' => 'draft',
            'raw_data' => ['hold' => 'HISTORICAL_TRANSFER_5_HOLD', 'dispatch' => 'DENIED'],
        ]);
        $before = $historical->fresh()->getAttributes();

        $intent = $this->confirmDynamic();
        $this->confirmation->cancel($this->owner, $intent->id);

        $this->assertSame($before, $historical->fresh()->getAttributes());
        $this->assertSame(1, Transfer::query()->count());
        $this->assertSame(1, NiumDynamicIntent::query()->count());
    }

    private function rejected(callable $operation, ?string $message = null): void
    {
        try {
            $operation();
            $this->fail('The invalid confirmation/cancellation was accepted.');
        } catch (RuntimeException|InvalidArgumentException $exception) {
            if ($exception instanceof QueryException) {
                throw $exception;
            }
            if ($message !== null) {
                $this->assertStringContainsString($message, $exception->getMessage());
            } else {
                $this->assertTrue($exception instanceof ModelNotFoundException
                    || str_starts_with($exception->getMessage(), 'dynamic_')
                    || str_starts_with($exception->getMessage(), 'payout_'));
            }
        }
    }

    private function assertEmptyDynamicState(string $available = '250.00000000'): void
    {
        $this->assertSame(0, NiumDynamicIntent::query()->count());
        $this->assertSame(0, NiumDynamicReservation::query()->count());
        $this->assertSame(0, NiumDynamicPayoutOutbox::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
        $this->assertSame($available, $this->wallet->fresh()->available_balance);
        $this->assertSame('0.00000000', $this->wallet->fresh()->reserved_balance);
    }

    private function databaseRejects(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('A database safety invariant was not enforced.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
