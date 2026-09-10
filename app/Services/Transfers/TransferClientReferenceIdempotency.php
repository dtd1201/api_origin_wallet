<?php

namespace App\Services\Transfers;

use App\Models\Transfer;
use Illuminate\Database\QueryException;

final class TransferClientReferenceIdempotency
{
    public const CONSTRAINT = 'transfers_user_provider_client_reference_unique';

    private const SEMANTIC_FIELDS = [
        'beneficiary_id',
        'source_bank_account_id',
        'fx_quote_id',
        'transfer_type',
        'source_currency',
        'target_currency',
        'source_amount',
        'target_amount',
        'fx_rate',
        'fee_amount',
        'fee_currency',
        'purpose_code',
        'reference_text',
    ];

    private const DECIMAL_SCALES = [
        'source_amount' => 8,
        'target_amount' => 8,
        'fx_rate' => 10,
        'fee_amount' => 8,
    ];

    public function matches(Transfer $stored, array $normalizedRequest): bool
    {
        return $this->fingerprint($this->fromTransfer($stored)) === $this->fingerprint($normalizedRequest);
    }

    public function isExpectedUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $message = $exception->getMessage().' '.(string) ($exception->errorInfo[2] ?? '');

        if ($sqlState === '23505') {
            return str_contains($message, self::CONSTRAINT);
        }

        return in_array($sqlState, ['19', '23000'], true)
            && str_contains($message, 'UNIQUE constraint failed: transfers.user_id, transfers.provider_id, transfers.client_reference');
    }

    private function fromTransfer(Transfer $transfer): array
    {
        return collect(self::SEMANTIC_FIELDS)
            ->mapWithKeys(fn (string $field): array => [$field => $transfer->{$field}])
            ->all();
    }

    private function fingerprint(array $values): string
    {
        $canonical = [];
        foreach (self::SEMANTIC_FIELDS as $field) {
            $value = $values[$field] ?? null;
            if ($value !== null && isset(self::DECIMAL_SCALES[$field])) {
                $value = $this->decimal((string) $value, self::DECIMAL_SCALES[$field]);
            } elseif ($value !== null && in_array($field, ['beneficiary_id', 'source_bank_account_id', 'fx_quote_id'], true)) {
                $value = (string) $value;
            }
            $canonical[$field] = $value;
        }

        return hash('sha256', json_encode($canonical, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function decimal(string $value, int $scale): string
    {
        $value = trim($value);
        if (preg_match('/^([+-]?)(\d+)(?:\.(\d*))?$/', $value, $matches) !== 1) {
            return $value;
        }

        $integer = ltrim($matches[2], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = str_pad(substr($matches[3] ?? '', 0, $scale), $scale, '0');
        $sign = $matches[1] === '-' && ($integer !== '0' || trim($fraction, '0') !== '') ? '-' : '';

        return $sign.$integer.'.'.$fraction;
    }
}
