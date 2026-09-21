<?php

namespace Tests\Feature;

use App\Models\IntegrationProvider;
use App\Models\KycDocument;
use App\Models\NiumRfiCase;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumCorporateRfiSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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
            'submission_state' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        return [$case, $account, $user, $provider];
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
}
