<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Financial;

use App\Domain\Nium\DynamicMultiCurrency\EvidenceState;
use App\Domain\Nium\DynamicMultiCurrency\Registry\EvidenceSource;
use App\Domain\Nium\DynamicMultiCurrency\Registry\RevocationStatus;
use DateTimeImmutable;

/**
 * Pure comparison of supplied candidate, calculation contract and frozen evidence.
 * Does not price a corridor, calculate FX/margins, round amounts, authenticate
 * assertions, emit financial-readiness evidence or grant dispatch authorization.
 */
final class FinancialTermsValidator
{
    public function evaluate(FinancialTerms $candidate, DateTimeImmutable $asOf, ?PayoutContract $contract, PricingEvidence ...$evidence): FinancialDecision
    {
        $crossCurrency = $candidate->corridor->sourceCurrency !== $candidate->corridor->destinationCurrency;
        $reasons = [];
        $origins = [];
        $byStage = [];
        $ids = [];

        if ($candidate->corridor->provider !== 'nium') {
            $reasons[] = FinancialReason::UnsupportedProvider;
        }
        if ($candidate->corridor->provider === 'nium' && $candidate->corridor->destinationCurrency === 'SGD'
            && $candidate->corridor->payoutMethod === 'LOCAL') {
            $reasons[] = FinancialReason::SgdHold;
        }

        $contractReasons = $contract === null ? [FinancialReason::ContractMissing] : $this->contractReasons($candidate, $contract, $asOf, $crossCurrency);
        $reasons = [...$reasons, ...$contractReasons];
        if ($contract !== null) {
            $origins[$contract->source === EvidenceSource::Synthetic ? 'synthetic' : 'asserted'] = true;
        }

        foreach ($evidence as $record) {
            $origins[$record->source === EvidenceSource::Synthetic ? 'synthetic' : 'asserted'] = true;
            $byStage[$record->stage->value][] = $record;
            $key = 'id:'.$record->id;
            if (isset($ids[$key])) {
                $reasons[] = FinancialReason::DuplicateId;
            }
            $ids[$key] = true;
            $reasons = [...$reasons, ...$this->stateReasons($record->state),
                ...$this->timeReasons($record->validFrom, $record->expiresAt, $record->issuedAt, $asOf),
                ...$this->revocationReasons($record->revocation)];

            if (! $candidate->corridor->equals($record->terms->corridor)) {
                $reasons[] = FinancialReason::ScopeMismatch;
            }
            if ($candidate->pricingVersion !== $record->terms->pricingVersion || $candidate->feePlanId !== $record->terms->feePlanId
                || $candidate->corridor->contractVersion !== $record->terms->corridor->contractVersion
                || $candidate->corridor->profileVersion !== $record->terms->corridor->profileVersion) {
                $reasons[] = FinancialReason::VersionMismatch;
            }
            if ($candidate->snapshot() !== $record->terms->snapshot()) {
                $reasons[] = FinancialReason::TermsChanged;
            }
            if ($record->stage === PricingStage::CustomerConfirmed && $record->source === EvidenceSource::ProviderResponse) {
                $reasons[] = FinancialReason::ConfirmationSourceInvalid;
            }
            if ($crossCurrency && $record->stage === PricingStage::ProviderQuote
                && $this->hasText($candidate->quoteId) && $this->hasText($candidate->quoteProvenance)
                && ($candidate->quoteId !== $record->id || $candidate->quoteProvenance !== $record->provenance)) {
                $reasons[] = FinancialReason::QuoteBindingMismatch;
            }
        }
        foreach ($byStage as $records) {
            if (count($records) > 1) {
                $reasons[] = FinancialReason::Conflicting;
            }
        }
        if (! isset($byStage[PricingStage::Effective->value])) {
            $reasons[] = FinancialReason::EffectiveMissing;
        }
        if (! isset($byStage[PricingStage::CustomerConfirmed->value])) {
            $reasons[] = FinancialReason::ConfirmationMissing;
        }
        if ($crossCurrency && ! isset($byStage[PricingStage::ProviderQuote->value])) {
            $reasons[] = FinancialReason::QuoteMissing;
        }
        if (count($origins) > 1) {
            $reasons[] = FinancialReason::MixedOrigin;
        }

        $amountReasons = $this->amountReasons($candidate, $contract, $crossCurrency);
        $reasons = [...$reasons, ...$amountReasons];
        // Only a verified, applicable calculation contract permits even these checks.
        if ($contractReasons === [] && $amountReasons === []) {
            $reasons = [...$reasons, ...$this->equationReasons($candidate, $crossCurrency)];
        }

        return new FinancialDecision(isset($origins['synthetic']), ...$reasons);
    }

