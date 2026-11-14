<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use App\Domain\Nium\DynamicMultiCurrency\Financial\DocumentaryFeeSchedule;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FinalizedUsdPayoutPreparation;
use App\Models\Beneficiary;
use RuntimeException;

/** Contracted Nium fees allocated in customer consent; actual collection requires separate evidence. */
class DynamicPayoutFinancialAuthorization
{
    public const VERSION = 'nium-contracted-local-usd-inclusive-v4';
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

        // Preserve the existing allocation arithmetic as consent, not collection proof.
        // Neither this fee nor Nium's FX margin is Origin revenue.
        return ['version' => self::VERSION, ...$row, ...DocumentaryFeeSchedule::LOCAL_FEE_CLASSIFICATION,
            'contracted_nium_transaction_fee_usd' => FinalizedUsdPayoutPreparation::FEE_USD,
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
