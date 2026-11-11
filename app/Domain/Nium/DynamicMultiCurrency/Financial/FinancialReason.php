<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Financial;

enum FinancialReason: string
{
    case ContractMissing = 'financial_contract_missing';
    case ContractUnknown = 'financial_contract_unknown';
    case EffectiveMissing = 'effective_pricing_missing';
    case ConfirmationMissing = 'customer_financial_terms_missing';
    case QuoteMissing = 'provider_quote_missing';
    case QuoteBindingMismatch = 'provider_quote_binding_mismatch';
    case EvidenceUnknown = 'pricing_evidence_unknown';
    case EvidenceDenied = 'pricing_evidence_denied';
    case EvidenceHold = 'pricing_evidence_hold';
    case EvidenceExpired = 'pricing_evidence_expired';
    case EvidenceFuture = 'pricing_evidence_future_dated';
    case EvidenceWindowInvalid = 'pricing_evidence_invalid_window';
    case EvidenceRevoked = 'pricing_evidence_revoked';
    case RevocationUnknown = 'pricing_revocation_unknown';
    case Conflicting = 'pricing_evidence_conflicting';
    case DuplicateId = 'pricing_evidence_duplicate_id';
    case MixedOrigin = 'pricing_evidence_origin_mixed';
    case ScopeMismatch = 'financial_scope_mismatch';
    case VersionMismatch = 'financial_contract_version_mismatch';
    case TermsChanged = 'financial_terms_changed';
    case UnsupportedMethod = 'payout_method_unsupported';
    case FxRequirementUnknown = 'fx_requirement_unknown';
    case FxRequirementMismatch = 'fx_requirement_mismatch';
    case FxTermsUnknown = 'fx_terms_unknown';
    case FeeUnknown = 'transaction_fee_unknown';
    case DebitUnknown = 'total_wallet_debit_unknown';
    case RecipientUnknown = 'estimated_recipient_unknown';
    case CurrencyMismatch = 'financial_currency_mismatch';
    case FeeCurrencyMismatch = 'fee_currency_mismatch';
    case AllocationUnknown = 'fee_allocation_unknown';
    case AllocationMismatch = 'fee_allocation_mismatch';
    case CalculationUnknown = 'debit_calculation_contract_unknown';
    case FxCalculationUnknown = 'fx_calculation_contract_unknown';
    case PrecisionUnknown = 'currency_precision_unknown';
    case PrecisionInvalid = 'financial_precision_invalid';
    case RoundingUnknown = 'rounding_contract_unknown';
    case AmountInvalid = 'financial_amount_invalid';
    case DebitInconsistent = 'total_wallet_debit_inconsistent';
    case RecipientInconsistent = 'estimated_recipient_inconsistent';
    case ConfirmationSourceInvalid = 'customer_confirmation_source_invalid';
    case SgdHold = 'sgd_local_hold';
    case UnsupportedProvider = 'financial_provider_unsupported';

    public function isUnknown(): bool
    {
        return in_array($this, [self::ContractMissing, self::ContractUnknown, self::EffectiveMissing,
            self::ConfirmationMissing, self::QuoteMissing, self::EvidenceUnknown, self::RevocationUnknown,
            self::FxRequirementUnknown, self::FxTermsUnknown, self::FeeUnknown, self::DebitUnknown,
            self::RecipientUnknown, self::AllocationUnknown, self::CalculationUnknown,
            self::FxCalculationUnknown, self::PrecisionUnknown, self::RoundingUnknown], true);
    }
}
