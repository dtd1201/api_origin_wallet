<?php

namespace App\Services\Wise;

use App\Models\Beneficiary;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

trait WiseDataFormatter
{
    private function normalizeBeneficiaryStatus(?string $status, ?bool $active = null): string
    {
        if ($active === false) {
            return 'inactive';
        }

        return match (strtolower((string) $status)) {
            'active', 'available' => 'active',
            'pending', 'processing', 'queued' => 'pending',
            'inactive', 'disabled' => 'inactive',
            default => 'active',
        };
    }

    private function normalizeTransferStatus(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'outgoing_payment_sent', 'funds_refunded', 'completed', 'bounced_back', 'charged_back' => 'completed',
            'cancelled', 'canceled' => 'cancelled',
            'processing', 'funds_converted', 'incoming_payment_waiting', 'pending', 'incoming_payment_sent' => 'pending',
            'rejected', 'failed' => 'failed',
            default => 'submitted',
        };
    }

    private function normalizeWebhookTransferStatus(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'outgoing_payment_sent', 'completed', 'funds_refunded', 'bounced_back', 'charged_back' => 'completed',
            'cancelled', 'canceled' => 'cancelled',
            'rejected', 'failed' => 'failed',
            'processing', 'funds_converted', 'incoming_payment_waiting', 'incoming_payment_sent', 'pending' => 'pending',
            default => 'submitted',
        };
    }

    private function beneficiaryName(Beneficiary $beneficiary): string
    {
        return $beneficiary->company_name
            ?: $beneficiary->full_name;
    }

    private function beneficiaryLegalType(Beneficiary $beneficiary): string
    {
        return strtolower((string) $beneficiary->beneficiary_type) === 'business'
            ? 'BUSINESS'
            : 'PRIVATE';
    }

    private function ownedByCustomer(Beneficiary $beneficiary): bool
    {
        $wise = (array) (($beneficiary->raw_data ?? [])['wise'] ?? []);

        return (bool) ($wise['ownedByCustomer'] ?? $wise['owned_by_customer'] ?? false);
    }

    private function defaultRecipientType(Beneficiary $beneficiary, array $wise): string
    {
        if (filled($wise['type'] ?? null)) {
            return (string) $wise['type'];
        }

        if (filled($beneficiary->iban)) {
            return 'iban';
        }

        if (strtoupper((string) $beneficiary->currency) === 'GBP' && filled($beneficiary->bank_code)) {
            return 'sort_code';
        }

        if (filled($beneficiary->swift_bic)) {
            return 'swift_code';
        }

        return 'bank_account';
    }

    private function defaultRecipientDetails(Beneficiary $beneficiary, array $wise): array
    {
        $details = array_filter([
            'legalType' => $this->beneficiaryLegalType($beneficiary),
            'accountNumber' => $beneficiary->account_number,
            'iban' => $beneficiary->iban,
            'swiftCode' => $beneficiary->swift_bic,
            'bankCode' => $beneficiary->bank_code,
            'branchCode' => $beneficiary->branch_code,
            'sortCode' => strtoupper((string) $beneficiary->currency) === 'GBP' ? $beneficiary->bank_code : null,
            'abartn' => strtoupper((string) $beneficiary->currency) === 'USD' ? $beneficiary->bank_code : null,
            'ifscCode' => strtoupper((string) $beneficiary->currency) === 'INR' ? $beneficiary->bank_code : null,
            'dateOfBirth' => $wise['dateOfBirth'] ?? $wise['date_of_birth'] ?? null,
            'address' => $this->beneficiaryAddressPayload($beneficiary, $wise),
            'email' => $beneficiary->email,
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);

        return array_replace_recursive($details, (array) ($wise['details'] ?? []));
    }

    private function beneficiaryAddressPayload(Beneficiary $beneficiary, array $wise): array
    {
        return array_filter([
            'country' => $beneficiary->country_code,
            'countryCode' => $beneficiary->country_code,
            'city' => $beneficiary->city,
            'state' => $beneficiary->state,
            'stateCode' => $beneficiary->state,
            'postCode' => $beneficiary->postal_code,
            'firstLine' => $beneficiary->address_line1,
            'secondLine' => $beneficiary->address_line2,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function senderProfileType(User $user): string
    {
        return strtolower((string) $user->profile?->user_type) === 'business'
            ? 'BUSINESS'
            : 'PERSONAL';
    }

    private function senderOriginator(User $user): array
    {
        $profile = $user->profile;

        return array_filter([
            'legalEntityType' => strtolower((string) $profile?->user_type) === 'business' ? 'BUSINESS' : 'PRIVATE',
            'name' => strtolower((string) $profile?->user_type) === 'business'
                ? ($profile?->company_name ?: $user->full_name)
                : $user->full_name,
            'dateOfBirth' => null,
            'companyRegistrationNumber' => $profile?->company_reg_no,
            'address' => array_filter([
                'firstLine' => $profile?->address_line1,
                'city' => $profile?->city,
                'stateCode' => $profile?->state,
                'countryCode' => $profile?->country_code,
                'postCode' => $profile?->postal_code,
            ], static fn ($value) => $value !== null && $value !== ''),
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    private function transferReference(string $reference): string
    {
        return Str::limit($reference, 70, '');
    }

    private function transferFailureMessage(array $responseData, string $fallback): string
    {
        return Arr::get($responseData, 'errors.0.message')
            ?? Arr::get($responseData, 'error')
            ?? Arr::get($responseData, 'message')
            ?? $fallback;
    }
}
