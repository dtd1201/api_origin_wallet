<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Registry;

enum RegistryReason: string
{
    case ScopeNotFound = 'registry_scope_not_found';
    case IncompleteEvidence = 'registry_evidence_incomplete';
    case DuplicateIdentifier = 'registry_duplicate_evidence_id';
    case ConflictingAssertions = 'registry_conflicting_assertions';
    case InvalidWindow = 'registry_invalid_validity_window';
    case Expired = 'registry_evidence_expired';
    case FutureDated = 'registry_evidence_future_dated';
    case Revoked = 'registry_evidence_revoked';
    case UnknownRevocation = 'registry_revocation_unknown';
    case LocalAttestationRequired = 'registry_local_attestation_required';
}
