<?php

namespace App\Services\Unlimit;

use App\Models\Beneficiary;
use App\Models\Transfer;
use Illuminate\Support\Arr;

class UnlimitPayoutPayloadFactory
{
    public function payoutAccount(Beneficiary $beneficiary): array
    {
        $rawData = (array) ($beneficiary->raw_data ?? []);
        $unlimit = (array) ($rawData['unlimit'] ?? []);

        return $this->filterBlank([
            'customer' => $this->customerPayload($beneficiary, (array) ($unlimit['customer'] ?? [])),
            'ewallet_account' => $this->ewalletAccountPayload($beneficiary, (array) ($unlimit['ewallet_account'] ?? [])),
            'card_account' => $unlimit['card_account'] ?? null,
            'payment_data' => $unlimit['payment_data'] ?? null,
        ]);
    }

    public function payoutPayload(Transfer $transfer): array
    {
        $transfer->loadMissing(['beneficiary', 'user']);

        $rawData = (array) ($transfer->raw_data ?? []);
        $unlimit = (array) ($rawData['unlimit'] ?? []);
        $beneficiary = $transfer->beneficiary;
        $beneficiaryAccount = $beneficiary instanceof Beneficiary
            ? $this->payoutAccount($beneficiary)
            : [];
        $paymentMethod = strtoupper((string) ($unlimit['payment_method'] ?? $rawData['payment_method'] ?? ''));

        $payload = [
            'request' => $this->requestPayload($transfer, (array) ($unlimit['request'] ?? [])),
            'merchant_order' => $this->merchantOrderPayload($transfer, (array) ($unlimit['merchant_order'] ?? [])),
            'payment_method' => $paymentMethod,
            'payout_data' => $this->payoutDataPayload($transfer, (array) ($unlimit['payout_data'] ?? [])),
            'customer' => array_replace_recursive(
                (array) ($beneficiaryAccount['customer'] ?? []),
                (array) ($unlimit['customer'] ?? []),
            ),
        ];

        foreach (['card_account', 'ewallet_account', 'payment_data'] as $key) {
            $value = $unlimit[$key] ?? $beneficiaryAccount[$key] ?? null;

            if ($value !== null && $value !== '' && $value !== []) {
                $payload[$key] = $value;
            }
        }

        return $this->filterBlank($payload);
    }

    private function requestPayload(Transfer $transfer, array $overrides): array
    {
        return array_replace_recursive([
            'id' => substr((string) ($transfer->client_reference ?: $transfer->transfer_no), 0, 50),
            'time' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ], $overrides);
    }

    private function merchantOrderPayload(Transfer $transfer, array $overrides): array
    {
        return array_replace_recursive($this->filterBlank([
            'id' => substr((string) ($transfer->client_reference ?: $transfer->transfer_no), 0, 50),
            'description' => $transfer->reference_text ?: $transfer->purpose_code,
        ]), $overrides);
    }

    private function payoutDataPayload(Transfer $transfer, array $overrides): array
    {
        return array_replace_recursive($this->filterBlank([
            'amount' => (float) ($transfer->target_amount ?? $transfer->source_amount),
            'currency' => strtoupper((string) $transfer->target_currency),
            'note' => $transfer->reference_text ?: $transfer->transfer_no,
        ]), $overrides);
    }

    private function customerPayload(Beneficiary $beneficiary, array $overrides): array
    {
        [$firstName, $lastName] = $this->splitName((string) ($beneficiary->company_name ?: $beneficiary->full_name));

        return array_replace_recursive($this->filterBlank([
            'id' => (string) $beneficiary->user_id,
            'email' => $beneficiary->email,
            'phone' => $beneficiary->phone,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => $beneficiary->company_name ?: $beneficiary->full_name,
            'document_type' => Arr::get($beneficiary->raw_data ?? [], 'unlimit.customer.document_type'),
            'identity' => Arr::get($beneficiary->raw_data ?? [], 'unlimit.customer.identity'),
            'birth_date' => Arr::get($beneficiary->raw_data ?? [], 'unlimit.customer.birth_date'),
            'tax_reason_code' => Arr::get($beneficiary->raw_data ?? [], 'unlimit.customer.tax_reason_code'),
            'living_address' => [
                'country' => $beneficiary->country_code,
                'state' => $beneficiary->state,
                'city' => $beneficiary->city,
                'zip' => $beneficiary->postal_code,
                'street' => trim((string) $beneficiary->address_line1.' '.(string) $beneficiary->address_line2),
            ],
        ]), $overrides);
    }

    private function ewalletAccountPayload(Beneficiary $beneficiary, array $overrides): array
    {
        return array_replace_recursive($this->filterBlank([
            'id' => $beneficiary->account_number ?: $beneficiary->iban ?: $beneficiary->email ?: $beneficiary->phone,
            'type' => Arr::get($beneficiary->raw_data ?? [], 'unlimit.ewallet_account.type'),
            'name' => $beneficiary->company_name ?: $beneficiary->full_name,
            'bank_branch' => $beneficiary->branch_code,
            'bank_code' => $beneficiary->bank_code ?: $beneficiary->swift_bic,
            'bank_name' => $beneficiary->bank_name,
        ]), $overrides);
    }

    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];

        return [
            $parts[0] ?? $name,
            $parts[1] ?? null,
        ];
    }

    private function filterBlank(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $value = $this->filterBlank($value);
            }

            if ($value === null || $value === '' || $value === []) {
                unset($payload[$key]);
                continue;
            }

            $payload[$key] = $value;
        }

        return $payload;
    }
}
