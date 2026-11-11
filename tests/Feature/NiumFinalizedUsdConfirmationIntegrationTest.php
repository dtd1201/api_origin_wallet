<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Nium\DynamicMultiCurrency\CorridorEvidence;
use App\Domain\Nium\DynamicMultiCurrency\CorridorIdentity;
use App\Domain\Nium\DynamicMultiCurrency\EvidenceKind;
use App\Domain\Nium\DynamicMultiCurrency\EvidenceState;
use App\Domain\Nium\DynamicMultiCurrency\Financial\Decimal;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FeeAllocation;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FeePayer;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FinalizedUsdPayoutPreparation;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FinancialTerms;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FxRequirement;
use App\Domain\Nium\DynamicMultiCurrency\Financial\Money;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutAmountMode;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutConfirmation;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutContract;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutQuoteContext;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutRateQuote;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PricingEvidence;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PricingStage;
use App\Domain\Nium\DynamicMultiCurrency\Options\BeneficiarySnapshot;
use App\Domain\Nium\DynamicMultiCurrency\Options\CustomerAccountScope;
use App\Domain\Nium\DynamicMultiCurrency\Registry\EvidenceSource;
use App\Domain\Nium\DynamicMultiCurrency\Registry\RevocationStatus;
use App\Models\LedgerEntry;
use App\Models\NiumDynamicIntent;
use App\Models\NiumDynamicPayoutOutbox;
use App\Models\NiumDynamicReservation;
use App\Models\Transfer;
use App\Services\Nium\DynamicConfirmation\DynamicReceivingAccountBinding;
use App\Services\Nium\DynamicConfirmation\DynamicReservationLedger;
use App\Services\Nium\DynamicConfirmation\DynamicTransferConfirmationService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesDynamicConfirmationFixtures;
use Tests\Support\DynamicConfirmationDatabaseTestCase;

/**
 * Offline PostgreSQL fixture assertions, NOT verified Nium financial behavior.
 * The existing test-only evidence stub simulates an authenticated persisted mapping.
 * No production evidence source is installed; HTTP/jobs are forbidden by the base class.
 */
