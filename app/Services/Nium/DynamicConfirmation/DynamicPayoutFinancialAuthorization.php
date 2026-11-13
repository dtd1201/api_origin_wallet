<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use App\Domain\Nium\DynamicMultiCurrency\Financial\DocumentaryFeeSchedule;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FinalizedUsdPayoutPreparation;
use App\Models\Beneficiary;
use RuntimeException;

/** Accepted customer pricing only. Provider settlement must be observed independently. */
class DynamicPayoutFinancialAuthorization
{
    public const VERSION = 'origin-nium-contracted-local-usd-inclusive-v2';
    public const REFUSALS = ['dynamic_financial_scope_required', 'dynamic_pricing_corridor_unsupported',
        'dynamic_financial_terms_changed'];

    public function unavailableReasons(?Beneficiary $beneficiary = null): array
    {
        if ($beneficiary === null) return ['dynamic_financial_scope_required'];

        return $this->row($beneficiary) === null ? ['dynamic_pricing_corridor_unsupported'] : [];
    }

    public function assertAvailable(?Beneficiary $beneficiary = null): void
    {
        $reasons = $this->unavailableReasons($beneficiary);
        if ($reasons !== []) {
            throw new RuntimeException($reasons[0]);
        }
    }

    public function pricing(Beneficiary $beneficiary): array
    {
        $this->assertAvailable($beneficiary);
        $row = $this->row($beneficiary);
        // All accepted rows share USD 20; refuse drift from the existing arithmetic.
        if ($row['fee'] !== FinalizedUsdPayoutPreparation::FEE_USD || $row['fee_currency'] !== 'USD') {
            throw new RuntimeException('dynamic_financial_terms_changed');
        }

        return ['version' => self::VERSION, ...$row, 'origin_service_fee_usd' => '0',
            'fee_payer' => 'SENDER', 'fee_treatment' => 'INCLUDED_IN_TOTAL_USD_DEBIT',
            'rate_treatment' => 'PRESERVE_NIUM_QUOTED_RATE_NO_ADDITIONAL_MARGIN',
            'provider_settlement' => 'INDEPENDENT_AUTHENTICATED_RECONCILIATION'];
    }

    public function fingerprint(Beneficiary $beneficiary): string
    {
        return hash('sha256', json_encode($this->pricing($beneficiary), JSON_THROW_ON_ERROR));
    }

    private function row(Beneficiary $beneficiary): ?array
    {
        return DocumentaryFeeSchedule::contractedLocal('USD', (string) $beneficiary->country_code,
            (string) $beneficiary->currency, (string) $beneficiary->payout_method);
    }
}
