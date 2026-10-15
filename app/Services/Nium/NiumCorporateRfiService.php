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
        if ($customerHashId === ''
            || $account->customer_id_verified_at === null
            || $account->security_conflict_at !== null
            || filled($account->security_conflict_reason)
            || $account->user === null) {
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

        $rfis = array_map(function (mixed $item): array {
            if (! is_array($item) || array_is_list($item)) {
                throw new RuntimeException('Nium Corporate RFI response contains an invalid RFI item.');
            }

            $rfiHashId = $this->identifier($item['rfiHashId'] ?? null);
            $templateId = $this->identifier($item['templateId'] ?? null);
            $template = $this->template($item['template'] ?? null);
            $remarks = $this->text($item['remarks'] ?? null, 10000);
            $status = strtoupper(trim((string) ($item['status'] ?? '')));
            if ($rfiHashId === null || ! in_array($status, ['RFI_REQUESTED', 'RFI_RESPONDED'], true)) {
                throw new RuntimeException('Nium Corporate RFI item is missing authoritative identity or status.');
            }
            if ($templateId === null || $template === null || $remarks === null) {
                throw new RuntimeException('Nium Corporate RFI item is missing its authoritative template.');
            }

            return [
                'rfiHashId' => $rfiHashId,
                'templateId' => $templateId,
                'template' => $template,
                'remarks' => $remarks,
                'status' => $status,
            ];
        }, $body['rfiTemplates']);

        if (collect($rfis)->pluck('rfiHashId')->unique()->count() !== count($rfis)) {
            throw new RuntimeException('Nium Corporate RFI response contains duplicate authoritative identities.');
        }

        return $rfis;
    }

    private function identifier(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' && strlen($value) <= 255 ? $value : null;
    }

    private function template(mixed $value): ?array
    {
        if (! is_array($value) || array_is_list($value)) {
            return null;
        }

        $name = $this->identifier($value['name'] ?? null);
        $documentType = $this->identifier($value['documentType'] ?? null);
        $type = $this->identifier($value['type'] ?? null);
        $rfiType = $this->identifier($value['rfiType'] ?? null);
        if ($name === null || $documentType === null || $type === null || strtolower((string) $rfiType) !== 'corporate') {
            return null;
        }

        $requiredFields = $value['requiredFields'] ?? null;
        if (! is_array($requiredFields) || ! array_is_list($requiredFields) || $requiredFields === []) {
            return null;
        }

        $parsedFields = array_map(function (mixed $item): ?array {
            if (! is_array($item) || array_is_list($item)) {
                return null;
            }
            $label = $this->text($item['fieldLabel'] ?? null, 1000);
            $fieldValue = $this->identifier($item['fieldValue'] ?? null);
            $fieldType = $this->identifier($item['type'] ?? null);
            if ($label === null || $fieldValue === null || $fieldType === null) {
                return null;
            }

            return ['fieldLabel' => $label, 'fieldValue' => $fieldValue, 'type' => $fieldType];
        }, $requiredFields);
        if (in_array(null, $parsedFields, true)) {
            return null;
        }

        return [
            'name' => $name,
            'documentType' => $documentType,
            'type' => $type,
            'rfiType' => $rfiType,
            'requiredFields' => $parsedFields,
        ];
    }

    private function text(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' && strlen($value) <= $maxLength ? $value : null;
    }
}