    private function contractReasons(FinancialTerms $terms, PayoutContract $contract, DateTimeImmutable $asOf, bool $cross): array
    {
        $reasons = $contract->state === EvidenceState::Unknown ? [FinancialReason::ContractUnknown] : $this->stateReasons($contract->state);
        $reasons = [...$reasons, ...$this->timeReasons($contract->validFrom, $contract->expiresAt, $contract->validFrom, $asOf),
            ...$this->revocationReasons($contract->revocation)];
        if (! $contract->corridor->equals($terms->corridor)) {
            $reasons[] = FinancialReason::ScopeMismatch;
        }
        if ($contract->pricingVersion !== $terms->pricingVersion || $contract->feePlanId !== $terms->feePlanId
            || $contract->corridor->contractVersion !== $terms->corridor->contractVersion
            || $contract->corridor->profileVersion !== $terms->corridor->profileVersion) {
            $reasons[] = FinancialReason::VersionMismatch;
        }
        if (! in_array($terms->corridor->payoutMethod, $contract->supportedMethods, true)) {
            $reasons[] = FinancialReason::UnsupportedMethod;
        }
        if ($contract->roundingRule !== 'EXACT') {
            $reasons[] = FinancialReason::RoundingUnknown;
        }
        if (! $this->hasText($contract->calculationReference)) {
            $reasons[] = FinancialReason::CalculationUnknown;
        }
        if ($cross && ! $this->hasText($contract->fxCalculationReference)) {
            $reasons[] = FinancialReason::FxCalculationUnknown;
        }
        if (! in_array($contract->feeAllocation, [FeeAllocation::SenderAdditional, FeeAllocation::RecipientDeducted], true)
            || ! in_array($contract->feePayer, [FeePayer::Sender, FeePayer::Recipient], true)) {
            $reasons[] = FinancialReason::AllocationUnknown;
        } elseif ($contract->feeAllocation !== $terms->feeAllocation || $contract->feePayer !== $terms->feePayer) {
            // Unknown candidate values are reported as unknown, not invented mismatches.
            if (in_array($terms->feeAllocation, [FeeAllocation::SenderAdditional, FeeAllocation::RecipientDeducted], true)
                && in_array($terms->feePayer, [FeePayer::Sender, FeePayer::Recipient], true)) {
                $reasons[] = FinancialReason::AllocationMismatch;
            }
        }

        return $reasons;
    }

    private function amountReasons(FinancialTerms $terms, ?PayoutContract $contract, bool $cross): array
    {
        $reasons = [];
        $source = $terms->corridor->sourceCurrency;
        $destination = $terms->corridor->destinationCurrency;
        if ($terms->principal->currency !== $source
            || ($terms->totalWalletDebit !== null && $terms->totalWalletDebit->currency !== $source)
            || ($terms->estimatedRecipient !== null && $terms->estimatedRecipient->currency !== $destination)
            || ($terms->conversionAmount !== null && $terms->conversionAmount->currency !== $destination)) {
            $reasons[] = FinancialReason::CurrencyMismatch;
        }
        if ($terms->principal->amount->isZero()
            || $terms->totalWalletDebit?->amount->isZero() || $terms->estimatedRecipient?->amount->isZero()
            || $terms->conversionAmount?->amount->isZero()) {
            $reasons[] = FinancialReason::AmountInvalid;
        }
        if ($terms->transactionFee === null) {
            $reasons[] = FinancialReason::FeeUnknown;
        }
        if ($terms->totalWalletDebit === null) {
            $reasons[] = FinancialReason::DebitUnknown;
        }
        if ($terms->estimatedRecipient === null) {
            $reasons[] = FinancialReason::RecipientUnknown;
        }
        if ($terms->fxRequirement === FxRequirement::Unknown) {
            $reasons[] = FinancialReason::FxRequirementUnknown;
        } elseif (($cross && $terms->fxRequirement !== FxRequirement::Required) || (! $cross && $terms->fxRequirement !== FxRequirement::None)) {
            $reasons[] = FinancialReason::FxRequirementMismatch;
        }
        if ($cross) {
            if ($terms->fxRate === null || ! $this->hasText($terms->quoteId) || ! $this->hasText($terms->quoteProvenance)
                || $terms->fxMarginPercent === null || $terms->conversionAmount === null) {
                $reasons[] = FinancialReason::FxTermsUnknown;
            } elseif ($terms->fxRate->isZero()) {
                $reasons[] = FinancialReason::AmountInvalid;
            }
        } elseif ($terms->fxRate !== null || $terms->quoteId !== null || $terms->quoteProvenance !== null
            || $terms->fxMarginPercent !== null || $terms->conversionAmount !== null) {
            $reasons[] = FinancialReason::FxRequirementMismatch;
        }

        if (! in_array($terms->feeAllocation, [FeeAllocation::SenderAdditional, FeeAllocation::RecipientDeducted], true)
            || ! in_array($terms->feePayer, [FeePayer::Sender, FeePayer::Recipient], true)) {
            $reasons[] = FinancialReason::AllocationUnknown;
        } else {
            $sender = $terms->feeAllocation === FeeAllocation::SenderAdditional;
            if ($terms->feePayer !== ($sender ? FeePayer::Sender : FeePayer::Recipient)) {
                $reasons[] = FinancialReason::AllocationMismatch;
            }
            if ($terms->transactionFee !== null && $terms->transactionFee->currency !== ($sender ? $source : $destination)) {
                $reasons[] = FinancialReason::FeeCurrencyMismatch;
            }
        }
        if ($contract !== null) {
            if ($terms->transactionFee !== null && $terms->transactionFee->currency !== $contract->feeCurrency) {
                $reasons[] = FinancialReason::FeeCurrencyMismatch;
            }
            foreach ([$terms->principal, $terms->transactionFee, $terms->totalWalletDebit, $terms->estimatedRecipient, $terms->conversionAmount] as $money) {
                if ($money === null) {
                    continue;
                }
                $scale = $contract->currencyScales[$money->currency] ?? null;
                if ($scale === null) {
                    $reasons[] = FinancialReason::PrecisionUnknown;
                } elseif (! $money->amount->fitsScale($scale)) {
                    $reasons[] = FinancialReason::PrecisionInvalid;
                }
            }
            if ($cross && $terms->fxRate !== null) {
                if ($contract->rateScale === null) {
                    $reasons[] = FinancialReason::PrecisionUnknown;
                } elseif (! $terms->fxRate->fitsScale($contract->rateScale)) {
                    $reasons[] = FinancialReason::PrecisionInvalid;
                }
            }
        }

        return $reasons;
    }

