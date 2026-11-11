<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency;

enum EvidenceKind: string
{
    case ProviderCapability = 'provider_capability';
    case AccountEntitlement = 'account_entitlement';
    case OperationalReadiness = 'operational_readiness';
    case FinancialReadiness = 'financial_readiness';
    case LocalActivation = 'local_activation';
    case OverrideClearance = 'override_clearance';
    case Override = 'override';

    /** @return list<self> */
    public static function required(): array
    {
        return [self::ProviderCapability, self::AccountEntitlement, self::OperationalReadiness,
            self::FinancialReadiness, self::LocalActivation, self::OverrideClearance];
    }
}
