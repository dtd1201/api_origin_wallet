<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

/** Dynamic-only operator policy; these observations never grant corridor authorization. */
final class DynamicAccountEligibility
{
    public static function permitsAuxiliaryObservations(?string $complianceStatus, ?string $oddStatus): bool
    {
        // Callers must still require an active CLEAR account/provider, verified
        // bindings, cleared RFI and reconciliation. Missing local observations
        // are permitted, not promoted to clearance. Explicit restrictions and
        // unsupported values remain blocking; AML/RFI evidence is checked separately.
        return in_array($complianceStatus, [null, 'unknown', 'clear', 'approved'], true)
            && in_array($oddStatus, [null, 'unknown', 'clear', 'not_required'], true);
    }
}
