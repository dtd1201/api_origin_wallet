<?php

namespace App\Services\Nium;

use App\Models\UserProviderAccount;
use RuntimeException;

final class NiumCorporateRfiService
{
    public function __construct(private readonly NiumService $niumService) {}

    public function fetch(UserProviderAccount $account): array
    {
        $account->loadMissing('user');
        $customerHashId = trim((string) $account->external_customer_id);
        if ($customerHashId === '' || $account->user === null) {
            throw new RuntimeException('Nium Corporate RFI fetch requires a verified customer and local owner.');
        }

        $response = $this->niumService->get(
            path: $this->niumService->path(
                (string) config('services.nium.customer_rfi_fetch_endpoint'),
                ['clientHashId' => $this->niumService->clientId()],
            ),
            query: ['customerHashId' => $customerHashId],
            user: $account->user,
            operation: 'customer_corporate_rfi_fetch',
            externalReference: $customerHashId,
        );

        if (! $response->successful()) {
            throw NiumProviderRequestException::fromResponse($response, 'Nium Corporate RFI fetch failed.');
        }

        $body = $response->json();
        if (! is_array($body) || ! is_array($body['rfiTemplates'] ?? null) || ! array_is_list($body['rfiTemplates'])) {
            throw new RuntimeException('Nium Corporate RFI response has an invalid rfiTemplates shape.');
        }

        return array_map(function (mixed $item): array {
            if (! is_array($item) || array_is_list($item)) {
                throw new RuntimeException('Nium Corporate RFI response contains an invalid RFI item.');
            }

            $rfiHashId = $this->identifier($item['rfiHashId'] ?? null);
            $status = strtoupper(trim((string) ($item['status'] ?? '')));
            if ($rfiHashId === null || ! in_array($status, ['RFI_REQUESTED', 'RFI_RESPONDED'], true)) {
                throw new RuntimeException('Nium Corporate RFI item is missing authoritative identity or status.');
            }

            return array_filter([
                'rfiHashId' => $rfiHashId,
                'templateId' => $this->identifier($item['templateId'] ?? null),
                'referenceId' => $this->identifier($item['referenceId'] ?? null),
                'caseId' => $this->identifier($item['caseId'] ?? null),
                'status' => $status,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }, $body['rfiTemplates']);
    }

    private function identifier(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' && strlen($value) <= 255 ? $value : null;
    }
}
