<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use App\Models\Beneficiary;

final class DynamicReceivingAccountBinding
{
    /** Exact editable-record binding; a trusted adapter supplies the REGISTERED hash. */
    public static function fingerprint(Beneficiary $beneficiary): string
    {
        $fields = ['user_id', 'provider_id', 'external_beneficiary_id', 'beneficiary_type',
            'full_name', 'company_name', 'country_code', 'currency', 'payout_method',
            'bank_name', 'bank_code', 'branch_code', 'account_number', 'iban', 'swift_bic',
            'address_line1', 'address_line2', 'city', 'state', 'postal_code'];
        $binding = ['version' => 'nium-dynamic-receiving-account-v1'];
        foreach ($fields as $field) {
            $value = $beneficiary->getAttribute($field);
            $binding[$field] = $value === null ? null : (string) $value;
        }
        // Provider-specific account/routing fields can also live in raw_data.
        $binding['provider_details'] = self::canonical($beneficiary->raw_data);

        return hash('sha256', json_encode($binding, JSON_THROW_ON_ERROR));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }
}
