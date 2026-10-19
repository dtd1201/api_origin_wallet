<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\IntegrationProvider;
use App\Models\KycDocument;
use App\Models\NiumRfiCase;
use App\Models\User;
use App\Models\UserProviderAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SuperAdminCorporateRfiIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private string $rfiHashId = 'synthetic-future-rfi-reference';

    private string $templateId = 'synthetic-other-document-template';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kyc_private');
        Http::preventStrayRequests();
        config()->set('services.nium.base_url', 'https://gateway.test.invalid');
        config()->set('services.nium.client_id', 'synthetic-client');
        config()->set('services.nium.auth', ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'test-corporate-rfi-key']);
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'test-webhook-key');
        config()->set('services.nium.regulatory_region', 'HK');
        config()->set('services.nium.customer_rfi_request_schema_version', 'synthetic-reviewed-request-v1');
        config()->set('services.nium.customer_rfi_response_contract_version', 'synthetic-reviewed-response-v1');
        config()->set('nium_rfi_contracts.corporate', [$this->testContract()]);
    }

    public function test_two_customer_documents_are_approved_submitted_and_authoritatively_reconciled(): void
    {
        [$case, $account, $excludedDocument] = $this->caseWithApprovedDocument();
        $customer = $account->user()->firstOrFail();
        $case->documents()->detach($excludedDocument->id);
        $case->update(['submission_state' => 'sent_to_user', 'approved_at' => null, 'approved_by' => null]);

        $documents = collect([
            ['2026-07-bank-statement.pdf', "%PDF-1.4\nSynthetic July 2026 bank statement\n%%EOF"],
            ['2026-08-bank-statement.pdf', "%PDF-1.4\nSynthetic August 2026 bank statement\n%%EOF"],
        ])->map(function (array $fixture) use ($customer): KycDocument {
            [$fileName, $contents] = $fixture;
            $path = 'rfi/'.$customer->id.'/'.Str::uuid().'.pdf';
            Storage::disk('kyc_private')->put($path, $contents);

            return KycDocument::query()->create([
                'kyc_profile_id' => $customer->kycProfile->id,
                'type' => 'corporate_rfi_supporting_document',
                'status' => 'pending_admin_review',
                'file_url' => 'private://'.$path,
                'storage_disk' => 'kyc_private',
                'file_path' => $path,
                'original_name' => $fileName,
                'mime_type' => 'application/pdf',
                'file_size' => strlen($contents),
                'file_hash' => hash('sha256', $contents),
                'metadata' => ['factual' => true, 'synthetic' => false, 'rfi_upload' => true],
            ]);
        });

        $superAdmin = $this->admin('super_admin');
        $adminToken = $this->issueTokenFor($superAdmin);
        $customerToken = $this->issueTokenFor($customer);

        $this->withToken($customerToken)->putJson("/api/user/users/{$customer->id}/nium-rfi-cases/{$case->id}/draft", [
            'document_ids' => $documents->pluck('id')->all(),
            'explanation' => 'Official company bank statements for July and August 2026.',
        ])->assertOk()
            ->assertJsonPath('submission_state', 'user_draft')
            ->assertJsonPath('supporting_file_count', 2);
        $this->withToken($customerToken)->postJson("/api/user/users/{$customer->id}/nium-rfi-cases/{$case->id}/submit")
            ->assertOk()->assertJsonPath('submission_state', 'user_submitted');
        $this->withToken($adminToken)->postJson("/api/admin/nium-rfi-cases/{$case->id}/approve-user-submission")
            ->assertOk()
            ->assertJsonPath('submission_state', 'admin_approved')
            ->assertJsonPath('supporting_file_count', 2);
        $this->assertSame(['approved', 'approved'], $documents->map(fn (KycDocument $document) => $document->fresh()->status)->all());

        config()->set('services.nium.customer_rfi_authoritative_submission_enabled', true);
        $postCount = 0;
        $getCount = 0;
        Http::fake(function (Request $request) use (&$postCount, &$getCount, $account, $documents) {
            $this->assertSame('https://gateway.test.invalid/api/v1/client/synthetic-client/corporate/rfi', strtok($request->url(), '?'));
            if ($request->method() === 'POST') {
                $postCount++;
                $expectedDocuments = $documents->sortBy('id')->values()->map(fn (KycDocument $document): array => [
                    'document' => base64_encode(Storage::disk('kyc_private')->get($document->file_path)),
                    'fileName' => $document->original_name,
                    'fileType' => 'application/pdf',
                ])->all();
                $this->assertSame([
                    'region' => 'HK',
                    'customerHashId' => $account->external_customer_id,
                    'rfiResponseRequest' => [[
                        'rfiHashId' => $this->rfiHashId,
                        'businessDetails' => ['documentDetails' => ['document' => $expectedDocuments]],
                    ]],
                ], $request->data());
                $this->assertCount(1, $request->data()['rfiResponseRequest']);
                $this->assertCount(2, data_get($request->data(), 'rfiResponseRequest.0.businessDetails.documentDetails.document'));

                return Http::response(['status' => 'RFI_RESPONDED'], 200);
            }

            $this->assertSame('GET', $request->method());
            $getCount++;

            return Http::response(['rfiTemplates' => [$this->providerRfi('RFI_RESPONDED')]], 200);
        });

        $fingerprints = $this->fingerprints($case);
        $this->withToken($adminToken)->postJson("/api/admin/nium-rfi-cases/{$case->id}/submit", [
            'case_id' => $case->id,
            'confirmation' => 'SUBMIT '.$case->id,
            ...$fingerprints,
        ])->assertOk()->assertJsonPath('submission_state', 'provider_acknowledged');

        $this->withToken($adminToken)->postJson("/api/admin/nium-rfi-cases/{$case->id}/refresh-status", [
            'case_id' => $case->id,
            ...$fingerprints,
        ])->assertOk()
            ->assertJsonPath('status', 'responded_under_review')
            ->assertJsonPath('submission_state', 'responded')
            ->assertJsonPath('submission_outcome', 'RECONCILED')
            ->assertJsonPath('authoritative_rfi_status', 'RFI_RESPONDED')
            ->assertJsonPath('reconciliation_required', false);

        $this->withToken($adminToken)->postJson("/api/admin/nium-rfi-cases/{$case->id}/submit", [
            'case_id' => $case->id,
            'confirmation' => 'SUBMIT '.$case->id,
            ...$fingerprints,
        ])->assertUnprocessable();
        $this->assertSame(1, $postCount);
        $this->assertSame(1, $getCount);

        $detail = $this->withToken($adminToken)->getJson("/api/admin/nium-rfi-cases/{$case->id}")->assertOk();
        $json = $detail->getContent();
        $this->assertStringNotContainsString($this->rfiHashId, $json);
        $this->assertStringNotContainsString($this->templateId, $json);
        $this->assertStringNotContainsString((string) $account->external_customer_id, $json);
        $this->assertStringNotContainsString('test-corporate-rfi-key', $json);
        foreach ($documents as $document) {
            $this->assertStringNotContainsString(base64_encode(Storage::disk('kyc_private')->get($document->file_path)), $json);
        }
        $this->assertStringNotContainsString(base64_encode(Storage::disk('kyc_private')->get($excludedDocument->file_path)), $json);
        $detail->assertJsonPath('submission_http_status', 200)
            ->assertJsonPath('submission_outcome', 'RECONCILED');
    }

    public function test_live_permission_and_global_activation_are_required_before_any_post(): void
    {
        [$case] = $this->caseWithApprovedDocument();
        $superAdminToken = $this->issueTokenFor($this->admin('super_admin'));
        $this->withToken($superAdminToken)->postJson("/api/admin/nium-rfi-cases/{$case->id}/approve")->assertOk();
        $payload = ['case_id' => $case->id, 'confirmation' => 'SUBMIT '.$case->id, ...$this->fingerprints($case)];
        Http::fake();

        foreach (['compliance_officer', 'admin'] as $role) {
            $this->withToken($this->issueTokenFor($this->admin($role)))
                ->postJson("/api/admin/nium-rfi-cases/{$case->id}/submit", $payload)
                ->assertForbidden();
        }

        config()->set('services.nium.customer_rfi_authoritative_submission_enabled', false);
        $this->withToken($superAdminToken)->postJson("/api/admin/nium-rfi-cases/{$case->id}/submit", $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'NIUM_RFI_AUTHORITATIVE_SUBMISSION_DISABLED: authoritative Corporate RFI POST is not activated.');
        $this->assertSame('approved', $case->fresh()->submission_state);
        $this->assertNull($case->fresh()->claimed_at);
        Http::assertNothingSent();
    }

    public function test_unregistered_templates_and_uncertain_attempts_never_post_or_retry_automatically(): void
    {
        $token = $this->issueTokenFor($this->admin('super_admin'));
        config()->set('services.nium.customer_rfi_authoritative_submission_enabled', true);

        foreach (['businessRegistrationDocument', 'unknownDocument'] as $templateName) {
            [$blocked] = $this->caseWithApprovedDocument($templateName, "template-{$templateName}", "rfi-{$templateName}");
            $this->withToken($token)->postJson("/api/admin/nium-rfi-cases/{$blocked->id}/approve")->assertOk();
            Http::fake();
            $this->withToken($token)->postJson("/api/admin/nium-rfi-cases/{$blocked->id}/submit", [
                'case_id' => $blocked->id,
                'confirmation' => 'SUBMIT '.$blocked->id,
                ...$this->fingerprints($blocked),
            ])->assertUnprocessable();
            $this->assertNull($blocked->fresh()->claimed_at);
            Http::assertNothingSent();
        }

        [$uncertain] = $this->caseWithApprovedDocument();
        $this->withToken($token)->postJson("/api/admin/nium-rfi-cases/{$uncertain->id}/approve")->assertOk();
        Http::fake(['*' => Http::failedConnection('synthetic timeout')]);
        $payload = ['case_id' => $uncertain->id, 'confirmation' => 'SUBMIT '.$uncertain->id, ...$this->fingerprints($uncertain)];
        $this->withToken($token)->postJson("/api/admin/nium-rfi-cases/{$uncertain->id}/submit", $payload)
            ->assertOk()->assertJsonPath('submission_state', 'unknown');
        $this->withToken($token)->postJson("/api/admin/nium-rfi-cases/{$uncertain->id}/submit", $payload)
            ->assertUnprocessable();
        Http::assertSentCount(1);
        $this->assertNotNull($uncertain->fresh()->claimed_at);
        $this->assertTrue((bool) data_get($uncertain->fresh()->provider_response_evidence, 'manual_reconciliation_required'));
    }

    private function caseWithApprovedDocument(
        string $templateName = 'otherDocument',
        ?string $templateId = null,
        ?string $rfiHashId = null,
    ): array {
        $templateId ??= $this->templateId;
        $rfiHashId ??= $this->rfiHashId;
        $provider = IntegrationProvider::query()->firstOrCreate(
            ['code' => 'nium'],
            ['name' => 'Nium', 'status' => 'active'],
        );
        $customer = User::factory()->create();
        $profile = $customer->kycProfile()->create([
            'status' => 'approved', 'applicant_type' => 'business', 'legal_name' => 'Synthetic Corporate RFI Customer',
            'address_line1' => '1 Synthetic Road', 'city' => 'Hong Kong', 'country_code' => 'HK',
            'registered_country_code' => 'HK', 'metadata' => ['nium_region' => 'HK'],
        ]);
        $account = UserProviderAccount::query()->create([
            'user_id' => $customer->id, 'provider_id' => $provider->id,
            'external_customer_id' => 'synthetic-customer-'.Str::uuid(), 'status' => 'active',
            'provider_status' => 'pending', 'provider_sub_status' => 'rfi_requested', 'rfi_status' => 'requested',
            'customer_id_verified_at' => now(),
        ]);
        $case = NiumRfiCase::query()->create([
            'provider_id' => $provider->id, 'user_provider_account_id' => $account->id,
            'transaction_id' => null, 'scope' => 'customer', 'origin' => 'authoritative_fetch',
            'provider_reference_fingerprint' => hash('sha256', $rfiHashId), 'status' => 'requested',
            'evidence' => $this->providerRfi('RFI_REQUESTED', $templateName, $templateId, $rfiHashId),
            'response_draft' => [[
                'questionId' => 'document-review', 'answer' => 'Approved factual corporate evidence.',
                'provenance' => ['source' => 'human_supplied', 'reviewer_id' => 1, 'recorded_at' => now()->toISOString()],
            ]],
            'contract_gate' => 'provider_contract_unconfirmed', 'submission_state' => 'draft',
        ]);
        $contents = "%PDF-1.4\nSynthetic future Corporate RFI evidence\n%%EOF";
        $path = 'rfi/'.$customer->id.'/'.Str::uuid().'.pdf';
        Storage::disk('kyc_private')->put($path, $contents);
        $document = KycDocument::query()->create([
            'kyc_profile_id' => $profile->id, 'type' => 'corporate_rfi_supporting_document', 'status' => 'approved',
            'file_url' => 'private://'.$path, 'storage_disk' => 'kyc_private', 'file_path' => $path,
            'original_name' => 'synthetic-evidence.pdf', 'mime_type' => 'application/pdf',
            'file_size' => strlen($contents), 'file_hash' => hash('sha256', $contents),
            'metadata' => ['factual' => true, 'synthetic' => false, 'rfi_upload' => true],
        ]);
        $case->documents()->attach($document->id, ['attached_by' => $customer->id]);

        return [$case->fresh(), $account, $document];
    }

    private function providerRfi(
        string $status,
        string $templateName = 'otherDocument',
        ?string $templateId = null,
        ?string $rfiHashId = null,
    ): array {
        return [
            'rfiHashId' => $rfiHashId ?? $this->rfiHashId,
            'templateId' => $templateId ?? $this->templateId,
            'template' => [
                'name' => $templateName, 'documentType' => 'document', 'type' => 'document', 'rfiType' => 'corporate',
                'requiredFields' => [['fieldLabel' => 'Synthetic supporting document', 'fieldValue' => 'document', 'type' => 'document']],
            ],
            'remarks' => 'Provide synthetic factual evidence.', 'rfiStatus' => $status, 'status' => $status,
        ];
    }

    private function testContract(): array
    {
        $shape = ['template_name' => 'otherDocument', 'rfi_type' => 'corporate', 'field_value' => 'document', 'field_type' => 'document'];

        return [
            'environment' => app()->environment(),
            'endpoint' => '/api/v1/client/{clientHashId}/corporate/rfi', 'method' => 'POST',
            'request_schema_version' => 'synthetic-reviewed-request-v1',
            'contract_version' => 'synthetic-reviewed-response-v1',
            'template_fingerprint' => hash('sha256', $this->templateId),
            'required_field_shape_fingerprint' => hash('sha256', json_encode($shape, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        ];
    }

    private function fingerprints(NiumRfiCase $case): array
    {
        return [
            'provider_reference_fingerprint' => $case->provider_reference_fingerprint,
            'template_fingerprint' => hash('sha256', (string) data_get($case->evidence, 'templateId')),
        ];
    }

    private function admin(string $role): User
    {
        $admin = User::factory()->create();
        $admin->roles()->create(['role_code' => $role]);

        return $admin;
    }

    private function issueTokenFor(User $user): string
    {
        $plain = Str::random(80);
        ApiToken::query()->create([
            'user_id' => $user->id, 'name' => 'test', 'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDay(),
        ]);

        return $plain;
    }
}
