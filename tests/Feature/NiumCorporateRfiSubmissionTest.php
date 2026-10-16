<?php

namespace Tests\Feature;

use App\Models\IntegrationProvider;
use App\Models\KycDocument;
use App\Models\NiumRfiCase;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumCorporateRfiSubmissionService;
use App\Services\Nium\NiumRegionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class NiumCorporateRfiSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.nium.base_url', 'https://gateway.test.invalid');
        config()->set('services.nium.client_id', 'path-client-hash');
        config()->set('services.nium.auth', ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'test-key']);
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'webhook-key');
        config()->set('services.nium.customer_rfi_request_schema_version', 'corporate-rfi-request-test-v1');
        config()->set('services.nium.customer_rfi_response_contract_version', 'corporate-rfi-response-test-v1');
        Http::preventStrayRequests();
    }

    public function test_requires_approval_and_customer_scope(): void
    {
        [$case] = $this->corporateCase();
        $case->update(['submission_state' => 'draft', 'approved_at' => null]);
        $this->expectFailure($case, 'separate human approval');

        [$transactionCase] = $this->corporateCase();
        $transactionCase->update(['scope' => 'transaction']);
        $this->expectFailure($transactionCase->fresh(), 'customer-scoped');
        Http::assertNothingSent();
    }

    public function test_requires_every_authoritative_identifier(): void
    {
        foreach (['caseId', 'rfiHashId', 'rfiTemplateId', 'clientId'] as $field) {
            [$case] = $this->corporateCase();
            $evidence = $case->evidence;
            unset($evidence[$field]);
            $case->update(['evidence' => $evidence]);
            if ($field === 'clientId') {
                UserProviderAccount::query()->whereKey($case->user_provider_account_id)
                    ->update(['metadata' => ['nium_region' => 'SG']]);
            }
            $this->expectFailure($case->fresh(), $field);
        }

        foreach (['customerHashId', 'region'] as $field) {
            [$case, $account] = $this->corporateCase();
            if ($field === 'customerHashId') {
                $account->update(['external_customer_id' => null]);
            } else {
                $evidence = $case->evidence;
                unset($evidence['region']);
                $case->update(['evidence' => $evidence]);
                $account->update(['metadata' => ['nium_client_id' => 'body-client-id']]);
            }
            $this->expectFailure($case, $field);
        }
        Http::assertNothingSent();
    }

    public function test_posts_exact_official_contract_with_request_id_and_only_requested_answers(): void
    {
        [$case] = $this->corporateCase();
        Http::fake(['*' => Http::response(['status' => 'RFI_RESPONDED', 'caseId' => 'provider-case'])]);

        $result = app(NiumCorporateRfiSubmissionService::class)->submit($case);

        $this->assertSame('responded', $result->submission_state);
        $this->assertSame('RFI_RESPONDED', data_get($result->provider_response_evidence, 'status'));
        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://gateway.test.invalid/api/v1/client/path-client-hash/corporate/rfi'
                && filled($request->header('x-request-id')[0] ?? null)
                && $request->data() === [
                    'caseId' => 'case-123',
                    'clientId' => 'body-client-id',
                    'customerHashId' => 'customer-hash',
                    'region' => 'SG',
                    'rfiResponseRequest' => [[
                        'rfiHashId' => 'rfi-hash',
                        'rfiTemplateId' => 'template-id',
                        'businessDescription' => 'Cross-border software services',
                    ]],
                ];
        });
    }

    public function test_rejects_unrequested_and_raw_injected_fields_before_post(): void
    {
        [$case] = $this->corporateCase();
        $case->update(['response_draft' => [$this->answer('unrequested', 'value')]]);
        $this->expectFailure($case->fresh(), 'unrequested response field');

        [$rawCase] = $this->corporateCase();
        $rawCase->update(['response_draft' => [$this->answer('businessDescription', ['document' => 'raw'])]]);
        $this->expectFailure($rawCase->fresh(), 'raw document response data');
        Http::assertNothingSent();
    }

    public function test_encodes_only_approved_private_factual_documents_at_final_request_construction(): void
    {
        Storage::fake('kyc_private');
        [$case, $account, $user] = $this->corporateCase();
        $contents = "%PDF-1.4\ncorporate-rfi\n%%EOF";
        $path = "rfi/{$user->id}/evidence.pdf";
        Storage::disk('kyc_private')->put($path, $contents);
        $profile = $user->kycProfile()->firstOrCreate([], [
            'status' => 'approved',
            'applicant_type' => 'business',
            'legal_name' => 'Corporate RFI Test',
            'address_line1' => '1 Test Road',
            'city' => 'Singapore',
            'country_code' => 'SG',
        ]);
        $document = KycDocument::query()->create([
            'kyc_profile_id' => $profile->id,
            'type' => 'business_registration_doc',
            'status' => 'approved',
            'file_url' => 'private://'.$path,
            'storage_disk' => 'kyc_private',
            'file_path' => $path,
            'original_name' => 'evidence.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => strlen($contents),
            'file_hash' => hash('sha256', $contents),
            'metadata' => ['factual' => true, 'synthetic' => false],
        ]);
        $case->update([
            'evidence' => [...$case->evidence, 'requiredData' => [
                ['value' => 'businessDescription', 'type' => 'TEXT'],
                ['value' => 'supportingDocument', 'type' => 'DOCUMENT'],
            ]],
            'supporting_file_ids' => [['document_id' => $document->id]],
        ]);
        Http::fake(['*' => Http::response(['status' => 'RFI_RESPONDED'])]);

        app(NiumCorporateRfiSubmissionService::class)->submit($case->fresh());

        $this->assertStringNotContainsString(base64_encode($contents), json_encode($case->fresh()->supporting_file_ids));
        Http::assertSent(fn ($request): bool => data_get(
            $request->data(),
            'rfiResponseRequest.0.supportingDocument.0.document',
        ) === base64_encode($contents));
        $this->assertSame($account->id, $case->user_provider_account_id);
    }

    public function test_prepares_each_authoritative_corporate_document_template_offline_from_its_own_active_links(): void
    {
        Storage::fake('kyc_private');
        $templates = [
            ['businessRegistrationDocument', 'Business registration document'],
            ['otherDocument', 'Personal Source of Wealth'],
            ['otherDocument', 'Personal Source of Funds'],
            ['otherDocument', 'Corporate Source of Wealth and Funds'],
        ];

        foreach ($templates as $index => [$templateName, $label]) {
            [$case, $account, $user] = $this->authoritativeCorporateCase(
                "rfi-hash-{$index}",
                "template-{$index}",
                $templateName,
                $label,
            );
            $document = $this->approvedDocument($user, "evidence-{$index}.pdf", "document-{$index}");
            $case->documents()->attach($document->id, ['attached_by' => $user->id]);

            $prepared = app(NiumCorporateRfiSubmissionService::class)->preparePayload($case->fresh());

            $this->assertSame($account->external_customer_id, $prepared['customerHashId']);
            $this->assertSame("rfi-hash-{$index}", $prepared['rfiHashId']);
            $this->assertSame("template-{$index}", $prepared['templateId']);
            $this->assertSame($templateName, data_get($prepared, 'template.name'));
            $this->assertSame('document', data_get($prepared, 'template.documentType'));
            $this->assertSame('corporate', data_get($prepared, 'template.rfiType'));
            $this->assertSame('document', data_get($prepared, 'fields.0.fieldValue'));
            $this->assertSame([$document->id], collect(data_get($prepared, 'fields.0.documents'))->pluck('documentId')->all());
        }

        Http::assertNothingSent();
    }

    public function test_authoritative_offline_preparation_rejects_invalid_case_document_evidence(): void
    {
        Storage::fake('kyc_private');

        [$missing] = $this->authoritativeCorporateCase('missing-doc', 'template-missing', 'otherDocument', 'Missing document');
        $this->expectPreparationFailure($missing, 'active approved supporting document');

        [$detached, , $detachedUser] = $this->authoritativeCorporateCase('detached-doc', 'template-detached', 'otherDocument', 'Detached document');
        $detachedDocument = $this->approvedDocument($detachedUser, 'detached.pdf', 'detached');
        $detached->documents()->attach($detachedDocument->id, ['attached_by' => $detachedUser->id, 'detached_at' => now()]);
        $this->expectPreparationFailure($detached->fresh(), 'active approved supporting document');

        [$unapproved, , $unapprovedUser] = $this->authoritativeCorporateCase('unapproved-doc', 'template-unapproved', 'otherDocument', 'Unapproved document');
        $unapprovedDocument = $this->approvedDocument($unapprovedUser, 'unapproved.pdf', 'unapproved');
        $unapprovedDocument->update(['status' => 'pending_admin_review']);
        $unapproved->documents()->attach($unapprovedDocument->id, ['attached_by' => $unapprovedUser->id]);
        $this->expectPreparationFailure($unapproved->fresh(), 'ownership, provenance, status, or storage');

        [$superseded, , $supersededUser] = $this->authoritativeCorporateCase('superseded-doc', 'template-superseded', 'otherDocument', 'Superseded document');
        $supersededDocument = $this->approvedDocument($supersededUser, 'superseded.pdf', 'superseded');
        $supersededDocument->update(['status' => 'superseded']);
        $superseded->documents()->attach($supersededDocument->id, ['attached_by' => $supersededUser->id]);
        $this->expectPreparationFailure($superseded->fresh(), 'ownership, provenance, status, or storage');

        [$synthetic, , $syntheticUser] = $this->authoritativeCorporateCase('synthetic-doc', 'template-synthetic', 'otherDocument', 'Synthetic document');
        $syntheticDocument = $this->approvedDocument($syntheticUser, 'synthetic.pdf', 'synthetic');
        $syntheticDocument->update(['metadata' => ['factual' => true, 'synthetic' => true]]);
        $synthetic->documents()->attach($syntheticDocument->id, ['attached_by' => $syntheticUser->id]);
        $this->expectPreparationFailure($synthetic->fresh(), 'ownership, provenance, status, or storage');

        [$crossCustomer] = $this->authoritativeCorporateCase('cross-customer-doc', 'template-cross', 'otherDocument', 'Cross-customer document');
        [, , $otherUser] = $this->corporateCase();
        $crossDocument = $this->approvedDocument($otherUser, 'cross-customer.pdf', 'cross-customer');
        $crossCustomer->documents()->attach($crossDocument->id, ['attached_by' => $otherUser->id]);
        $this->expectPreparationFailure($crossCustomer->fresh(), 'ownership, provenance, status, or storage');

        [$badHash, , $badHashUser] = $this->authoritativeCorporateCase('bad-file-hash', 'template-file-hash', 'otherDocument', 'Bad file hash');
        $badHashDocument = $this->approvedDocument($badHashUser, 'bad-hash.pdf', 'bad-file-hash');
        $badHashDocument->update(['file_hash' => str_repeat('0', 64)]);
        $badHash->documents()->attach($badHashDocument->id, ['attached_by' => $badHashUser->id]);
        $this->expectPreparationFailure($badHash->fresh(), 'hash does not match');

        [$unsafePath, , $unsafePathUser] = $this->authoritativeCorporateCase('unsafe-path', 'template-unsafe', 'otherDocument', 'Unsafe path');
        $unsafePathDocument = $this->approvedDocument($unsafePathUser, 'unsafe-path.pdf', 'unsafe-path');
        $unsafePathDocument->update(['file_path' => '../unsafe-path.pdf']);
        $unsafePath->documents()->attach($unsafePathDocument->id, ['attached_by' => $unsafePathUser->id]);
        $this->expectPreparationFailure($unsafePath->fresh(), 'ownership, provenance, status, or storage');

        [$mismatchedRfi, , $mismatchedUser] = $this->authoritativeCorporateCase('mismatched-rfi', 'template-rfi-hash', 'otherDocument', 'Mismatched RFI');
        $mismatchedDocument = $this->approvedDocument($mismatchedUser, 'mismatched-rfi.pdf', 'mismatched-rfi');
        $mismatchedRfi->documents()->attach($mismatchedDocument->id, ['attached_by' => $mismatchedUser->id]);
        $mismatchedRfi->update(['provider_reference_fingerprint' => hash('sha256', 'different-rfi')]);
        $this->expectPreparationFailure($mismatchedRfi->fresh(), 'provider reference fingerprint');

        [$duplicates, , $duplicateUser] = $this->authoritativeCorporateCase('duplicate-docs', 'template-duplicates', 'otherDocument', 'Duplicate documents');
        $firstDuplicate = $this->approvedDocument($duplicateUser, 'duplicate-one.pdf', 'same-content');
        $secondDuplicate = $this->approvedDocument($duplicateUser, 'duplicate-two.pdf', 'same-content');
        $duplicates->documents()->attach([$firstDuplicate->id, $secondDuplicate->id], ['attached_by' => $duplicateUser->id]);
        $this->expectPreparationFailure($duplicates->fresh(), 'duplicate supporting evidence');

        Http::assertNothingSent();
    }

    public function test_prepares_non_executable_hk_post_envelopes_for_each_authoritative_template(): void
    {
        Storage::fake('kyc_private');
        Http::fake();
        config()->set('services.nium.regulatory_region', 'HK');
        $templates = [
            ['businessRegistrationDocument', 'Business registration document', true],
            ['otherDocument', 'Personal Source of Wealth', false],
            ['otherDocument', 'Personal Source of Funds', false],
            ['otherDocument', 'Corporate Source of Wealth and Funds', false],
        ];

        foreach ($templates as $index => [$templateName, $label, $hasDocumentTypeBlocker]) {
            [$case, $account, $user] = $this->authoritativeCorporateCase(
                "envelope-rfi-{$index}",
                "envelope-template-{$index}",
                $templateName,
                $label,
            );
            $user->kycProfile()->update([
                'registered_country_code' => 'HK',
                'country_code' => 'HK',
                'metadata' => ['nium_region' => 'HK'],
            ]);
            $document = $this->approvedDocument($user, "envelope-{$index}.pdf", "envelope-document-{$index}");
            $case->documents()->attach($document->id, ['attached_by' => $user->id]);

            $envelope = app(NiumCorporateRfiSubmissionService::class)->preparePostEnvelope($case->fresh());
            $unchanged = $case->fresh();

            $this->assertFalse($envelope['executable']);
            $this->assertSame('admin_approved', $unchanged->submission_state);
            $this->assertNull($unchanged->claimed_at);
            $this->assertNull($unchanged->provider_response_evidence);
            $this->assertSame('POST', data_get($envelope, 'transport.method'));
            $this->assertSame('/api/v1/client/{clientHashId}/corporate/rfi', data_get($envelope, 'transport.endpointTemplate'));
            $this->assertSame('path-client-hash', data_get($envelope, 'transport.pathParameters.clientHashId'));
            $this->assertSame([
                'region' => 'HK',
                'customerHashId' => $account->external_customer_id,
                'rfiResponseRequest' => [[
                    'rfiHashId' => "envelope-rfi-{$index}",
                    'businessDetails' => [
                        'documentDetails' => [
                            'document' => [[
                                'document' => base64_encode("%PDF-1.4\nenvelope-document-{$index}\n%%EOF"),
                                'fileName' => "envelope-{$index}.pdf",
                                'fileType' => 'application/pdf',
                            ]],
                        ],
                    ],
                ]],
            ], $envelope['candidateBody']);
            $this->assertSame("envelope-template-{$index}", data_get($envelope, 'reviewMetadata.templateId'));
            $this->assertSame($templateName, data_get($envelope, 'reviewMetadata.templateName'));
            $this->assertSame('corporate', data_get($envelope, 'reviewMetadata.rfiType'));
            $this->assertSame('document', data_get($envelope, 'reviewMetadata.requiredField.fieldValue'));
            $this->assertSame(
                $templateName === 'businessRegistrationDocument' ? 'BUSINESS_REGISTRATION_DOC' : null,
                data_get($envelope, 'reviewMetadata.optionalDocumentTypeCandidate'),
            );
            $this->assertSame($hasDocumentTypeBlocker, $envelope['contractBlockers'] !== []);
            $this->assertArrayNotHasKey('caseId', $envelope['candidateBody']);
            $this->assertArrayNotHasKey('clientId', $envelope['candidateBody']);
        }

        Http::assertNothingSent();
    }

    public function test_authoritative_submission_dry_run_builds_transport_plan_without_sending_or_claiming(): void
    {
        Storage::fake('kyc_private');
        Http::fake();
        config()->set('services.nium.regulatory_region', 'HK');

        [$case, $account, $user] = $this->authoritativeCorporateCase(
            'dry-run-rfi-hash',
            'dry-run-template-id',
            'otherDocument',
            'Corporate Source of Wealth and Funds',
        );
        $this->makeHkProfile($user);
        $document = $this->approvedDocument($user, 'dry-run.pdf', 'dry-run-document');
        $case->documents()->attach($document->id, ['attached_by' => $user->id]);

        $first = app(NiumCorporateRfiSubmissionService::class)->prepareAuthoritativeDryRun($case->fresh());
        $second = app(NiumCorporateRfiSubmissionService::class)->prepareAuthoritativeDryRun($case->fresh());
        $unchanged = $case->fresh();

        $this->assertFalse($first['executable']);
        $this->assertSame('POST', data_get($first, 'request.method'));
        $this->assertSame('/api/v1/client/path-client-hash/corporate/rfi', data_get($first, 'request.endpoint'));
        $this->assertSame('application/json', data_get($first, 'request.headers.Accept'));
        $this->assertSame('application/json', data_get($first, 'request.headers.Content-Type'));
        $this->assertTrue(Str::isUuid((string) data_get($first, 'request.headers.x-request-id')));
        $this->assertNotSame(
            data_get($first, 'request.headers.x-request-id'),
            data_get($second, 'request.headers.x-request-id'),
        );
        $this->assertSame(['x-api-key'], data_get($first, 'request.credentialHeaderNames'));
        $this->assertSame(['region', 'customerHashId', 'rfiResponseRequest'], array_keys(data_get($first, 'request.body')));
        $this->assertSame('HK', data_get($first, 'request.body.region'));
        $this->assertSame($account->external_customer_id, data_get($first, 'request.body.customerHashId'));
        $this->assertSame('dry-run-rfi-hash', data_get($first, 'request.body.rfiResponseRequest.0.rfiHashId'));
        $this->assertSame(
            base64_encode("%PDF-1.4\ndry-run-document\n%%EOF"),
            data_get($first, 'request.body.rfiResponseRequest.0.businessDetails.documentDetails.document.0.document'),
        );
        $this->assertArrayNotHasKey('caseId', data_get($first, 'request.body'));
        $this->assertArrayNotHasKey('clientId', data_get($first, 'request.body'));
        $this->assertArrayNotHasKey('rfiTemplateId', data_get($first, 'request.body.rfiResponseRequest.0'));
        $this->assertSame('admin_approved', $unchanged->submission_state);
        $this->assertNull($unchanged->claimed_at);
        $this->assertNull($unchanged->provider_response_evidence);
        Http::assertNothingSent();
    }

    public function test_authoritative_submit_remains_blocked_when_contract_configuration_or_gate_changes(): void
    {
        Storage::fake('kyc_private');
        Http::fake();
        config()->set('services.nium.regulatory_region', 'HK');

        [$unconfirmed] = $this->authoritativeCorporateCase('blocked-unconfirmed', 'template-unconfirmed', 'otherDocument', 'Unconfirmed contract');
        $this->expectFailure($unconfirmed, 'exact Corporate RFI response contract is not configured');

        [$configurationOnly] = $this->authoritativeCorporateCase('blocked-configuration', 'template-configuration', 'otherDocument', 'Configuration only');
        config()->set('services.nium.customer_rfi_response_contract_version', 'configuration-only-change');
        $this->expectFailure($configurationOnly, 'exact Corporate RFI response contract is not configured');
        config()->set('services.nium.customer_rfi_response_contract_version', 'corporate-rfi-response-test-v1');

        [$changedGate, , $changedGateUser] = $this->authoritativeCorporateCase('blocked-gate', 'template-gate', 'otherDocument', 'Changed gate');
        $this->makeHkProfile($changedGateUser);
        $changedGateDocument = $this->approvedDocument($changedGateUser, 'blocked-gate.pdf', 'blocked-gate');
        $changedGate->documents()->attach($changedGateDocument->id, ['attached_by' => $changedGateUser->id]);
        $changedGate->update(['contract_gate' => 'corporate-rfi-response-test-v1']);
        $this->expectFailure($changedGate->fresh(), 'authoritative Corporate RFI POST envelope remains unverified');

        $this->assertSame('admin_approved', $changedGate->fresh()->submission_state);
        $this->assertNull($changedGate->fresh()->claimed_at);
        $this->assertNull($changedGate->fresh()->provider_response_evidence);
        Http::assertNothingSent();
    }

    public function test_post_envelope_fails_closed_for_missing_identifiers_region_mismatch_and_unsupported_templates(): void
    {
        Storage::fake('kyc_private');
        Http::fake();
        config()->set('services.nium.regulatory_region', 'HK');

        [$missingCustomer, $missingCustomerAccount, $missingCustomerUser] = $this->authoritativeCorporateCase('missing-customer', 'template-customer', 'otherDocument', 'Missing customer');
        $this->makeHkProfile($missingCustomerUser);
        $missingCustomerDocument = $this->approvedDocument($missingCustomerUser, 'missing-customer.pdf', 'missing-customer');
        $missingCustomer->documents()->attach($missingCustomerDocument->id, ['attached_by' => $missingCustomerUser->id]);
        $missingCustomerAccount->update(['external_customer_id' => null]);
        $this->expectEnvelopeFailure($missingCustomer->fresh(), 'customerHashId');

        [$missingTemplate, , $missingTemplateUser] = $this->authoritativeCorporateCase('missing-template', 'template-missing-id', 'otherDocument', 'Missing template');
        $this->makeHkProfile($missingTemplateUser);
        $missingTemplateDocument = $this->approvedDocument($missingTemplateUser, 'missing-template.pdf', 'missing-template');
        $missingTemplate->documents()->attach($missingTemplateDocument->id, ['attached_by' => $missingTemplateUser->id]);
        $evidence = $missingTemplate->evidence;
        unset($evidence['templateId']);
        $missingTemplate->update(['evidence' => $evidence]);
        $this->expectEnvelopeFailure($missingTemplate->fresh(), 'templateId');

        [$missingClient, , $missingClientUser] = $this->authoritativeCorporateCase('missing-client', 'template-client', 'otherDocument', 'Missing client');
        $this->makeHkProfile($missingClientUser);
        $missingClientDocument = $this->approvedDocument($missingClientUser, 'missing-client.pdf', 'missing-client');
        $missingClient->documents()->attach($missingClientDocument->id, ['attached_by' => $missingClientUser->id]);
        config()->set('services.nium.client_id', '');
        $this->expectEnvelopeFailure($missingClient->fresh(), 'client id is not configured');
        config()->set('services.nium.client_id', 'path-client-hash');

        [$unsupportedRegion, , $unsupportedRegionUser] = $this->authoritativeCorporateCase('unsupported-region', 'template-unsupported-region', 'otherDocument', 'Unsupported region');
        $unsupportedRegionUser->kycProfile()->update([
            'registered_country_code' => null,
            'residence_country_code' => null,
            'country_code' => 'ZZ',
            'metadata' => null,
        ]);
        $unsupportedRegionDocument = $this->approvedDocument($unsupportedRegionUser, 'unsupported-region.pdf', 'unsupported-region');
        $unsupportedRegion->documents()->attach($unsupportedRegionDocument->id, ['attached_by' => $unsupportedRegionUser->id]);
        config()->set('services.nium.regulatory_region', null);
        $this->expectEnvelopeFailure($unsupportedRegion->fresh(), NiumRegionResolver::INVALID_REGION);
        config()->set('services.nium.regulatory_region', 'HK');

        [$regionMismatch, , $regionMismatchUser] = $this->authoritativeCorporateCase('region-mismatch', 'template-region', 'otherDocument', 'Region mismatch');
        $regionMismatchUser->kycProfile()->update(['registered_country_code' => 'SG', 'country_code' => 'SG', 'metadata' => ['nium_region' => 'SG']]);
        $regionDocument = $this->approvedDocument($regionMismatchUser, 'region.pdf', 'region');
        $regionMismatch->documents()->attach($regionDocument->id, ['attached_by' => $regionMismatchUser->id]);
        $this->expectEnvelopeFailure($regionMismatch->fresh(), NiumRegionResolver::REGION_MISMATCH);

        [$unsupported, , $unsupportedUser] = $this->authoritativeCorporateCase('unsupported-template', 'template-unsupported', 'taxDocument', 'Unsupported template');
        $this->makeHkProfile($unsupportedUser);
        $unsupportedDocument = $this->approvedDocument($unsupportedUser, 'unsupported.pdf', 'unsupported');
        $unsupported->documents()->attach($unsupportedDocument->id, ['attached_by' => $unsupportedUser->id]);
        $this->expectEnvelopeFailure($unsupported->fresh(), 'does not support this authoritative template name');

        [$unsupportedType, , $unsupportedTypeUser] = $this->authoritativeCorporateCase('unsupported-type', 'template-type', 'otherDocument', 'Unsupported document type');
        $this->makeHkProfile($unsupportedTypeUser);
        $unsupportedTypeDocument = $this->approvedDocument($unsupportedTypeUser, 'unsupported-type.pdf', 'unsupported-type');
        $unsupportedType->documents()->attach($unsupportedTypeDocument->id, ['attached_by' => $unsupportedTypeUser->id]);
        $unsupportedTypeEvidence = $unsupportedType->evidence;
        $unsupportedTypeEvidence['template']['documentType'] = 'tax_document';
        $unsupportedType->update(['evidence' => $unsupportedTypeEvidence]);
        $this->expectEnvelopeFailure($unsupportedType->fresh(), 'does not support this authoritative documentType');

        [$unsupportedField, , $unsupportedFieldUser] = $this->authoritativeCorporateCase('unsupported-field', 'template-field', 'otherDocument', 'Unsupported required field');
        $this->makeHkProfile($unsupportedFieldUser);
        $unsupportedFieldDocument = $this->approvedDocument($unsupportedFieldUser, 'unsupported-field.pdf', 'unsupported-field');
        $unsupportedField->documents()->attach($unsupportedFieldDocument->id, ['attached_by' => $unsupportedFieldUser->id]);
        $unsupportedFieldEvidence = $unsupportedField->evidence;
        $unsupportedFieldEvidence['template']['requiredFields'][0]['fieldValue'] = 'supportingDocument';
        $unsupportedField->update(['evidence' => $unsupportedFieldEvidence]);
        $this->expectEnvelopeFailure($unsupportedField->fresh(), 'documented corporate document required field');

        [$badEndpoint, , $badEndpointUser] = $this->authoritativeCorporateCase('bad-endpoint', 'template-endpoint', 'otherDocument', 'Bad endpoint');
        $this->makeHkProfile($badEndpointUser);
        $badEndpointDocument = $this->approvedDocument($badEndpointUser, 'bad-endpoint.pdf', 'bad-endpoint');
        $badEndpoint->documents()->attach($badEndpointDocument->id, ['attached_by' => $badEndpointUser->id]);
        config()->set('services.nium.customer_rfi_response_endpoint', '/unreviewed/corporate/rfi');
        $this->expectEnvelopeFailure($badEndpoint->fresh(), 'reviewed endpoint template');
        config()->set('services.nium.customer_rfi_response_endpoint', '/api/v1/client/{clientHashId}/corporate/rfi');

        Http::assertNothingSent();
    }

    public function test_post_envelope_keeps_case_documents_isolated_and_reuses_all_builder_security_checks(): void
    {
        Storage::fake('kyc_private');
        Http::fake();
        config()->set('services.nium.regulatory_region', 'HK');
        [$first, , $firstUser] = $this->authoritativeCorporateCase('isolated-first', 'template-first', 'otherDocument', 'Personal Source of Wealth');
        [$second, , $secondUser] = $this->authoritativeCorporateCase('isolated-second', 'template-second', 'otherDocument', 'Personal Source of Funds');
        $this->makeHkProfile($firstUser);
        $this->makeHkProfile($secondUser);
        $firstDocument = $this->approvedDocument($firstUser, 'first.pdf', 'first-private-document');
        $firstAdditionalDocument = $this->approvedDocument($firstUser, 'first-additional.pdf', 'first-additional-private-document');
        $secondDocument = $this->approvedDocument($secondUser, 'second.pdf', 'second-private-document');
        $first->documents()->attach([$firstDocument->id, $firstAdditionalDocument->id], ['attached_by' => $firstUser->id]);
        $second->documents()->attach($secondDocument->id, ['attached_by' => $secondUser->id]);

        $firstEnvelope = app(NiumCorporateRfiSubmissionService::class)->preparePostEnvelope($first->fresh());
        $secondEnvelope = app(NiumCorporateRfiSubmissionService::class)->preparePostEnvelope($second->fresh());
        $firstDocuments = data_get($firstEnvelope, 'candidateBody.rfiResponseRequest.0.businessDetails.documentDetails.document');
        $firstEncoded = data_get($firstDocuments, '0.document');
        $secondEncoded = data_get($secondEnvelope, 'candidateBody.rfiResponseRequest.0.businessDetails.documentDetails.document.0.document');

        $this->assertCount(2, $firstDocuments);
        $this->assertNotSame($firstEncoded, $secondEncoded);
        $this->assertNotContains($secondEncoded, collect($firstDocuments)->pluck('document')->all());
        $this->assertSame('isolated-first', data_get($firstEnvelope, 'candidateBody.rfiResponseRequest.0.rfiHashId'));
        $this->assertSame('isolated-second', data_get($secondEnvelope, 'candidateBody.rfiResponseRequest.0.rfiHashId'));

        $firstDocument->update(['file_path' => '../unsafe.pdf']);
        $this->expectEnvelopeFailure($first->fresh(), 'ownership, provenance, status, or storage');
        Http::assertNothingSent();
    }

    public function test_deterministic_and_uncertain_provider_outcomes_are_persisted(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['message' => 'rejected'], 400)
            ->push(['message' => 'rejected'], 422)
            ->push(['message' => 'uncertain'], 408)
            ->push(['message' => 'uncertain'], 429)
            ->push(['message' => 'uncertain'], 500)
            ->push(['message' => 'uncertain'], 503)]);
        foreach ([400, 422] as $status) {
            [$case] = $this->corporateCase();
            $this->assertSame('rejected', app(NiumCorporateRfiSubmissionService::class)->submit($case)->submission_state);
        }
        foreach ([408, 429, 500, 503] as $status) {
            [$case] = $this->corporateCase();
            $result = app(NiumCorporateRfiSubmissionService::class)->submit($case);
            $this->assertSame('unknown', $result->submission_state);
            $this->assertTrue((bool) data_get($result->provider_response_evidence, 'manual_reconciliation_required'));
        }
    }

    public function test_transport_malformed_and_unexpected_success_are_unknown(): void
    {
        [$transport] = $this->corporateCase();
        Http::fake(['*' => Http::failedConnection('timeout')]);
        $this->assertSame('unknown', app(NiumCorporateRfiSubmissionService::class)->submit($transport)->submission_state);

        [$malformed] = $this->corporateCase();
        Http::fake(['*' => Http::response('not-json', 200, ['Content-Type' => 'text/plain'])]);
        $this->assertSame('unknown', app(NiumCorporateRfiSubmissionService::class)->submit($malformed)->submission_state);

        foreach ([[201, 'RFI_RESPONDED'], [200, 'PENDING']] as [$httpStatus, $providerStatus]) {
            [$case] = $this->corporateCase();
            Http::fake(['*' => Http::response(['status' => $providerStatus], $httpStatus)]);
            $this->assertSame('unknown', app(NiumCorporateRfiSubmissionService::class)->submit($case)->submission_state);
        }
    }

    private function corporateCase(): array
    {
        $provider = IntegrationProvider::query()->firstOrCreate(
            ['code' => 'nium'],
            ['name' => 'Nium', 'status' => 'active'],
        );
        $user = User::factory()->create();
        $account = UserProviderAccount::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'external_customer_id' => 'customer-hash',
            'status' => 'active',
            'metadata' => ['nium_region' => 'SG', 'nium_client_id' => 'body-client-id'],
        ]);
        $case = NiumRfiCase::query()->create([
            'provider_id' => $provider->id,
            'user_provider_account_id' => $account->id,
            'scope' => 'customer',
            'provider_reference_fingerprint' => hash('sha256', 'rfi-hash-'.str()->uuid()),
            'status' => 'requested',
            'evidence' => [
                'caseId' => 'case-123',
                'rfiHashId' => 'rfi-hash',
                'rfiTemplateId' => 'template-id',
                'clientId' => 'body-client-id',
                'region' => 'SG',
                'rfiStatus' => 'RFI_REQUESTED',
                'requiredData' => [['value' => 'businessDescription', 'type' => 'TEXT']],
            ],
            'response_draft' => [$this->answer('businessDescription', 'Cross-border software services')],
            'contract_gate' => 'corporate-rfi-response-test-v1',
            'submission_state' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        return [$case, $account, $user, $provider];
    }

    private function authoritativeCorporateCase(
        string $rfiHashId,
        string $templateId,
        string $templateName,
        string $fieldLabel,
    ): array {
        [$case, $account, $user, $provider] = $this->corporateCase();
        $user->kycProfile()->firstOrCreate([], [
            'status' => 'approved',
            'applicant_type' => 'business',
            'legal_name' => 'Corporate RFI Test',
            'address_line1' => '1 Test Road',
            'city' => 'Singapore',
            'country_code' => 'SG',
        ]);
        $case->update([
            'origin' => 'authoritative_fetch',
            'provider_reference_fingerprint' => hash('sha256', $rfiHashId),
            'evidence' => [
                'rfiHashId' => $rfiHashId,
                'templateId' => $templateId,
                'template' => [
                    'name' => $templateName,
                    'documentType' => 'document',
                    'type' => 'document',
                    'rfiType' => 'corporate',
                    'requiredFields' => [[
                        'fieldLabel' => $fieldLabel,
                        'fieldValue' => 'document',
                        'type' => 'document',
                    ]],
                ],
                'remarks' => "Provide {$fieldLabel} evidence.",
                'rfiStatus' => 'RFI_REQUESTED',
            ],
            'response_draft' => null,
            'supporting_file_ids' => null,
            'contract_gate' => 'provider_contract_unconfirmed',
            'submission_state' => 'admin_approved',
        ]);

        return [$case->fresh(), $account, $user, $provider];
    }

    private function approvedDocument(User $user, string $fileName, string $marker): KycDocument
    {
        $contents = "%PDF-1.4\n{$marker}\n%%EOF";
        $path = "rfi/{$user->id}/".str()->uuid().'.pdf';
        Storage::disk('kyc_private')->put($path, $contents);
        $profile = $user->kycProfile()->firstOrCreate([], [
            'status' => 'approved',
            'applicant_type' => 'business',
            'legal_name' => 'Corporate RFI Test',
            'address_line1' => '1 Test Road',
            'city' => 'Singapore',
            'country_code' => 'SG',
        ]);

        return KycDocument::query()->create([
            'kyc_profile_id' => $profile->id,
            'type' => 'corporate_rfi_supporting_document',
            'status' => 'approved',
            'file_url' => 'private://'.$path,
            'storage_disk' => 'kyc_private',
            'file_path' => $path,
            'original_name' => $fileName,
            'mime_type' => 'application/pdf',
            'file_size' => strlen($contents),
            'file_hash' => hash('sha256', $contents),
            'metadata' => ['factual' => true, 'synthetic' => false, 'rfi_upload' => true],
        ]);
    }

    private function makeHkProfile(User $user): void
    {
        $user->kycProfile()->update([
            'registered_country_code' => 'HK',
            'country_code' => 'HK',
            'metadata' => ['nium_region' => 'HK'],
        ]);
    }

    private function answer(string $field, mixed $value): array
    {
        return ['questionId' => $field, 'answer' => $value, 'provenance' => [
            'source' => 'human_supplied', 'reviewer_id' => 1, 'recorded_at' => now()->toISOString(),
        ]];
    }

    private function expectFailure(NiumRfiCase $case, string $message): void
    {
        try {
            app(NiumCorporateRfiSubmissionService::class)->submit($case);
            $this->fail('Expected Corporate RFI submission to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function expectPreparationFailure(NiumRfiCase $case, string $message): void
    {
        try {
            app(NiumCorporateRfiSubmissionService::class)->preparePayload($case);
            $this->fail('Expected offline Corporate RFI preparation to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function expectEnvelopeFailure(NiumRfiCase $case, string $message): void
    {
        try {
            app(NiumCorporateRfiSubmissionService::class)->preparePostEnvelope($case);
            $this->fail('Expected offline Corporate RFI POST envelope preparation to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
