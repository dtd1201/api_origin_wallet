<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Options;

use App\Domain\Nium\DynamicMultiCurrency\EvidenceKind;
use App\Domain\Nium\DynamicMultiCurrency\EvidenceState;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FinancialReason;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FinancialTermsValidator;
use App\Domain\Nium\DynamicMultiCurrency\ReasonCode;
use App\Domain\Nium\DynamicMultiCurrency\Registry\EvidenceSource;
use App\Domain\Nium\DynamicMultiCurrency\Registry\RegistryEligibilityAdapter;
use App\Domain\Nium\DynamicMultiCurrency\Registry\RegistryEvaluation;
use App\Domain\Nium\DynamicMultiCurrency\Registry\RevocationStatus;
use DateTimeImmutable;

/**
 * Pure, read-only projection of server-owned snapshots. No ORM, container, clock,
 * provider discovery, configuration, pricing acquisition or runtime policy calls.
 * Does not authenticate a request; a future approved adapter must establish actor,
 * route user and ownership. No Phase 1/3 ALLOWED result activates an option here.
 */
final class TransferOptionsService
{
    public function options(string $authenticatedUserId, string $routeUserId, CustomerAccountScope $scope, DateTimeImmutable $asOf, TransferOptionCandidate ...$candidates): TransferOptionsResponse
    {
        if (! $scope->authorizes($authenticatedUserId, $routeUserId, $asOf)) {
            return new TransferOptionsResponse([], OptionsReason::ScopeUnauthorized);
        }

        $groups = [];
        foreach ($candidates as $candidate) {
            // Filter before examining evidence: foreign data cannot affect output.
            if (! $scope->equals($candidate->scope) || ! $scope->matches($candidate->corridor)) {
                continue;
            }
            $corridor = $candidate->corridor;
            $key = implode('|', [$corridor->sourceCurrency, $corridor->destinationCountry, $corridor->destinationCurrency, $corridor->payoutMethod]);
            $groups[$key][] = $candidate;
        }
        ksort($groups, SORT_STRING);
        $options = [];
        foreach ($groups as $group) {
            if (count($group) > 1) {
                // Distinct versions/types must not collapse into a positive winner.
                $options[] = new TransferOption($group[0]->corridor, 'UNKNOWN', 'UNKNOWN', OptionsReason::ConflictingOptions->value);

                continue;
            }
            $candidate = $group[0];
            $registry = (new RegistryEligibilityAdapter)->evaluate($candidate->evidence, $candidate->corridor, $asOf);
            $reasons = [...$registry->lookup->reasons, ...$registry->decision->preparationReasons];
            if (in_array('synthetic_evidence', $registry->decision->dispatchReasons, true)) {
                $reasons[] = ReasonCode::SyntheticEvidence->value;
            }
            [$compatibility, $beneficiaryReasons] = $this->beneficiary($candidate, $asOf);
            [$financial, $financialReasons] = $this->financial($candidate, $registry, $asOf);
            $options[] = new TransferOption($candidate->corridor, $compatibility, $financial,
                ...[...$reasons, ...$beneficiaryReasons, ...$financialReasons]);
        }

        return new TransferOptionsResponse($options);
    }

    private function beneficiary(TransferOptionCandidate $candidate, DateTimeImmutable $asOf): array
    {
        $beneficiary = $candidate->beneficiary;
        if ($beneficiary === null) {
            return ['UNKNOWN', [OptionsReason::BeneficiaryUnknown->value]];
        }
        if (! $candidate->scope->equals($beneficiary->scope) || $beneficiary->observedAt > $asOf
            || $beneficiary->expiresAt <= $asOf || $beneficiary->expiresAt <= $beneficiary->observedAt
            || $beneficiary->revocation !== RevocationStatus::NotRevoked || $beneficiary->state === EvidenceState::Unknown) {
            return ['UNKNOWN', [OptionsReason::BeneficiaryUnavailable->value]];
        }
        $corridor = $candidate->corridor;
        if ($beneficiary->state !== EvidenceState::Verified || ! $beneficiary->registered
            || $beneficiary->destinationCountry !== $corridor->destinationCountry
            || $beneficiary->destinationCurrency !== $corridor->destinationCurrency
            || $beneficiary->payoutMethod !== $corridor->payoutMethod || $beneficiary->beneficiaryType !== $corridor->beneficiaryType) {
            return ['INCOMPATIBLE', [OptionsReason::BeneficiaryIncompatible->value]];
        }

        return ['COMPATIBLE', []];
    }

    private function financial(TransferOptionCandidate $candidate, RegistryEvaluation $registry, DateTimeImmutable $asOf): array
    {
        $readiness = null;
        foreach ($registry->lookup->records as $record) {
            if ($record->kind === EvidenceKind::FinancialReadiness) {
                $readiness = $record;
            }
        }
        if ($readiness !== null && in_array($readiness->state, [EvidenceState::Denied, EvidenceState::Hold], true)) {
            return ['DENIED', [ReasonCode::FinancialReadinessDenied->value]];
        }
        if ($candidate->financialTerms === null) {
            return ['UNKNOWN', [OptionsReason::FinancialUnknown->value]];
        }
        if (! $candidate->financialTerms->corridor->equals($candidate->corridor)) {
            return ['DENIED', [FinancialReason::ScopeMismatch->value]];
        }
        $decision = (new FinancialTermsValidator)->evaluate($candidate->financialTerms, $asOf, $candidate->financialContract, ...$candidate->pricingEvidence);
        if ($decision->status !== 'VERIFIED') {
            return [$decision->status, $decision->reasons];
        }
        $synthetic = in_array('synthetic_evidence', $decision->dispatchReasons, true);
        foreach ($registry->lookup->records as $record) {
            $synthetic = $synthetic || $record->source === EvidenceSource::Synthetic;
        }
        if ($synthetic || ! $registry->lookup->accepted || $readiness === null || $readiness->state !== EvidenceState::Verified) {
            $reasons = [OptionsReason::FinancialUnknown->value];
            if ($synthetic) {
                $reasons[] = ReasonCode::SyntheticEvidence->value;
            }

            return ['UNKNOWN', $reasons];
        }

        return ['VERIFIED', []];
    }
}