    private function equationReasons(FinancialTerms $terms, bool $cross): array
    {
        $reasons = [];
        $fee = $terms->transactionFee->amount;
        $principal = $terms->principal->amount;
        $recipient = $terms->estimatedRecipient->amount;
        $grossRecipient = $cross ? $terms->conversionAmount->amount : $principal;
        if ($terms->feeAllocation === FeeAllocation::SenderAdditional) {
            if (! $terms->totalWalletDebit->amount->equals($principal->plus($fee))) {
                $reasons[] = FinancialReason::DebitInconsistent;
            }
            if (! $recipient->equals($grossRecipient)) {
                $reasons[] = FinancialReason::RecipientInconsistent;
            }
        } else {
            if (! $terms->totalWalletDebit->amount->equals($principal)) {
                $reasons[] = FinancialReason::DebitInconsistent;
            }
            if (! $recipient->plus($fee)->equals($grossRecipient)) {
                $reasons[] = FinancialReason::RecipientInconsistent;
            }
        }

        return $reasons;
    }

    private function stateReasons(EvidenceState $state): array
    {
        return match ($state) {
            EvidenceState::Verified => [], EvidenceState::Unknown => [FinancialReason::EvidenceUnknown],
            EvidenceState::Denied => [FinancialReason::EvidenceDenied], EvidenceState::Hold => [FinancialReason::EvidenceHold],
        };
    }

    private function revocationReasons(RevocationStatus $status): array
    {
        return match ($status) {
            RevocationStatus::NotRevoked => [], RevocationStatus::Revoked => [FinancialReason::EvidenceRevoked],
            RevocationStatus::Unknown => [FinancialReason::RevocationUnknown],
        };
    }

    private function timeReasons(DateTimeImmutable $from, DateTimeImmutable $expires, DateTimeImmutable $issued, DateTimeImmutable $asOf): array
    {
        $reasons = [];
        if ($expires <= $from || $expires <= $issued) {
            $reasons[] = FinancialReason::EvidenceWindowInvalid;
        }
        if ($from > $asOf || $issued > $asOf) {
            $reasons[] = FinancialReason::EvidenceFuture;
        }
        if ($expires <= $asOf) {
            $reasons[] = FinancialReason::EvidenceExpired;
        }

        return $reasons;
    }

    private function hasText(?string $value): bool
    {
        return $value !== null && trim($value) !== '' && trim($value) === $value && preg_match('/[\x00-\x1F\x7F]/', $value) === 0;
    }
}
