<?php

namespace App\Services\Nium;

use App\Models\NiumRfiCase;
use App\Models\UserProviderAccount;
use RuntimeException;

final class NiumCorporateRfiPostEnvelopeAdapter
{
    public function __construct(
        private readonly NiumCorporateRfiPayloadBuilder $payloadBuilder,
        private readonly NiumRegionResolver $regionResolver,
        private readonly NiumService $niumService,
    ) {}

    /**
     * Produce a non-executable candidate envelope for contract review only.
     * Unknown Corporate POST details remain explicit blockers rather than inferred defaults.
     */
    public function prepare(NiumRfiCase $case): array
    {
        $prepared = $this->payloadBuilder->prepare($case);
        $account = UserProviderAccount::query()->with('user.kycProfile')->whereKey($case->user_provider_account_id)
            ->where('provider_id', $case->provider_id)->firstOrFail();
        $profile = $account->user?->kycProfile;
        if ($profile === null) {
            throw new RuntimeException('Corporate RFI envelope preparation requires a verified customer profile.');
        }

        $profileMetadata = (array) $profile->metadata;
        $region = $this->regionResolver->resolve(
            $profileMetadata['nium_region'] ?? null,
            $profile->registered_country_code,
            $profile->residence_country_code,
            $profile->country_code,
        );
        $endpoint = (string) config('services.nium.customer_rfi_response_endpoint');
        if ($endpoint !== '/api/v1/client/{clientHashId}/corporate/rfi') {
            throw new RuntimeException('Corporate RFI envelope preparation requires the reviewed endpoint template.');
        }

        $blockers = [
            'Nium must confirm whether the Corporate POST body requires caseId, clientId, or both.',
            'Nium must confirm whether customerHashId and region are top-level Corporate POST body fields.',
            'Nium must confirm that templateId is submitted as rfiTemplateId.',
            'Nium must confirm whether rfiType and templateName are transmitted or are GET-only template metadata.',
            'Nium must confirm the exact nesting of fieldValue, documentType, and document arrays.',
        ];
        if ($prepared['template']['documentType'] !== 'document') {
            throw new RuntimeException('Corporate RFI POST adapter does not support this authoritative documentType.');
        }
        $responseFields = [];
        foreach ($prepared['fields'] as $field) {
            if (($field['type'] ?? null) !== 'document') {
                throw new RuntimeException('Corporate RFI POST adapter currently supports only verified document field preparation.');
            }
            $documentType = $this->documentType($prepared['template']['name'], $blockers);
            $responseFields[] = [
                'fieldValue' => $field['fieldValue'],
                'type' => 'document',
                'documentType' => $documentType,
                'documents' => array_map(static fn (array $document): array => [
                    'fileName' => $document['fileName'],
                    'fileType' => $document['fileType'],
                    'document' => $document['document'],
                ], $field['documents']),
            ];
        }

        return [
            'executable' => false,
            'transport' => [
                'method' => 'POST',
                'endpointTemplate' => $endpoint,
                'pathParameters' => ['clientHashId' => $this->niumService->clientId()],
            ],
            'body' => [
                'customerHashId' => $prepared['customerHashId'],
                'region' => $region,
                'rfiResponseRequest' => [[
                    'rfiHashId' => $prepared['rfiHashId'],
                    'rfiTemplateId' => $prepared['templateId'],
                    'rfiType' => $prepared['template']['rfiType'],
                    'templateName' => $prepared['template']['name'],
                    'responseFields' => $responseFields,
                ]],
            ],
            'evidence' => [
                'clientHashId' => 'services.nium.client_id used only for the configured URL placeholder',
                'customerHashId' => 'user_provider_accounts.external_customer_id',
                'region' => 'NiumRegionResolver using KYC profile facts and configured regulatory region',
                'rfiHashId' => 'authoritative Corporate RFI GET evidence and matching case fingerprint',
                'templateId' => 'authoritative Corporate RFI GET evidence',
                'template' => 'authoritative evidence.template and requiredFields',
                'documents' => 'active approved nium_rfi_case_documents relationships validated by NiumCorporateRfiPayloadBuilder',
            ],
            'contractBlockers' => array_values(array_unique($blockers)),
        ];
    }

    private function documentType(string $templateName, array &$blockers): ?string
    {
        if ($templateName === 'businessRegistrationDocument') {
            $blockers[] = 'Nium must confirm BUSINESS_REGISTRATION_DOC as the Corporate RFI documentType value.';

            return 'BUSINESS_REGISTRATION_DOC';
        }
        if ($templateName === 'otherDocument') {
            $blockers[] = 'Nium must provide the case-specific documentType for each otherDocument RFI.';

            return null;
        }

        throw new RuntimeException('Corporate RFI POST adapter does not support this authoritative template name.');
    }
}
