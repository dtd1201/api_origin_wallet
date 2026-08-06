<?php

namespace App\Support;

use Illuminate\Support\Arr;

final class NiumOperationalData
{
    private const FIELDS = [
        'eventId', 'event_id', 'requestId', 'request_id', 'reference', 'clientReference',
        'systemReferenceNumber', 'system_reference_number', 'paymentId', 'payment_id',
        'transactionId', 'transaction_id', 'status', 'subStatus', 'complianceStatus',
        'template', 'customerHashId', 'walletHashId', 'uniquePaymentId', 'currencyCode',
        'accountCategory', 'accountType',
        'currency', 'sourceCurrency', 'destinationCurrency', 'amount', 'sourceAmount',
        'destinationAmount', 'fee', 'feeAmount', 'errorCode', 'code', 'dateTime',
        'createdAt', 'updatedAt', 'lastUpdatedAt', 'completedAt',
    ];

    public static function project(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $safe = [];

        foreach (self::FIELDS as $field) {
            $value = Arr::get($payload, $field);

            if (is_scalar($value) && $value !== '') {
                $safe[$field] = $value;
            }
        }

        return $safe;
    }
}
