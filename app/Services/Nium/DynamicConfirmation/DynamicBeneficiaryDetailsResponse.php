<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use RuntimeException;

/** Direct V2 object, with the field names observed in Production. No aliases or unmasking. */
final class DynamicBeneficiaryDetailsResponse
{
    // Common identity for the existing bank-payout product. Bank/account type,
    // routing, address and identification requirements come from Nium's schema.
    private const REQUIRED = ['beneficiaryName', 'beneficiaryAccountType', 'beneficiaryAccountNumber',
        'destinationCountry', 'destinationCurrency', 'payoutMethod'];
    private const RECEIVING_FIELDS = ['beneficiaryBankName', 'beneficiaryBankAccountType', 'beneficiaryBankCode',
        'routingCodeType1', 'routingCodeValue1', 'routingCodeType2', 'routingCodeValue2'];

    public static function expected(array $registered): array
    {
        self::plain($registered['clientLegalEntity'] ?? null);
        foreach (self::REQUIRED as $field) {
            self::plain($registered[$field] ?? null);
        }
        self::identifier($registered['beneficiaryAccountNumber']);
        // Legal entity belongs to the authenticated registration request, not a
        // field promised by the Details response. All other supplied receiving
        // inputs must be echoed exactly except bank-name casing; unknown mappings remain a refusal.
        unset($registered['clientLegalEntity']);
        foreach ($registered as $field => $value) {
            if (is_string($value)) self::plain($value);
            if (str_starts_with($field, 'routingCodeValue') && $value !== null) self::identifier($value);
        }
        ksort($registered, SORT_STRING);
        return $registered;
    }

    public static function verify(string $body, array $registered, string $beneficiary, string $client, string $customer): array
    {
        $expected = self::expected($registered);
        try {
            ExactProviderJson::decode($body);
            $response = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            self::refuse();
        }
        if (! is_string($response['beneficiaryHashId'] ?? null) || $response['beneficiaryHashId'] !== $beneficiary) self::refuse();
        foreach (['clientHashId' => $client, 'customerHashId' => $customer] as $field => $value) {
            if (array_key_exists($field, $response) && $response[$field] !== $value) self::refuse();
        }
        if (array_key_exists('clientLegalEntity', $response)
            && $response['clientLegalEntity'] !== ($registered['clientLegalEntity'] ?? null)) self::refuse();
        self::maskFlags($response);
        foreach ($expected as $field => $value) {
            if (! array_key_exists($field, $response)) self::refuse();
            if (is_string($value)) self::plain($response[$field]);
            if ($field === 'beneficiaryBankName' && is_string($value)) {
                // Production Details may change bank-name casing. Fold only this field:
                // no trimming, whitespace/punctuation changes or Unicode normalization.
                if (mb_convert_case($response[$field], MB_CASE_FOLD, 'UTF-8')
                    !== mb_convert_case($value, MB_CASE_FOLD, 'UTF-8')) self::refuse();
            } elseif ($response[$field] !== $value) self::refuse();
        }
        foreach (self::RECEIVING_FIELDS as $field) {
            // Absent optional registration inputs may be absent/null at Nium,
            // but extra non-null receiving instructions are contradictory.
            if (! array_key_exists($field, $expected) && ($response[$field] ?? null) !== null) self::refuse();
        }
        return ['beneficiaryHashId' => $beneficiary, ...$expected];
    }

    private static function plain(mixed $value): void
    {
        if (! is_string($value) || trim($value) === ''
            || preg_match('/[\x00-\x1f\x7f*#?•●…]|\.{2,}|masked|redacted/iu', $value) !== 0) self::refuse();
    }

    private static function identifier(mixed $value): void
    {
        // Mask ambiguity is a refusal, not an account-format rule. A suffix such
        // as XXX is not interpreted or stripped; the provider schema validates it.
        self::plain($value);
        if (preg_match('/\A[xX]{2,}/', $value)) self::refuse();
    }

    private static function maskFlags(array $response): void
    {
        foreach ($response as $key => $value) {
            if (preg_match('/mask|redact/i', (string) $key) && $value !== false && $value !== null) self::refuse();
            if (is_array($value)) self::maskFlags($value);
        }
    }

    private static function refuse(): never
    {
        throw new RuntimeException('dynamic_registered_account_customer_scope_unavailable');
    }
}
