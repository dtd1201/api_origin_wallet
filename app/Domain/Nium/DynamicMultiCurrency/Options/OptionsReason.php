<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Options;

use App\Domain\Nium\DynamicMultiCurrency\Financial\FinancialReason;
use App\Domain\Nium\DynamicMultiCurrency\ReasonCode;
use App\Domain\Nium\DynamicMultiCurrency\Registry\RegistryReason;

enum OptionsReason: string
{
    case ScopeUnauthorized = 'options_scope_not_authorized';
    case PreparationClosed = 'dynamic_preparation_closed';
    case DispatchDisabled = 'dynamic_dispatch_disabled';
    case BeneficiaryUnknown = 'beneficiary_compatibility_unknown';
    case BeneficiaryUnavailable = 'beneficiary_unavailable';
    case BeneficiaryIncompatible = 'beneficiary_incompatible';
    case FinancialUnknown = 'financial_readiness_unverified';
    case ConflictingOptions = 'option_assertions_conflicting';
    case EvidenceUnavailable = 'option_evidence_unavailable';

    /** Only fixed machine codes cross the response boundary; never free text or IDs. */
    public static function publicCodes(string ...$codes): array
    {
        $allowed = [];
        foreach ([self::cases(), ReasonCode::cases(), RegistryReason::cases(), FinancialReason::cases()] as $cases) {
            foreach ($cases as $case) {
                $allowed[] = $case->value;
            }
        }
        $safe = array_values(array_intersect($codes, $allowed));
        if (array_diff($codes, $allowed) !== []) {
            $safe[] = self::EvidenceUnavailable->value;
        }
        $safe = array_values(array_unique($safe, SORT_STRING));
        sort($safe, SORT_STRING);

        return $safe;
    }
}