final class NiumFinalizedUsdConfirmationIntegrationTest extends DynamicConfirmationDatabaseTestCase
{
    use CreatesDynamicConfirmationFixtures;
    use \Tests\Concerns\CreatesFinalizedUsdConfirmationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDynamicFixtures('2000.00');
        $this->wallet->update(['currency' => 'USD']);
    }

    public static function modes(): array
    {
        return [[PayoutAmountMode::Source], [PayoutAmountMode::Destination]];
    }

    #[DataProvider('modes')]
    public function test_bound_preparation_reserves_entered_spend_with_one_included_fee(PayoutAmountMode $mode): void
    {
        $this->source->review = $this->usdReview($mode);
        $fingerprint = $this->source->review->fingerprint();
        $intent = $this->confirmDynamic();
        $snapshot = $intent->fresh()->confirmed_terms;
        $binding = $snapshot['finalized_usd_preparation'];
        $this->assertSame($fingerprint, hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)));
        $this->assertSame('980', $snapshot['financial_terms']['principal']['amount']);
        $this->assertSame('20', $snapshot['financial_terms']['transaction_fee']['amount']);
        $this->assertSame('1000', $binding['planned_customer_wallet_debit']['amount']);
        $this->assertSame($mode === PayoutAmountMode::Source ? '1000' : '784', $binding['entered_amount']['amount']);
        $this->assertSame($mode === PayoutAmountMode::Source ? '980' : '784', $snapshot['input_amount']);
        $this->assertSame('0.13', $binding['expected_fx_margin_percent']);
        $this->assertSame('784', $binding['quoted_receive_amount']['amount']);
        $this->assertSame($snapshot['quote_id'], $binding['quote_id']);
        $this->assertSame($snapshot['quote_request_sha256'], $binding['quote_request_sha256']);
        $this->assertSame($snapshot['quote_response_sha256'], $binding['quote_response_sha256']);
        $this->assertSame('1000.00000000', $intent->total_debit);
        $this->assertSame('1000.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame('1000.00000000', $this->wallet->fresh()->available_balance);
        $this->assertSame('-1000.00000000', LedgerEntry::query()->sole()->amount);
        $this->assertSame('1000.00000000', NiumDynamicReservation::query()->sole()->amount);
        $outbox = NiumDynamicPayoutOutbox::query()->sole();
        $this->assertSame('held', $outbox->state);
        $this->assertSame(0, $outbox->attempts);
        $this->assertFalse($outbox->dispatchable);
        $this->assertFalse($outbox->isDispatchable());
        $this->assertSame(0, Transfer::query()->count());
    }

    public function test_duplicate_confirmation_after_timeout_or_expiry_returns_original_without_another_hold(): void
    {
        $this->source->review = $this->usdReview();
        $original = $this->confirmDynamic();
        $this->travelTo(new DateTimeImmutable('2030-01-02T00:00:00Z'));
        $retry = $this->confirmDynamic();
        $this->assertSame($original->id, $retry->id);
        $this->assertSame(1, $this->source->calls);
        $this->assertSame(1, LedgerEntry::query()->count());
        $this->assertSame(1, NiumDynamicIntent::query()->count());
        $this->assertSame(1, NiumDynamicPayoutOutbox::query()->count());
        $this->assertSame('1000.00000000', $this->wallet->fresh()->reserved_balance);
    }

    public function test_changed_preparation_fingerprint_cannot_replace_a_confirmed_reference(): void
    {
        $this->source->review = $this->usdReview();
        $original = $this->confirmDynamic();
        $this->source->review = $this->usdReview(PayoutAmountMode::Destination);
        $this->assertNotSame($original->terms_fingerprint, $this->source->review->fingerprint());
        $this->assertRejected(fn () => $this->confirmDynamic(), 'dynamic_confirmation_idempotency_conflict');
        $this->assertSame($original->terms_fingerprint, $original->fresh()->terms_fingerprint);
        $this->assertSame(1, LedgerEntry::query()->count());
    }

    public function test_same_quote_cannot_reserve_again_under_a_new_reference(): void
    {
        $this->source->review = $this->usdReview();
        $this->confirmDynamic();
        $this->assertRejected(fn () => $this->confirmDynamic('fixture-another-reference'), 'dynamic_quote_already_consumed');
        $this->assertSame(1, NiumDynamicIntent::query()->count());
        $this->assertSame(1, LedgerEntry::query()->count());
        $this->assertSame('1000.00000000', $this->wallet->fresh()->reserved_balance);
    }

    public static function invalidMappings(): array
    {
        return [
            'expired quote' => [['response' => ['expiryTime' => '2030-01-01T00:05:00Z']]],
            'wrong customer echo' => [['response' => ['customerHashId' => 'fixture-other']]],
            'wrong source amount' => [['response' => ['sourceAmount' => '1000']]],
            'wrong currency echo' => [['response' => ['destinationCurrencyCode' => 'EUR']]],
            'wrong corridor' => [['binding_corridor' => ['destinationCountry' => 'DE']]],
            'replaced quote identity' => [['confirmation_quote_id' => 'fixture-other-quote']],
            'fee charged again' => [['terms' => ['totalWalletDebit' => new Money('USD', new Decimal('1020'))]]],
            'wrong fee' => [['terms' => ['transactionFee' => new Money('USD', new Decimal('21'))]]],
            'recipient reduced' => [['terms' => ['estimatedRecipient' => new Money('GBP', new Decimal('764'))]]],
            'wrong margin' => [['terms' => ['fxMarginPercent' => new Decimal('1.5')]]],
            'missing provider debit calculation' => [['contract' => ['calculationReference' => null]]],
            'unknown provider mapping' => [['contract' => ['state' => EvidenceState::Unknown]]],
            'unverified pricing' => [['pricing_state' => EvidenceState::Unknown]],
            'missing mode semantics' => [['mode_references' => []]],
        ];
    }

    #[DataProvider('invalidMappings')]
    public function test_invalid_quote_or_mapping_never_creates_reservation_or_outbox(array $changes): void
    {
        $this->assertRejected(function () use ($changes): void {
            $this->source->review = $this->usdReview(changes: $changes);
            $this->confirmDynamic();
        });
        $this->assertNoFinancialWrites();
    }

    public function test_usd_review_without_preparation_binding_is_denied_before_reservation(): void
    {
        $this->source->review = $this->usdReview(bindPreparation: false);
        $this->assertRejected(fn () => $this->confirmDynamic(), 'dynamic_finalized_usd_preparation_unavailable');
        $this->assertNoFinancialWrites();
    }

    public function test_existing_non_usd_review_keeps_its_snapshot_shape_and_confirmation_fingerprint(): void
    {
        $review = $this->dynamicReview();
        $this->assertArrayNotHasKey('finalized_usd_preparation', $review->snapshot());
        $confirmed = $review->confirm((string) $this->owner->id, $review->fingerprint(), $this->at());
        $this->assertSame($review->fingerprint(), $confirmed->fingerprint());
        $this->assertArrayNotHasKey('finalized_usd_preparation', $confirmed->snapshot());
        $this->assertNoFinancialWrites();
    }

    public function test_business_preparation_does_not_install_an_authoritative_evidence_source(): void
    {
        $this->confirmation = new DynamicTransferConfirmationService(new DynamicReservationLedger);
        $this->assertRejected(fn () => $this->confirmDynamic(fingerprint: str_repeat('a', 64)),
            'dynamic_authoritative_evidence_source_unavailable');
        $this->assertNoFinancialWrites();
    }

    public function test_quote_expiring_during_revalidation_cannot_commit_a_reservation(): void
    {
        $this->source->review = $this->usdReview();
        $this->source->duringValidation = function (): void {
            $this->travelTo($this->at('01:00:00'));
        };
        $this->assertRejected(fn () => $this->confirmDynamic(), 'dynamic_confirmation_evidence_expired');
        $this->assertNoFinancialWrites();
    }

    private function assertRejected(callable $operation, ?string $message = null): void
    {
        try {
            $operation();
        } catch (InvalidArgumentException|RuntimeException $e) {
            if ($message !== null) {
                $this->assertSame($message, $e->getMessage());
            } else {
                $this->assertNotSame('', $e->getMessage());
            }

            return;
        }
        $this->fail('Invalid finalized confirmation was accepted.');
    }

    private function assertNoFinancialWrites(): void
    {
        $this->assertSame(0, NiumDynamicIntent::query()->count());
        $this->assertSame(0, NiumDynamicReservation::query()->count());
        $this->assertSame(0, NiumDynamicPayoutOutbox::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
        $this->assertSame('0.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame('2000.00000000', $this->wallet->fresh()->available_balance);
    }
}
