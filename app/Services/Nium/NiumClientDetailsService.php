<?php

namespace App\Services\Nium;

use RuntimeException;

final class NiumClientDetailsService
{
    public function __construct(private readonly NiumService $niumService) {}

    public function get(bool $separateHumanApproval = false): array
    {
        if (! $separateHumanApproval) {
            throw new RuntimeException('HOLD_NIUM_CLIENT_DETAILS_HUMAN_APPROVAL_REQUIRED');
        }

        $response = $this->niumService->get($this->niumService->path(
            (string) config('services.nium.client_details_endpoint'),
            ['client' => $this->niumService->clientId()],
        ));
        $data = $response->json();

        if (! $response->successful() || ! is_array($data) || array_is_list($data)) {
            throw new RuntimeException('HOLD_NIUM_CLIENT_DETAILS_UNAVAILABLE');
        }

        return [
            'regulatoryRegion' => $this->string($data['regulatoryRegion'] ?? null),
            'allowThirdPartyFunding' => $this->boolean($data['allowThirdPartyFunding'] ?? null),
            'fundingInstrumentType' => $this->string($data['fundingInstrumentType'] ?? null),
            'ekycRedirectUrlConfigured' => is_string($data['ekycRedirectUrl'] ?? null)
                && trim($data['ekycRedirectUrl']) !== '',
            'currencies' => $this->projectList($data['currencies'] ?? [], [
                'currencyCode', 'remittanceAllowed', 'fxSellAllowed', 'authorizationOrder', 'decimalUnit',
            ]),
            'paymentIds' => $this->projectPaymentIds($data['paymentIds'] ?? []),
        ];
    }

    private function projectList(mixed $items, array $fields): array
    {
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_map(function (array $item) use ($fields): array {
            $projected = [];
            foreach ($fields as $field) {
                $projected[$field] = in_array($field, ['remittanceAllowed', 'fxSellAllowed'], true)
                    ? $this->boolean($item[$field] ?? null)
                    : ($field === 'authorizationOrder' || $field === 'decimalUnit'
                        ? $this->integer($item[$field] ?? null)
                        : $this->string($item[$field] ?? null));
            }

            return $projected;
        }, array_filter($items, 'is_array')));
    }

    private function projectPaymentIds(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_map(fn (array $item): array => [
            'currencyCode' => $this->string($item['currencyCode'] ?? null),
            'bankName' => $this->string($item['bankName'] ?? null),
            'bankNameFull' => $this->string($item['bankNameFull'] ?? null),
            'accountType' => $this->string($item['accountType'] ?? null),
            'uniquePaymentIdPresent' => filled($item['uniquePaymentId'] ?? null),
            'uniquePayerIdPresent' => filled($item['uniquePayerId'] ?? null),
        ], array_filter($items, 'is_array')));
    }

    private function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function boolean(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    private function integer(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }
}
