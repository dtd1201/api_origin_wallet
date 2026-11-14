<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Financial;

use App\Domain\Nium\DynamicMultiCurrency\CorridorEvidence;
use App\Domain\Nium\DynamicMultiCurrency\EligibilityResolver;
use App\Domain\Nium\DynamicMultiCurrency\Registry\EvidenceSource;
use App\Domain\Nium\DynamicMultiCurrency\EvidenceState;
use App\Domain\Nium\DynamicMultiCurrency\Registry\RevocationStatus;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Versioned envelope around the EXISTING FinancialTerms, never another price model.
 * Server-supplied evidence is not authenticated by these pure value objects.
 * A confirmation value is not a persisted transaction, reservation or authorization.
 */
final readonly class PayoutConfirmation
{
    public const VERSION = 'nium-dynamic-confirmation-v1';

    private function __construct(
        public PayoutQuoteContext $context,
        public PayoutRateQuote $quote,
        public FinancialTerms $terms,
        public PayoutContract $contract,
        public array $pricingEvidence,
        public array $eligibilityEvidence,
        public string $amountSemanticsReference,
        public bool $synthetic,
        public ?string $confirmedBy = null,
        public ?DateTimeImmutable $confirmedAt = null,
        public ?array $finalizedPreparationBinding = null,
        public ?PayoutInstruction $instruction = null,
        public ?string $executionOperationKey = null,
        public ?string $validatedRequestSha256 = null,
    ) {}

    /**
     * $amountModeReferences is server-owned applicability evidence for this exact
     * PayoutContract: mode => reference attesting the selected remittance amount.
     * Empty by default. A quote alone does not validate the actual instruction.
     * Optional finalized preparation binds business inputs to these independently
     * evidenced terms; it never guarantees provider settlement or fee/debit equality.
     *
     * @param list<PricingEvidence> $pricingEvidence No client consent stage accepted.
     * @param list<CorridorEvidence> $eligibilityEvidence Complete snapshot, including holds.
     * @param array<string, string> $amountModeReferences
     */
    public static function prepare(
        string $authenticatedUserId,
        PayoutQuoteContext $context,
        PayoutRateQuote $quote,
        FinancialTerms $terms,
        PayoutContract $contract,
        array $pricingEvidence,
        array $eligibilityEvidence,
        array $amountModeReferences,
        DateTimeImmutable $asOf,
        ?FinalizedUsdPayoutPreparation $finalizedPreparation = null,
        ?PayoutInstruction $instruction = null,
        ?string $executionOperationKey = null,
        ?string $validatedRequestSha256 = null,
    ): self {
        $reference = $amountModeReferences[$quote->request->mode->value] ?? null;
        if (! is_string($reference) || $reference === '' || trim($reference) !== $reference
            || strlen($reference) > 255 || preg_match('/[\x00-\x1F\x7F]/', $reference)) {
            throw new InvalidArgumentException('payout_amount_semantics_unverified');
        }
        $synthetic = $contract->source === EvidenceSource::Synthetic;
        foreach ($pricingEvidence as $record) {
            if (! $record instanceof PricingEvidence || $record->stage === PricingStage::CustomerConfirmed) {
                throw new InvalidArgumentException('payout_client_confirmation_evidence_rejected');
            }
            $synthetic = $synthetic || $record->source === EvidenceSource::Synthetic;
        }
        foreach ($eligibilityEvidence as $record) {
            if (! $record instanceof CorridorEvidence) {
                throw new InvalidArgumentException('payout_eligibility_evidence_invalid');
            }
            if ($record->synthetic !== ($contract->source === EvidenceSource::Synthetic)) {
                throw new InvalidArgumentException('payout_evidence_origin_mixed');
            }
            $synthetic = $synthetic || $record->synthetic;
        }
        $binding = $finalizedPreparation === null ? null : self::bindFinalizedPreparation(
            $finalizedPreparation, $context, $quote, $terms, $authenticatedUserId, $asOf);
        $review = new self($context, $quote, $terms, $contract, array_values($pricingEvidence),
            array_values($eligibilityEvidence), $reference, $synthetic, finalizedPreparationBinding: $binding,
            instruction: $instruction, executionOperationKey: $executionOperationKey, validatedRequestSha256: $validatedRequestSha256);
        $review->assertCurrent($authenticatedUserId, $asOf);

        return $review;
    }

    /** Business-rule consistency only; the existing authoritative evidence checks still apply. */
    private static function bindFinalizedPreparation(
        FinalizedUsdPayoutPreparation $preparation,
        PayoutQuoteContext $context,
        PayoutRateQuote $quote,
        FinancialTerms $terms,
        string $actor,
        DateTimeImmutable $asOf,
    ): array {
        if (! hash_equals($preparation->context->fingerprint(), $context->fingerprint())
            || ($preparation->quote !== null && ($preparation->quote->id !== $quote->id
                || ! hash_equals($preparation->quote->responseSha256, $quote->responseSha256)))) {
            throw new InvalidArgumentException('payout_finalized_preparation_quote_mismatch');
        }
        // Reuse the finalized arithmetic and quote binding; never reconstruct a fee/debit here.
        $prepared = $preparation->withQuote($quote, $actor, $asOf);
        $snapshot = $prepared->snapshot($actor, $asOf);
        if ($terms->principal->snapshot() !== $snapshot['proposed_conversion_principal']
            || $terms->totalWalletDebit?->snapshot() !== $snapshot['planned_customer_wallet_debit']
            || $terms->transactionFee?->snapshot() !== $snapshot['included_fee']
            || $terms->estimatedRecipient?->snapshot() !== $snapshot['quoted_receive_amount']
            || $terms->fxMarginPercent?->value !== $snapshot['expected_fx_margin_percent']
            || $terms->feePayer !== FeePayer::Sender || $terms->feeAllocation !== FeeAllocation::SenderAdditional) {
            throw new InvalidArgumentException('payout_finalized_preparation_terms_mismatch');
        }

        // Only immutable business inputs/quote identity enter consent. No planning
        // status is promoted to provider authority; contract/pricing validation below is mandatory.
        $binding = array_intersect_key($snapshot, array_flip(['version', 'basis', 'input_mode', 'entered_amount',
            'planned_customer_wallet_debit', 'proposed_conversion_principal', 'included_fee', 'fee_classification',
            'expected_fx_margin_percent', 'margin_basis', 'quoted_receive_amount', 'quoted_rate',
            'quote_id', 'quote_response_sha256', 'quote_expires_at']));
        $binding['quote_request_sha256'] = hash('sha256', $prepared->quoteRequest->json());

        return $binding;
    }

    private function assertCurrent(string $actor, DateTimeImmutable $asOf): void
    {
        $this->context->assertUsable($actor, $asOf);
        $q = $this->quote;
        $r = $q->request;
        $t = $this->terms;
        $c = $this->context->corridor;
        if ($q->expiresAt <= $asOf) {
            throw new InvalidArgumentException('payout_quote_expired');
        }
        if (! hash_equals($q->contextFingerprint, $this->context->fingerprint())
            || ! $c->equals($t->corridor) || $r->customer !== $c->customer
            || $r->sourceCurrency !== $c->sourceCurrency || $r->destinationCurrency !== $c->destinationCurrency
            || $t->quoteId !== $q->id || ! $t->fxRate?->equals($q->rate)
            || ! $t->principal->amount->equals($q->sourceAmount)
            || ! $t->conversionAmount?->amount->equals($q->destinationAmount)) {
            throw new InvalidArgumentException('payout_quote_terms_binding_mismatch');
        }
        // DESTINATION_AMOUNT means the recipient amount, not an unapproved gross FX
        // output. Recipient-deducted fees cannot silently reduce the entered amount.
        $selected = $r->mode === PayoutAmountMode::Source ? $t->principal : $t->estimatedRecipient;
        if ($selected === null || ! $selected->amount->equals($r->amount)) {
            throw new InvalidArgumentException('payout_selected_amount_mismatch');
        }
        $scale = $this->contract->currencyScales[$r->mode === PayoutAmountMode::Source
            ? $r->sourceCurrency : $r->destinationCurrency] ?? null;
        if ($scale === null || ! $r->amount->fitsScale($scale)) {
            throw new InvalidArgumentException('payout_input_precision_invalid');
        }
        $eligibility = (new EligibilityResolver)->resolve($c, $asOf, ...$this->eligibilityEvidence);
        if ($eligibility->preparation !== 'ALLOWED') {
            throw new InvalidArgumentException('payout_corridor_unavailable');
        }
        $quoteEvidence = array_values(array_filter($this->pricingEvidence,
            static fn (PricingEvidence $e): bool => $e->stage === PricingStage::ProviderQuote));
        if (count($quoteEvidence) !== 1
            || (! $this->synthetic && $quoteEvidence[0]->source !== EvidenceSource::ProviderResponse)) {
            throw new InvalidArgumentException('payout_provider_evidence_missing');
        }
        $evidence = $this->pricingEvidence;
        if ($this->confirmedAt !== null) {
            $evidence[] = new PricingEvidence('customer-confirmation:'.$this->fingerprint(), PricingStage::CustomerConfirmed,
                EvidenceState::Verified, $this->synthetic ? EvidenceSource::Synthetic : EvidenceSource::LocalAttestation,
                self::VERSION, $t, $this->confirmedAt, $this->confirmedAt, $q->expiresAt, RevocationStatus::NotRevoked);
        }
        $decision = (new FinancialTermsValidator)->evaluate($t, $asOf, $this->contract, ...$evidence);
        $expected = $this->confirmedAt === null ? [FinancialReason::ConfirmationMissing->value] : [];
        if ($decision->reasons !== $expected) {
            throw new InvalidArgumentException('payout_financial_terms_unverified');
        }
    }

    /** Fresh server review required; the client supplies only actor-bound fingerprint acceptance. */
    public function confirm(string $authenticatedUserId, string $acceptedFingerprint, DateTimeImmutable $asOf): self
    {
        if ($this->confirmedAt !== null || ! hash_equals($this->fingerprint(), $acceptedFingerprint)) {
            throw new InvalidArgumentException('payout_confirmation_binding_mismatch');
        }
        $this->assertCurrent($authenticatedUserId, $asOf);
        $confirmed = new self($this->context, $this->quote, $this->terms, $this->contract,
            $this->pricingEvidence, $this->eligibilityEvidence, $this->amountSemanticsReference,
            $this->synthetic, $authenticatedUserId, $asOf, $this->finalizedPreparationBinding, $this->instruction, $this->executionOperationKey,
            $this->validatedRequestSha256);
        $confirmed->assertCurrent($authenticatedUserId, $asOf);

        return $confirmed;
    }

    /** Snapshot is server-side data, NOT a public response resource. No account numbers. */
    public function snapshot(): array
    {
        $pricing = array_map(static fn (PricingEvidence $e): array => [
            'id' => $e->id, 'stage' => $e->stage->value, 'state' => $e->state->value,
            'source' => $e->source->value, 'provenance' => $e->provenance, 'revocation' => $e->revocation->value,
            'issued_at' => $e->issuedAt->format('Y-m-d\TH:i:s.uP'),
            'valid_from' => $e->validFrom->format('Y-m-d\TH:i:s.uP'), 'expires_at' => $e->expiresAt->format('Y-m-d\TH:i:s.uP'),
        ], $this->pricingEvidence);
        $eligibility = array_map(static fn (CorridorEvidence $e): array => [
            'kind' => $e->kind->value, 'state' => $e->state->value, 'reference' => $e->reference,
            'observed_at' => $e->observedAt->format('Y-m-d\TH:i:s.uP'),
            'expires_at' => $e->expiresAt->format('Y-m-d\TH:i:s.uP'), 'synthetic' => $e->synthetic,
        ], $this->eligibilityEvidence);
        usort($pricing, static fn (array $a, array $b): int => strcmp($a['stage'], $b['stage']));
        usort($eligibility, static fn (array $a, array $b): int => strcmp($a['kind'], $b['kind']));
        $contract = get_object_vars($this->contract);
        $contract['corridor'] = get_object_vars($this->contract->corridor);
        $contract['validFrom'] = $this->contract->validFrom->format('Y-m-d\TH:i:s.uP');
        $contract['expiresAt'] = $this->contract->expiresAt->format('Y-m-d\TH:i:s.uP');
        ksort($contract['currencyScales'], SORT_STRING);
        sort($contract['supportedMethods'], SORT_STRING);

        $snapshot = [
            'version' => self::VERSION, 'context' => $this->context->snapshot(),
            'input_mode' => $this->quote->request->mode->value, 'input_amount' => $this->quote->request->amount->value,
            'quote_id' => $this->quote->id, 'quote_request_sha256' => hash('sha256', $this->quote->request->json()),
            'quote_response_sha256' => $this->quote->responseSha256,
            'quote_expires_at' => $this->quote->expiresAt->format('Y-m-d\TH:i:s.uP'),
            'financial_terms' => $this->terms->snapshot(), 'payout_contract' => $contract,
            'amount_semantics_reference' => $this->amountSemanticsReference,
            'pricing_evidence' => $pricing, 'eligibility_evidence' => $eligibility,
            'synthetic' => $this->synthetic, 'dispatch' => 'DENIED',
        ];
        // Preserve existing snapshots/fingerprints for reviews without this binding.
        if ($this->finalizedPreparationBinding !== null) {
            $snapshot['finalized_usd_preparation'] = $this->finalizedPreparationBinding;
        }
        if ($this->instruction !== null) {
            $snapshot['remittance_instruction'] = $this->instruction->snapshot();
        }
        if ($this->executionOperationKey !== null) {
            if (preg_match('/^[a-f0-9-]{36}$/D', $this->executionOperationKey) !== 1) {
                throw new InvalidArgumentException('dynamic_execution_operation_key_invalid');
            }
            $snapshot['execution_operation_key'] = $this->executionOperationKey;
        }
        if ($this->validatedRequestSha256 !== null) {
            if (preg_match('/^[a-f0-9]{64}$/D', $this->validatedRequestSha256) !== 1) {
                throw new InvalidArgumentException('dynamic_validated_request_hash_invalid');
            }
            $snapshot['validated_remittance_sha256'] = $this->validatedRequestSha256;
        }

        return $snapshot;
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->snapshot(), JSON_THROW_ON_ERROR));
    }

    public function confirmationBinding(): ?array
    {
        return $this->confirmedAt === null ? null : [
            'version' => self::VERSION, 'financial_terms_fingerprint' => $this->fingerprint(),
            'actor' => $this->confirmedBy, 'confirmed_at' => $this->confirmedAt->format('Y-m-d\TH:i:s.uP'),
            'dispatch' => 'DENIED',
        ];
    }
}
