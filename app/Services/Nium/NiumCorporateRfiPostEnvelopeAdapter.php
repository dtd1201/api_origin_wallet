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

    /** Produce a documentation-aligned, non-executable candidate for offline contract review. */
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

        if ($prepared['template']['documentType'] !== 'document') {
            throw new RuntimeException('Corporate RFI POST adapter does not support this authoritative documentType.');
        }
        if (count($prepared['fields']) !== 1
            || ($prepared['fields'][0]['type'] ?? null) !== 'document'
            || ($prepared['fields'][0]['fieldValue'] ?? null) !== 'document') {
            throw new RuntimeException('Corporate RFI POST adapter supports only the documented corporate document required field.');
        }
        $templateName = $prepared['template']['name'];
        if (! in_array($templateName, ['businessRegistrationDocument', 'otherDocument'], true)) {
            throw new RuntimeException('Corporate RFI POST adapter does not support this authoritative template name.');
        }
        $documents = array_map(static fn (array $document): array => [
            'document' => $document['document'],
            'fileName' => $document['fileName'],
            'fileType' => $document['fileType'],
        ], $prepared['fields'][0]['documents']);
        $blockers = $templateName === 'businessRegistrationDocument'
            ? ['Confirm whether the target Corporate RFI endpoint requires optional documentType=BUSINESS_REGISTRATION_DOC for this template.']
            : [];

        return [
            'executable' => false,
            'transport' => [
                'method' => 'POST',
                'endpointTemplate' => $endpoint,
                'pathParameters' => ['clientHashId' => $this->niumService->clientId()],
            ],
            'candidateBody' => [
                'region' => $region,
                'customerHashId' => $prepared['customerHashId'],
                'rfiResponseRequest' => [[
                    'rfiHashId' => $prepared['rfiHashId'],
                    'businessDetails' => [
                        'documentDetails' => [
                            'document' => $documents,
                        ],
                    ],
                ]],
            ],
            'reviewMetadata' => [
                'templateId' => $prepared['templateId'],
                'templateName' => $templateName,
                'rfiType' => $prepared['template']['rfiType'],
                'requiredField' => [
                    'fieldValue' => $prepared['fields'][0]['fieldValue'],
                    'type' => $prepared['fields'][0]['type'],
                ],
                'optionalDocumentTypeCandidate' => $templateName === 'businessRegistrationDocument'
                    ? 'BUSINESS_REGISTRATION_DOC'
                    : null,
                'documentationConfirmed' => [
                    'Corporate HK templates support businessRegistrationDocument and otherDocument.',
                    'Corporate documents are nested under businessDetails.documentDetails.document.',
                    'Document is an array of file objects containing document, fileName, and fileType.',
                    'rfiHashId identifies the specific requested RFI.',
                    'documentType is optional in the general Corporate RFI guidance.',
                ],
            ],
            'fieldEvidence' => [
                'clientHashId' => 'services.nium.client_id used only for the configured URL placeholder',
                'customerHashId' => 'user_provider_accounts.external_customer_id',
                'region' => 'NiumRegionResolver using KYC profile facts and configured regulatory region',
                'rfiHashId' => 'authoritative Corporate RFI GET evidence and matching case fingerprint',
                'documents' => 'active approved nium_rfi_case_documents relationships validated by NiumCorporateRfiPayloadBuilder',
            ],
            'contractBlockers' => array_values(array_unique($blockers)),
        ];
    }
}
