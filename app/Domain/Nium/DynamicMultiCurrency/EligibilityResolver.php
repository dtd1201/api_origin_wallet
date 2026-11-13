<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency;

use DateTimeImmutable;

/**
 * Pure evaluation of a supplied evidence snapshot at a supplied instant.
 * No clock, configuration, container, persistence, network or dispatch access.
 * Callers must supply the entire relevant snapshot, including applicable holds.
 * OverrideClearance must explicitly attest that applicable overrides were checked.
 * This class cannot discover missing overrides or authenticate assertions.
 */
final class EligibilityResolver
{
    public function resolve(CorridorIdentity $corridor, DateTimeImmutable $asOf, CorridorEvidence ...$evidence): EligibilityDecision
    {
        $reasons = [];
        $byKind = [];
        $origins = [];
        $hasSyntheticEvidence = false;

        if ($corridor->provider !== 'nium') {
            $reasons[] = ReasonCode::UnsupportedProvider;
        }

        foreach ($evidence as $record) {
            $hasSyntheticEvidence = $hasSyntheticEvidence || $record->synthetic;
            $origins[$record->synthetic ? 'synthetic' : 'attested'] = true;

            if (! $record->corridor->equals($corridor)) {
                $reasons[] = ReasonCode::ScopeMismatch;

                continue;
            }

            if ($record->expiresAt <= $record->observedAt) {
                $reasons[] = ReasonCode::InvalidEvidenceWindow;
            }
            if ($record->observedAt > $asOf) {
                $reasons[] = ReasonCode::FutureEvidence;
            }
            if ($record->expiresAt <= $asOf) {
                $reasons[] = ReasonCode::StaleEvidence;
            }

            // A stale hold or denial cannot silently become an approval.
            if ($record->state === EvidenceState::Hold) {
                $reasons[] = ReasonCode::HoldOverride;
            }
            if ($record->kind === EvidenceKind::Override) {
                $reasons[] = match ($record->state) {
                    EvidenceState::Hold => ReasonCode::HoldOverride,
                    EvidenceState::Denied => ReasonCode::DenyOverride,
                    default => ReasonCode::UnresolvedOverride,
                };

                continue;
            }

            $byKind[$record->kind->value][] = $record;
            if ($record->state === EvidenceState::Unknown) {
                $reasons[] = ReasonCode::UnknownEvidence;
            }
            if ($record->state === EvidenceState::Denied) {
                $reasons[] = $this->deniedReason($record->kind);
            }
        }

        if (count($origins) > 1) {
            $reasons[] = ReasonCode::MixedEvidenceOrigin;
        }
        if (! isset($byKind[EvidenceKind::ProviderCapability->value])) {
            $reasons[] = ReasonCode::UnknownCorridor;
        }
        foreach (EvidenceKind::required() as $kind) {
            $records = $byKind[$kind->value] ?? [];
            if ($records === []) {
                $reasons[] = $this->missingReason($kind);
            } elseif (count($records) > 1) {
                // No last-write-wins, deduplication or newer-evidence fallback.
                $reasons[] = ReasonCode::ConflictingEvidence;
            }
        }

        return new EligibilityDecision($hasSyntheticEvidence, ...$reasons);
    }

    private function missingReason(EvidenceKind $kind): ReasonCode
    {
        return match ($kind) {
            EvidenceKind::ProviderCapability => ReasonCode::CapabilityMissing,
            EvidenceKind::AccountEntitlement => ReasonCode::EntitlementMissing,
            EvidenceKind::OperationalReadiness => ReasonCode::OperationalReadinessMissing,
            EvidenceKind::FinancialReadiness => ReasonCode::FinancialReadinessMissing,
            EvidenceKind::LocalActivation => ReasonCode::ActivationMissing,
            EvidenceKind::OverrideClearance => ReasonCode::OverrideClearanceMissing,
            EvidenceKind::Override => ReasonCode::UnresolvedOverride,
        };
    }

    private function deniedReason(EvidenceKind $kind): ReasonCode
    {
        return match ($kind) {
            EvidenceKind::ProviderCapability => ReasonCode::CapabilityDenied,
            EvidenceKind::AccountEntitlement => ReasonCode::EntitlementDenied,
            EvidenceKind::OperationalReadiness => ReasonCode::OperationalReadinessDenied,
            EvidenceKind::FinancialReadiness => ReasonCode::FinancialReadinessDenied,
            EvidenceKind::LocalActivation => ReasonCode::ActivationDenied,
            EvidenceKind::OverrideClearance => ReasonCode::OverrideClearanceDenied,
            EvidenceKind::Override => ReasonCode::DenyOverride,
        };
    }
}
