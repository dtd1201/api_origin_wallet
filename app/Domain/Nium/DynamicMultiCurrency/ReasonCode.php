<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency;

/** Stable machine-readable values. Never expose provider response text here. */
enum ReasonCode: string
{
    case UnknownCorridor = 'unknown_corridor';
    case UnsupportedProvider = 'unsupported_provider';
    case CapabilityMissing = 'provider_capability_missing';
    case CapabilityDenied = 'provider_capability_denied';
    case EntitlementMissing = 'account_entitlement_missing';
    case EntitlementDenied = 'account_entitlement_denied';
    case OperationalReadinessMissing = 'operational_readiness_missing';
    case OperationalReadinessDenied = 'operational_readiness_denied';
    case FinancialReadinessMissing = 'financial_readiness_missing';
    case FinancialReadinessDenied = 'financial_readiness_denied';
    case ActivationMissing = 'local_activation_missing';
    case ActivationDenied = 'local_activation_denied';
    case OverrideClearanceMissing = 'override_clearance_missing';
    case OverrideClearanceDenied = 'override_clearance_denied';
    case ScopeMismatch = 'evidence_scope_mismatch';
    case StaleEvidence = 'evidence_stale';
    case FutureEvidence = 'evidence_not_yet_valid';
    case InvalidEvidenceWindow = 'evidence_invalid_window';
    case ConflictingEvidence = 'evidence_conflicting';
    case UnknownEvidence = 'evidence_unknown';
    case MixedEvidenceOrigin = 'evidence_origin_mixed';
    case HoldOverride = 'hold_override';
    case DenyOverride = 'deny_override';
    case UnresolvedOverride = 'override_unresolved';
    case SgdLocalHold = 'sgd_local_hold';
    case SyntheticEvidence = 'synthetic_evidence';
    case DispatchDisabled = 'phase_one_dispatch_disabled';
}
