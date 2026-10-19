<?php

namespace Tests\Feature;

use App\Console\Commands\SubmitNiumAuthoritativeCorporateRfi;
use App\Models\IntegrationProvider;
use App\Models\ApiRequestLog;
use App\Models\KycDocument;
use App\Models\NiumApiExchangeEvidence;
use App\Models\NiumRfiCase;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumCorporateRfiSubmissionService;
use App\Services\Nium\NiumCorporateRfiAuthoritativeTransport;
use App\Services\Nium\NiumCorporateRfiAuthoritativeSubmissionService;
use App\Services\Nium\NiumEvidencePersistenceException;
use App\Services\Nium\NiumService;
use App\Services\Nium\NiumRfiWorkflowService;
use App\Services\Nium\NiumRegionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
        config()->set('services.nium.auth', ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'test-contract-key']);
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

    public function test_authoritative_offline_preparation_rejects_invalid_case_scope_before_document_loading(): void
    {
        [$case] = $this->authoritativeCorporateCase(
            'invalid-scope',
            'template-invalid-scope',
            'otherDocument',
            'Invalid scope fixture',
        );
        $case->update(['scope' => 'transaction']);

        $this->expectPreparationFailure($case->fresh(), 'authoritative customer-scoped case');
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

        [$unconfirmed, , $unconfirmedUser] = $this->authoritativeCorporateCase('blocked-unconfirmed', 'template-unconfirmed', 'otherDocument', 'Unconfirmed contract');
        $this->makeHkProfile($unconfirmedUser);
        $unconfirmedDocument = $this->approvedDocument($unconfirmedUser, 'blocked-unconfirmed.pdf', 'blocked-unconfirmed');
        $unconfirmed->documents()->attach($unconfirmedDocument->id, ['attached_by' => $unconfirmedUser->id]);
        $this->expectFailure($unconfirmed->fresh(), 'not verified for live submission');

        [$configurationOnly, , $configurationOnlyUser] = $this->authoritativeCorporateCase('blocked-configuration', 'template-configuration', 'otherDocument', 'Configuration only');
        $this->makeHkProfile($configurationOnlyUser);
        $configurationOnlyDocument = $this->approvedDocument($configurationOnlyUser, 'blocked-configuration.pdf', 'blocked-configuration');
        $configurationOnly->documents()->attach($configurationOnlyDocument->id, ['attached_by' => $configurationOnlyUser->id]);
        $this->allowAuthoritativeContract($configurationOnly->fresh());
        config()->set('services.nium.customer_rfi_response_contract_version', 'configuration-only-change');
        $this->expectFailure($configurationOnly->fresh(), 'not verified for live submission');
        config()->set('services.nium.customer_rfi_response_contract_version', 'corporate-rfi-response-test-v1');

        [$changedGate, , $changedGateUser] = $this->authoritativeCorporateCase('blocked-gate', 'template-gate', 'otherDocument', 'Changed gate');
        $this->makeHkProfile($changedGateUser);
        $changedGateDocument = $this->approvedDocument($changedGateUser, 'blocked-gate.pdf', 'blocked-gate');
        $changedGate->documents()->attach($changedGateDocument->id, ['attached_by' => $changedGateUser->id]);
        $changedGate->update(['contract_gate' => 'corporate-rfi-response-test-v1']);
        $this->allowAuthoritativeContract($changedGate->fresh());
        $this->expectFailure($changedGate->fresh(), 'authoritative Corporate RFI POST envelope remains unverified');

        foreach ([$unconfirmed, $configurationOnly, $changedGate] as $case) {
            $this->assertSame('admin_approved', $case->fresh()->submission_state);
            $this->assertNull($case->fresh()->claimed_at);
            $this->assertNull($case->fresh()->provider_response_evidence);
        }
        Http::assertNothingSent();
    }

    public function test_contract_transport_sends_exact_legacy_candidate_but_treats_http_200_as_unverified(): void
    {
        Storage::fake('kyc_private');
        config()->set('services.nium.regulatory_region', 'HK');
        [$case, $account, $user] = $this->authoritativeCorporateCase('transport-rfi', 'transport-template', 'otherDocument', 'Transport document');
        $this->makeHkProfile($user);
        $document = $this->approvedDocument($user, 'transport.pdf', 'transport-document');
        $case->documents()->attach($document->id, ['attached_by' => $user->id]);

        config()->set('services.nium.auth.header_name', 'x-nium-test-key');
        Http::fake(function (Request $request) use ($account) {
            $this->assertSame('POST', $request->method());
            $this->assertSame('https://gateway.test.invalid/api/v1/client/path-client-hash/corporate/rfi', $request->url());
            $this->assertTrue(Str::isUuid((string) ($request->header('x-request-id')[0] ?? '')));
            $this->assertSame('test-contract-key', $request->header('x-nium-test-key')[0] ?? null);
            $this->assertSame([
                'region' => 'HK',
                'customerHashId' => $account->external_customer_id,
                'rfiResponseRequest' => [[
                    'rfiHashId' => 'transport-rfi',
                    'businessDetails' => ['documentDetails' => ['document' => [[
                        'document' => base64_encode("%PDF-1.4\ntransport-document\n%%EOF"),
                        'fileName' => 'transport.pdf',
                        'fileType' => 'application/pdf',
                    ]]]],
                ]],
            ], $request->data());

            return Http::response(['status' => 'RFI_RESPONDED']);
        });

        $result = app(NiumCorporateRfiAuthoritativeTransport::class)->sendForContractTest($case->fresh());

        $this->assertSame('UNKNOWN', $result['outcome']);
        $this->assertSame('legacy_response_contract_unverified', $result['reason']);
        $this->assertTrue($result['manual_reconciliation_required']);
        $this->assertSame('admin_approved', $case->fresh()->submission_state);
        $this->assertNull($case->fresh()->claimed_at);
        $this->assertNull($case->fresh()->provider_response_evidence);
        $this->assertDatabaseCount('api_request_logs', 0);
        $this->assertDatabaseCount('nium_api_exchange_evidence', 0);
        Http::assertSentCount(1);
    }

    public function test_contract_transport_rejects_non_testing_unsafe_gateway_credentials_and_unprotected_http(): void
    {
        [$case] = $this->corporateCase();
        $transport = app(NiumCorporateRfiAuthoritativeTransport::class);

        $this->app->detectEnvironment(fn (): string => 'production');
        try {
            $transport->sendForContractTest($case);
            $this->fail('Expected the contract transport to reject non-testing environments.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not activated', $exception->getMessage());
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
        }

        config()->set('services.nium.base_url', 'https://gateway.nium.com');
        $this->expectContractTransportFailure($case, 'reserved test gateway');
        config()->set('services.nium.base_url', 'https://gateway.test.invalid');

        foreach ([
            ['header', '', 'test-contract-key'],
            ['header', 'Authorization', 'test-contract-key'],
            ['header', 'x-api-key', 'production-looking-secret'],
            ['none', 'x-api-key', 'test-contract-key'],
        ] as [$mode, $name, $value]) {
            config()->set('services.nium.auth', ['mode' => $mode, 'header_name' => $name, 'header_value' => $value]);
            $this->expectContractTransportFailure($case, 'safe synthetic test credential');
        }

        config()->set('services.nium.auth', ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'test-contract-key']);
        Http::allowStrayRequests();
        $this->expectContractTransportFailure($case, 'stray HTTP requests to be prevented');
        Http::assertNothingSent();
    }

    public function test_contract_transport_classifies_rejections_and_uncertain_outcomes_without_persisting_documents(): void
    {
        Storage::fake('kyc_private');
        config()->set('services.nium.regulatory_region', 'HK');
        Http::fake(['*' => Http::sequence()
            ->push(['message' => 'invalid'], 400)
            ->push(['message' => 'invalid'], 422)
            ->push(['message' => 'timeout'], 408)
            ->push(['message' => 'limited'], 429)
            ->push(['message' => 'unavailable'], 503)]);

        foreach ([400, 422, 408, 429, 503] as $status) {
            [$case, , $user] = $this->authoritativeCorporateCase("transport-{$status}", "template-{$status}", 'otherDocument', "Status {$status}");
            $this->makeHkProfile($user);
            $document = $this->approvedDocument($user, "status-{$status}.pdf", "status-{$status}");
            $case->documents()->attach($document->id, ['attached_by' => $user->id]);

            $result = app(NiumCorporateRfiAuthoritativeTransport::class)->sendForContractTest($case->fresh());
            $this->assertSame(in_array($status, [400, 422], true) ? 'REJECTED' : 'UNKNOWN', $result['outcome']);
            $this->assertSame(! in_array($status, [400, 422], true), $result['manual_reconciliation_required']);
            $this->assertNull($case->fresh()->provider_response_evidence);
        }
    }

    public function test_contract_transport_cannot_escape_when_no_http_fake_is_registered(): void
    {
        Storage::fake('kyc_private');
        config()->set('services.nium.regulatory_region', 'HK');
        [$case, , $user] = $this->authoritativeCorporateCase('unfaked-rfi', 'unfaked-template', 'otherDocument', 'Unfaked request');
        $this->makeHkProfile($user);
        $document = $this->approvedDocument($user, 'unfaked.pdf', 'unfaked-document');
        $case->documents()->attach($document->id, ['attached_by' => $user->id]);

        try {
            app(NiumCorporateRfiAuthoritativeTransport::class)->sendForContractTest($case->fresh());
            $this->fail('Expected Laravel HTTP to reject the unfaked contract request.');
        } catch (StrayRequestException $exception) {
            $this->assertStringContainsString('without a matching fake', $exception->getMessage());
            $this->assertStringNotContainsString(base64_encode("%PDF-1.4\nunfaked-document\n%%EOF"), $exception->getMessage());
            $this->assertStringNotContainsString('unfaked.pdf', $exception->getMessage());
        }

        $this->assertSame('admin_approved', $case->fresh()->submission_state);
        $this->assertNull($case->fresh()->provider_response_evidence);
        $this->assertDatabaseCount('api_request_logs', 0);
        $this->assertDatabaseCount('nium_api_exchange_evidence', 0);
    }

    public function test_production_authoritative_workflow_is_disabled_by_default(): void
    {
        [$case] = $this->authoritativeCorporateCase('disabled-authoritative', 'disabled-template', 'otherDocument', 'Disabled submission');

        try {
            app(NiumCorporateRfiAuthoritativeSubmissionService::class)->submit($case);
            $this->fail('Expected authoritative Corporate RFI submission to remain disabled.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('AUTHORITATIVE_SUBMISSION_DISABLED', $exception->getMessage());
        }

        $this->assertSame('admin_approved', $case->fresh()->submission_state);
        $this->assertNull($case->fresh()->claimed_at);
        Http::assertNothingSent();
    }

    public function test_operator_command_inspects_without_writes_or_http_and_rejects_wrong_or_stale_cases(): void
    {
        [$case] = $this->activatedAuthoritativeSubmissionCase('command-inspect');
        config()->set('services.nium.customer_rfi_authoritative_submission_enabled', false);
        $this->artisan('nium:submit-authoritative-corporate-rfi', ['case' => $case->id])
            ->expectsOutputToContain("case_id={$case->id} state=admin_approved outcome=INSPECTED correlation=none")
            ->assertSuccessful();
        $this->assertSame('admin_approved', $case->fresh()->submission_state);
        $this->assertNull($case->fresh()->claimed_at);
        Http::assertNothingSent();

        [$wrongCase] = $this->corporateCase();
        $this->artisan('nium:submit-authoritative-corporate-rfi', ['case' => $wrongCase->id])
            ->expectsOutputToContain('case selection rejected')
            ->assertFailed();

        [$transactionCase] = $this->corporateCase();
        $transactionCase->update(['scope' => 'transaction']);
        $this->artisan('nium:submit-authoritative-corporate-rfi', ['case' => $transactionCase->id])
            ->expectsOutputToContain('case selection rejected')
            ->assertFailed();

        [$staleCase] = $this->activatedAuthoritativeSubmissionCase('command-stale');
        $staleEvidence = $staleCase->evidence;
        $staleEvidence['rfiStatus'] = 'RFI_RESPONDED';
        $staleCase->update(['evidence' => $staleEvidence]);
        $this->artisan('nium:submit-authoritative-corporate-rfi', ['case' => $staleCase->id])
            ->expectsOutputToContain('case is stale')
            ->assertFailed();
        Http::assertNothingSent();
    }

    public function test_operator_command_requires_authorization_activation_gate_and_confirmation(): void
    {
        [$case] = $this->activatedAuthoritativeSubmissionCase('command-authorization');
        $this->artisan('nium:submit-authoritative-corporate-rfi', [
            'case' => $case->id,
            '--submit' => true,
        ])->expectsOutputToContain('operator authorization rejected')->assertFailed();

        $authorized = [
            'case' => $case->id,
            '--submit' => true,
            '--expect-reference-fingerprint' => $case->provider_reference_fingerprint,
            '--approve' => SubmitNiumAuthoritativeCorporateRfi::APPROVAL,
            '--operator' => 'OPS-123 verified Legacy Corporate RFI',
        ];
        $wrongFingerprint = $authorized;
        $wrongFingerprint['--expect-reference-fingerprint'] = str_repeat('0', 64);
        $this->artisan('nium:submit-authoritative-corporate-rfi', $wrongFingerprint)
            ->expectsOutputToContain('case identity rejected')->assertFailed();

        config()->set('services.nium.customer_rfi_authoritative_submission_enabled', false);
        $this->artisan('nium:submit-authoritative-corporate-rfi', $authorized)
            ->expectsOutputToContain('submission disabled')->assertFailed();

        config()->set('services.nium.customer_rfi_authoritative_submission_enabled', true);
        config()->set('nium_rfi_contracts.corporate', []);
        $this->artisan('nium:submit-authoritative-corporate-rfi', $authorized)
            ->expectsOutputToContain('contract gate rejected')->assertFailed();

        $this->allowAuthoritativeContract($case->fresh());
        $this->artisan('nium:submit-authoritative-corporate-rfi', $authorized)
            ->expectsConfirmation("Submit authoritative Corporate RFI case {$case->id}?", 'no')
            ->expectsOutputToContain('operator confirmation rejected')
            ->assertFailed();
        $this->assertSame('admin_approved', $case->fresh()->submission_state);
        Http::assertNothingSent();
    }

    public function test_operator_command_reports_acknowledgement_and_uncertainty_without_sensitive_output_or_retry(): void
    {
        [$ackCase, $ackAccount] = $this->activatedAuthoritativeSubmissionCase('command-ack');
        Http::fake(['*' => Http::response(['status' => 'RFI_RESPONDED'])]);
        $ackOptions = $this->authorizedCommandOptions($ackCase);
        $this->artisan('nium:submit-authoritative-corporate-rfi', $ackOptions)
            ->expectsConfirmation("Submit authoritative Corporate RFI case {$ackCase->id}?", 'yes')
            ->expectsOutputToContain("case_id={$ackCase->id} state=provider_acknowledged outcome=ACKNOWLEDGED correlation=")
            ->assertSuccessful();
        $ackOutput = Artisan::output();
        $this->assertStringNotContainsString((string) $ackAccount->external_customer_id, $ackOutput);
        $this->assertStringNotContainsString('command-ack.pdf', $ackOutput);
        $this->assertStringNotContainsString(base64_encode("%PDF-1.4\ndocument-command-ack\n%%EOF"), $ackOutput);

        [$unknownCase] = $this->activatedAuthoritativeSubmissionCase('command-unknown');
        Http::fake(['*' => Http::failedConnection('timeout')]);
        $unknownOptions = $this->authorizedCommandOptions($unknownCase);
        $this->artisan('nium:submit-authoritative-corporate-rfi', $unknownOptions)
            ->expectsConfirmation("Submit authoritative Corporate RFI case {$unknownCase->id}?", 'yes')
            ->expectsOutputToContain("case_id={$unknownCase->id} state=unknown outcome=UNKNOWN correlation=")
            ->assertSuccessful();
        $this->artisan('nium:submit-authoritative-corporate-rfi', $unknownOptions)
            ->expectsOutputToContain('case state rejected')
            ->assertFailed();
        Http::assertSentCount(1);
    }

    public function test_authoritative_acknowledgement_requires_matching_get_reconciliation_and_never_persists_documents(): void
    {
        Storage::fake('kyc_private');
        config()->set('services.nium.regulatory_region', 'HK');
        config()->set('services.nium.customer_rfi_authoritative_submission_enabled', true);
        [$case, $account, $user] = $this->authoritativeCorporateCase('production-rfi', 'production-template', 'otherDocument', 'Production workflow');
        $this->makeHkProfile($user);
        $document = $this->approvedDocument($user, 'production-private.pdf', 'production-private-document');
        $case->documents()->attach($document->id, ['attached_by' => $user->id]);
        $case->update(['contract_gate' => 'corporate-rfi-response-test-v1']);
        $this->allowAuthoritativeContract($case->fresh());
        Http::fake(['*' => Http::response(['status' => 'RFI_RESPONDED'])]);

        $acknowledged = app(NiumCorporateRfiAuthoritativeSubmissionService::class)->submit($case->fresh());

        $this->assertSame('provider_acknowledged', $acknowledged->submission_state);
        $this->assertSame('ACKNOWLEDGED', data_get($acknowledged->provider_response_evidence, 'outcome'));
        $this->assertTrue((bool) data_get($acknowledged->provider_response_evidence, 'manual_reconciliation_required'));
        $requestId = data_get($acknowledged->provider_response_evidence, 'x_request_id');
        $this->assertTrue(Str::isUuid((string) $requestId));

        $log = ApiRequestLog::query()->where('operation', 'corporate_rfi_authoritative_submit')->sole();
        $this->assertSame(['region' => 'HK'], $log->request_body);
        $exchange = NiumApiExchangeEvidence::query()->where('api_request_log_id', $log->id)->sole();
        $this->assertNull($exchange->raw_request_body);
        $this->assertNull($exchange->raw_response_body);
        $this->assertStringNotContainsString('production-private.pdf', json_encode($log->toArray()));
        $this->assertStringNotContainsString(base64_encode("%PDF-1.4\nproduction-private-document\n%%EOF"), json_encode($log->toArray()));

        try {
            app(NiumCorporateRfiAuthoritativeSubmissionService::class)->submit($acknowledged->fresh());
            $this->fail('Expected duplicate authoritative submission to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('separate human approval', $exception->getMessage());
        }
        Http::assertSentCount(1);

        $account->update([
            'provider_status' => 'pending',
            'provider_sub_status' => 'under_review',
            'customer_id_verified_at' => now(),
        ]);
        $this->expectReconciliationFailure($account->fresh(), null, 'evidence is required');
        $this->assertSame('provider_acknowledged', $case->fresh()->submission_state);
        app(NiumRfiWorkflowService::class)->reconcileCustomerEvidence($account->fresh(), [[
            'rfiHashId' => 'production-rfi',
            'templateId' => 'production-template',
            'template' => $case->evidence['template'],
            'remarks' => 'Confirmed by authoritative GET.',
            'status' => 'RFI_RESPONDED',
        ]]);

        $reconciled = $case->fresh();
        $this->assertSame('responded', $reconciled->submission_state);
        $this->assertSame('RECONCILED', data_get($reconciled->provider_response_evidence, 'outcome'));
        $this->assertSame($requestId, data_get($reconciled->provider_response_evidence, 'x_request_id'));
        $this->assertFalse((bool) data_get($reconciled->provider_response_evidence, 'manual_reconciliation_required'));
        $this->assertArrayNotHasKey('rfi_hash_id', $reconciled->provider_response_evidence);
        $this->assertSame('pending', $account->fresh()->provider_status);
    }

    public function test_authoritative_workflow_separates_rejection_uncertainty_and_invalid_success(): void
    {
        Storage::fake('kyc_private');
        config()->set('services.nium.regulatory_region', 'HK');
        config()->set('services.nium.customer_rfi_authoritative_submission_enabled', true);
        Http::fake(['*' => Http::sequence()
            ->push(['message' => 'invalid'], 422)
            ->push(['message' => 'timeout'], 408)
            ->push(['status' => 'PENDING'], 200)]);

        foreach ([
            [422, 'rejected', 'REJECTED', false],
            [408, 'unknown', 'UNKNOWN', true],
            [200, 'unknown', 'UNKNOWN', true],
        ] as [$status, $state, $outcome, $reconcile]) {
            [$case, , $user] = $this->authoritativeCorporateCase("outcome-{$status}", "outcome-template-{$status}", 'otherDocument', "Outcome {$status}");
            $this->makeHkProfile($user);
            $document = $this->approvedDocument($user, "outcome-{$status}.pdf", "outcome-{$status}");
            $case->documents()->attach($document->id, ['attached_by' => $user->id]);
            $case->update(['contract_gate' => 'corporate-rfi-response-test-v1']);
            $this->allowAuthoritativeContract($case->fresh());

            $result = app(NiumCorporateRfiAuthoritativeSubmissionService::class)->submit($case->fresh());
            $this->assertSame($state, $result->submission_state);
            $this->assertSame($outcome, data_get($result->provider_response_evidence, 'outcome'));
            $this->assertSame($reconcile, (bool) data_get($result->provider_response_evidence, 'manual_reconciliation_required'));
            $this->assertSame($status, data_get($result->provider_response_evidence, 'http_status'));
        }
    }

    public function test_authoritative_deterministic_preflight_failures_do_not_claim(): void
    {
        [$configurationCase] = $this->activatedAuthoritativeSubmissionCase('preflight-config');
        $service = $this->partialMock(NiumService::class, function ($mock): void {
            $mock->shouldReceive('clientId')->andReturn('path-client-hash');
            $mock->shouldReceive('assertConfigured')->once()->andThrow(new RuntimeException('invalid local Nium configuration'));
        });
        $this->expectAuthoritativeFailure($configurationCase, 'invalid local Nium configuration');
        $this->assertSame('admin_approved', $configurationCase->fresh()->submission_state);
        $this->assertNull($configurationCase->fresh()->claimed_at);

        $this->app->forgetInstance(NiumService::class);
        [$endpointCase] = $this->activatedAuthoritativeSubmissionCase('preflight-endpoint');
        $this->partialMock(NiumService::class, function ($mock): void {
            $mock->shouldReceive('clientId')->andReturn('path-client-hash');
            $mock->shouldReceive('assertConfigured')->once();
            $mock->shouldReceive('path')->once()->andThrow(new RuntimeException('invalid deterministic endpoint'));
        });
        $this->expectAuthoritativeFailure($endpointCase, 'invalid deterministic endpoint');
        $this->assertSame('admin_approved', $endpointCase->fresh()->submission_state);
        $this->assertNull($endpointCase->fresh()->claimed_at);
        Http::assertNothingSent();
    }

    public function test_claim_normalizes_null_existing_and_malformed_provider_evidence(): void
    {
        $workflow = app(NiumRfiWorkflowService::class);

        [$nullCase] = $this->corporateCase();
        $nullRequestId = (string) Str::uuid();
        $claimedNull = $workflow->claimForProviderSubmission($nullCase, $nullRequestId);
        $this->assertSame($nullRequestId, data_get($claimedNull->provider_response_evidence, 'x_request_id'));

        [$existingCase] = $this->corporateCase();
        $existingCase->update(['provider_response_evidence' => [
            'legitimate_existing_field' => 'preserved',
            'manual_reconciliation_required' => false,
            'x_request_id' => 'superseded-correlation',
        ]]);
        $existingRequestId = (string) Str::uuid();
        $claimedExisting = $workflow->claimForProviderSubmission($existingCase->fresh(), $existingRequestId, [
            'template_fingerprint' => 'template-fingerprint',
        ]);
        $this->assertSame('preserved', data_get($claimedExisting->provider_response_evidence, 'legitimate_existing_field'));
        $this->assertFalse(data_get($claimedExisting->provider_response_evidence, 'manual_reconciliation_required'));
        $this->assertSame('template-fingerprint', data_get($claimedExisting->provider_response_evidence, 'template_fingerprint'));
        $this->assertSame($existingRequestId, data_get($claimedExisting->provider_response_evidence, 'x_request_id'));

        [$malformedCase] = $this->corporateCase();
        DB::table('nium_rfi_cases')->where('id', $malformedCase->id)->update([
            'provider_response_evidence' => json_encode('unexpected-scalar'),
        ]);
        $malformedRequestId = (string) Str::uuid();
        $claimedMalformed = $workflow->claimForProviderSubmission($malformedCase->fresh(), $malformedRequestId, [
            'provider_reference_fingerprint' => 'safe-fingerprint',
        ]);
        $this->assertSame($malformedRequestId, data_get($claimedMalformed->provider_response_evidence, 'x_request_id'));
        $this->assertSame('safe-fingerprint', data_get($claimedMalformed->provider_response_evidence, 'provider_reference_fingerprint'));
        $this->assertNotContains('unexpected-scalar', $claimedMalformed->provider_response_evidence);
        Http::assertNothingSent();
    }

    public function test_authoritative_transport_and_evidence_failures_remain_unresolved_and_are_not_resent(): void
    {
        [$transportCase] = $this->activatedAuthoritativeSubmissionCase('transport-uncertain');
        Http::fake(['*' => Http::failedConnection('timeout')]);
        $uncertain = app(NiumCorporateRfiAuthoritativeSubmissionService::class)->submit($transportCase->fresh());
        $this->assertSame('unknown', $uncertain->submission_state);
        $this->assertTrue((bool) data_get($uncertain->provider_response_evidence, 'manual_reconciliation_required'));
        $this->assertTrue(Str::isUuid((string) data_get($uncertain->provider_response_evidence, 'x_request_id')));
        $this->expectAuthoritativeFailure($uncertain->fresh(), 'separate human approval');
        Http::assertSentCount(1);

        $this->app->forgetInstance(NiumService::class);
        [$evidenceCase] = $this->activatedAuthoritativeSubmissionCase('evidence-failure');
        $this->partialMock(NiumService::class, function ($mock): void {
            $mock->shouldReceive('clientId')->andReturn('path-client-hash');
            $mock->shouldReceive('assertConfigured')->once();
            $mock->shouldReceive('path')->andReturn('/api/v1/client/path-client-hash/corporate/rfi');
            $mock->shouldReceive('postSensitiveWithRequestId')->once()->andThrow(new NiumEvidencePersistenceException([
                'http_status' => 200,
                'transport_outcome' => 'persistence_failure',
            ]));
        });
        $evidenceFailure = app(NiumCorporateRfiAuthoritativeSubmissionService::class)->submit($evidenceCase->fresh());
        $this->assertSame('unknown', $evidenceFailure->submission_state);
        $this->assertSame(200, data_get($evidenceFailure->provider_response_evidence, 'http_status'));
        $this->assertSame('provider_response_received_but_evidence_persistence_failed', data_get($evidenceFailure->provider_response_evidence, 'reason'));
        $this->assertTrue((bool) data_get($evidenceFailure->provider_response_evidence, 'manual_reconciliation_required'));
    }

    public function test_authoritative_get_reconciliation_rejects_stale_fingerprints_and_invalid_states(): void
    {
        [$case, $account] = $this->activatedAuthoritativeSubmissionCase('reconcile-guards');
        Http::fake(['*' => Http::response(['status' => 'RFI_RESPONDED'])]);
        $acknowledged = app(NiumCorporateRfiAuthoritativeSubmissionService::class)->submit($case->fresh());
        $account->update(['provider_status' => 'pending', 'provider_sub_status' => 'under_review', 'customer_id_verified_at' => now()]);
        $providerRfi = [[
            'rfiHashId' => 'reconcile-guards',
            'templateId' => 'template-reconcile-guards',
            'template' => $case->evidence['template'],
            'status' => 'RFI_RESPONDED',
        ]];

        $validEvidence = $acknowledged->provider_response_evidence;
        $badEvidence = $acknowledged->provider_response_evidence;
        $badEvidence['provider_reference_fingerprint'] = hash('sha256', 'stale-reference');
        $acknowledged->update(['provider_response_evidence' => $badEvidence]);
        $this->expectReconciliationFailure($account->fresh(), $providerRfi, 'does not match the claimed submission');
        $this->assertSame('provider_acknowledged', $acknowledged->fresh()->submission_state);

        $acknowledged->update([
            'provider_response_evidence' => $validEvidence,
            'submission_state' => 'rejected',
        ]);
        $this->expectReconciliationFailure($account->fresh(), $providerRfi, 'does not match the claimed submission');
        $this->assertSame('rejected', $acknowledged->fresh()->submission_state);
    }

    public function test_production_case_two_to_five_fixtures_reconcile_idempotently_from_authoritative_get(): void
    {
        $account = null;
        $providerRfis = [];
        $cases = [];
        foreach ([2 => 'provider_acknowledged', 3 => 'unknown', 4 => 'unknown', 5 => 'unknown'] as $number => $state) {
            [$case, $caseAccount] = $this->authoritativeCorporateCase(
                "fixture-production-case-{$number}",
                "fixture-production-template-{$number}",
                'otherDocument',
                "Synthetic fixture for previously submitted case {$number}",
            );
            $account ??= $caseAccount;
            if ($caseAccount->id !== $account->id) {
                $case->update(['user_provider_account_id' => $account->id]);
            }
            $requestId = (string) Str::uuid();
            $case->update([
                'submission_state' => $state,
                'claimed_at' => now(),
                'provider_response_evidence' => [
                    'submission_mode' => 'authoritative_legacy',
                    'x_request_id' => $requestId,
                    'request_correlation_fingerprint' => hash('sha256', $requestId),
                    'provider_reference_fingerprint' => hash('sha256', "fixture-production-case-{$number}"),
                    'template_fingerprint' => hash('sha256', "fixture-production-template-{$number}"),
                    'outcome' => $state === 'provider_acknowledged' ? 'ACKNOWLEDGED' : 'UNKNOWN',
                ],
            ]);
            $cases[] = $case;
            $providerRfis[] = [
                'rfiHashId' => "fixture-production-case-{$number}",
                'templateId' => "fixture-production-template-{$number}",
                'template' => $case->evidence['template'],
                'remarks' => "Synthetic fixture {$number}",
                'status' => 'RFI_RESPONDED',
            ];
        }
        $account->update(['provider_status' => 'pending', 'provider_sub_status' => 'under_review', 'customer_id_verified_at' => now()]);

        app(NiumRfiWorkflowService::class)->reconcileCustomerEvidence($account->fresh(), $providerRfis);
        app(NiumRfiWorkflowService::class)->reconcileCustomerEvidence($account->fresh(), $providerRfis);

        foreach ($cases as $case) {
            $this->assertSame('responded', $case->fresh()->submission_state);
            $this->assertSame('responded_under_review', $case->fresh()->status);
            $this->assertSame('RECONCILED', data_get($case->fresh()->provider_response_evidence, 'outcome'));
        }
        Http::assertNothingSent();
    }

    public function test_two_authoritative_cases_can_reuse_one_approved_document_without_mixing_rfi_identity(): void
    {
        Storage::fake('kyc_private');
        [$first, $account, $user, $provider] = $this->authoritativeCorporateCase('shared-rfi-one', 'shared-template-one', 'otherDocument', 'First shared document');
        $document = $this->approvedDocument($user, 'shared.pdf', 'shared-approved-document');
        $first->documents()->attach($document->id, ['attached_by' => $user->id]);
        $second = NiumRfiCase::query()->create([
            'provider_id' => $provider->id,
            'user_provider_account_id' => $account->id,
            'transaction_id' => null,
            'scope' => 'customer',
            'origin' => 'authoritative_fetch',
            'provider_reference_fingerprint' => hash('sha256', 'shared-rfi-two'),
            'status' => 'requested',
            'evidence' => [
                'rfiHashId' => 'shared-rfi-two',
                'templateId' => 'shared-template-two',
                'template' => $first->evidence['template'],
                'remarks' => 'Second shared document',
                'rfiStatus' => 'RFI_REQUESTED',
            ],
            'contract_gate' => 'provider_contract_unconfirmed',
            'submission_state' => 'admin_approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);
        $second->documents()->attach($document->id, ['attached_by' => $user->id]);

        $firstPayload = app(NiumCorporateRfiSubmissionService::class)->preparePayload($first->fresh());
        $secondPayload = app(NiumCorporateRfiSubmissionService::class)->preparePayload($second->fresh());

        $this->assertSame('shared-rfi-one', $firstPayload['rfiHashId']);
        $this->assertSame('shared-rfi-two', $secondPayload['rfiHashId']);
        $this->assertSame($firstPayload['fields'][0]['documents'][0]['fileHash'], $secondPayload['fields'][0]['documents'][0]['fileHash']);
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
            'transaction_id' => null,
            'scope' => 'customer',
            'origin' => 'webhook',
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
            'transaction_id' => null,
            'scope' => 'customer',
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

    private function expectContractTransportFailure(NiumRfiCase $case, string $message): void
    {
        try {
            app(NiumCorporateRfiAuthoritativeTransport::class)->sendForContractTest($case);
            $this->fail('Expected the authoritative Corporate RFI contract transport to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function activatedAuthoritativeSubmissionCase(string $suffix): array
    {
        Storage::fake('kyc_private');
        config()->set('services.nium.regulatory_region', 'HK');
        config()->set('services.nium.customer_rfi_authoritative_submission_enabled', true);
        [$case, $account, $user, $provider] = $this->authoritativeCorporateCase(
            $suffix,
            "template-{$suffix}",
            'otherDocument',
            "Document {$suffix}",
        );
        $this->makeHkProfile($user);
        $document = $this->approvedDocument($user, "{$suffix}.pdf", "document-{$suffix}");
        $case->documents()->attach($document->id, ['attached_by' => $user->id]);
        $case->update(['contract_gate' => 'corporate-rfi-response-test-v1']);
        $this->allowAuthoritativeContract($case->fresh());

        return [$case->fresh(), $account, $user, $provider];
    }

    private function allowAuthoritativeContract(NiumRfiCase $case): void
    {
        $shape = [
            'template_name' => data_get($case->evidence, 'template.name'),
            'rfi_type' => data_get($case->evidence, 'template.rfiType'),
            'field_value' => data_get($case->evidence, 'template.requiredFields.0.fieldValue'),
            'field_type' => data_get($case->evidence, 'template.requiredFields.0.type'),
        ];
        $verified = (array) config('nium_rfi_contracts.corporate', []);
        $verified[] = [
            'environment' => app()->environment(),
            'endpoint' => '/api/v1/client/{clientHashId}/corporate/rfi',
            'method' => 'POST',
            'request_schema_version' => 'corporate-rfi-request-test-v1',
            'contract_version' => 'corporate-rfi-response-test-v1',
            'template_fingerprint' => hash('sha256', (string) data_get($case->evidence, 'templateId')),
            'required_field_shape_fingerprint' => hash('sha256', json_encode($shape, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        ];
        config()->set('nium_rfi_contracts.corporate', $verified);
    }

    private function authorizedCommandOptions(NiumRfiCase $case): array
    {
        return [
            'case' => $case->id,
            '--submit' => true,
            '--expect-reference-fingerprint' => $case->provider_reference_fingerprint,
            '--approve' => SubmitNiumAuthoritativeCorporateRfi::APPROVAL,
            '--operator' => 'OPS-123 verified Legacy Corporate RFI',
        ];
    }

    private function expectAuthoritativeFailure(NiumRfiCase $case, string $message): void
    {
        try {
            app(NiumCorporateRfiAuthoritativeSubmissionService::class)->submit($case);
            $this->fail('Expected authoritative Corporate RFI submission to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function expectReconciliationFailure(UserProviderAccount $account, ?array $providerRfis, string $message): void
    {
        try {
            app(NiumRfiWorkflowService::class)->reconcileCustomerEvidence($account, $providerRfis);
            $this->fail('Expected authoritative Corporate RFI reconciliation to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
