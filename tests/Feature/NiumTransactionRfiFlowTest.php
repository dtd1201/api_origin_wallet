<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\KycDocument;
use App\Models\NiumComplianceEvent;
use App\Models\NiumRfiCase;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumRfiWorkflowService;
use App\Services\Nium\NiumTransactionRfiManualReconciliationException;
use App\Services\Nium\NiumTransactionRfiOutcomePersister;
use App\Services\Nium\NiumTransactionRfiService;
use App\Services\Nium\NiumTransactionRfiSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class NiumTransactionRfiFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.nium.base_url', 'https://gateway.test.invalid');
        config()->set('services.nium.client_id', 'client-test');
        config()->set('services.nium.auth', ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'test-key']);
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'webhook-key');
        config()->set('services.nium.compliance_callback.static_header_name', 'x-partner-key');
        config()->set('services.nium.compliance_callback.static_header_value', 'callback-key');
        Http::preventStrayRequests();
    }

    public function test_official_callback_fetches_exact_transaction_and_does_not_post_or_trust_body(): void
    {
        [$provider, $user, $account, $transaction] = $this->transactionAccount('tx-official');
        Http::fake(['*' => Http::response($this->providerTransaction('tx-official', 'RFI_REQUESTED', [
            $this->providerRfi('rfi-hash-1', 'RFI_REQUESTED', ['firstName']),
        ]))]);

        $this->withHeader('x-partner-key', 'callback-key')
            ->postJson('/api/callbacks/nium/transaction-compliance?type=transaction&value=tx-official', [
                'complianceStatus' => 'CLEAR',
                'rfiDetails' => [['rfiHashId' => 'callback-is-not-authoritative']],
            ])->assertAccepted()->assertJsonPath('match_status', 'matched_transaction');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request['transactionId'] === 'tx-official'
            && $request['size'] === 20);
        $transaction->refresh();
        $this->assertSame('RFI_REQUESTED', $transaction->compliance_status);
        $this->assertSame('rfi-hash-1', data_get($transaction->raw_data, 'transaction_rfi.rfiDetails.0.rfiHashId'));
        $eventPayload = NiumComplianceEvent::query()->firstOrFail()->payload;
        $this->assertSame(['type' => 'TRANSACTION', 'value' => 'tx-official'], $eventPayload);
        $this->assertNull($account->fresh()->transactions_last_synced_at);
        $this->assertDatabaseHas('nium_rfi_cases', [
            'transaction_id' => $transaction->id,
            'scope' => 'transaction',
            'status' => 'requested',
        ]);
        $this->assertStringNotContainsString('callback-is-not-authoritative', json_encode($transaction->raw_data));
    }

    public function test_multiple_rfis_are_distinct_idempotent_and_responded_is_not_clear(): void
    {
        [$provider, $user, $account, $transaction] = $this->transactionAccount('tx-multi');
        $payload = $this->providerTransaction('tx-multi', 'RFI_REQUESTED', [
            $this->providerRfi('rfi-a', 'RFI_REQUESTED', ['firstName'], 'shared-rfi-id'),
            $this->providerRfi('rfi-b', 'RFI_RESPONDED', ['bankName'], 'shared-rfi-id'),
        ]);
        Http::fake(['*' => Http::response($payload)]);
        $service = app(NiumTransactionRfiService::class);
        $service->fetchAndReconcile($provider, 'tx-multi');
        $service->fetchAndReconcile($provider, 'tx-multi');

        $this->assertDatabaseCount('nium_rfi_cases', 2);
        $this->assertEqualsCanonicalizing(
            ['requested', 'responded_under_review'],
            NiumRfiCase::query()->where('transaction_id', $transaction->id)->pluck('status')->all(),
        );
    }

    public function test_missing_rfi_hash_id_fails_closed(): void
    {
        [$provider] = $this->transactionAccount('tx-no-hash');
        $rfi = $this->providerRfi('temporary', 'RFI_REQUESTED', ['bankName']);
        unset($rfi['rfiHashId']);
        Http::fake(['*' => Http::response($this->providerTransaction('tx-no-hash', 'RFI_REQUESTED', [$rfi]))]);

        $this->expectExceptionMessage('no factual rfiHashId');
        app(NiumTransactionRfiService::class)->fetchAndReconcile($provider, 'tx-no-hash');
    }

    public function test_clear_reject_pending_and_stale_requested_reconcile_conservatively(): void
    {
        [$provider, $user, $account, $transaction] = $this->transactionAccount('tx-state');
        $service = app(NiumTransactionRfiService::class);
        Http::fake(['*' => Http::sequence()
            ->push($this->providerTransaction('tx-state', 'RFI_REQUESTED', [$this->providerRfi('rfi-state', 'RFI_REQUESTED', ['firstName'])]))
            ->push($this->providerTransaction('tx-state', 'PENDING', [$this->providerRfi('rfi-state', 'RFI_RESPONDED', ['firstName'])]))
            ->push($this->providerTransaction('tx-state', 'RFI_REQUESTED', [$this->providerRfi('rfi-state', 'RFI_REQUESTED', ['firstName'])]))
            ->push($this->providerTransaction('tx-state', 'CLEAR'))
            ->push($this->providerTransaction('tx-state', 'REJECT'))]);

        $service->fetchAndReconcile($provider, 'tx-state');
        $case = NiumRfiCase::query()->firstOrFail();
        $service->fetchAndReconcile($provider, 'tx-state');
        $this->assertSame('responded_under_review', $case->fresh()->status);
        $this->assertSame('PENDING', $transaction->fresh()->compliance_status);
        $service->fetchAndReconcile($provider, 'tx-state');
        $this->assertSame('responded_under_review', $case->fresh()->status);
        $service->fetchAndReconcile($provider, 'tx-state');
        $this->assertSame('resolved_authoritative_clear', $case->fresh()->status);
        $service->fetchAndReconcile($provider, 'tx-state');
        $this->assertSame('resolved_authoritative_clear', $case->fresh()->status);
        $this->assertSame('REJECT', $transaction->fresh()->compliance_status);
    }

    public function test_missing_or_ambiguous_authoritative_target_fails_closed(): void
    {
        [$provider] = $this->transactionAccount('tx-target');
        Http::fake(['*' => Http::response(['transactions' => [
            $this->providerTransaction('tx-target', 'PENDING'),
            $this->providerTransaction('tx-target', 'PENDING'),
        ]])]);

        try {
            app(NiumTransactionRfiService::class)->fetchAndReconcile($provider, 'tx-target');
            $this->fail('Expected ambiguous provider response to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('missing or ambiguous', $exception->getMessage());
        }

        Http::fake();
        try {
            app(NiumTransactionRfiService::class)->fetchAndReconcile($provider, 'missing-local');
            $this->fail('Expected missing local target to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('one exact local transaction', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_approved_submission_uses_exact_contract_omits_empty_fields_and_posts_once(): void
    {
        [$provider, $user, $account, $transaction] = $this->transactionAccount('tx-submit');
        $case = $this->approvedCase($provider, $account, $transaction, 'rfi-submit', 'AUTH-123', [
            ['questionId' => 'firstName', 'answer' => 'Ada', 'provenance' => ['source' => 'human_supplied', 'reviewer_id' => $user->id]],
        ]);
        Http::fake(['*' => Http::response(['complianceId' => 'compliance-1', 'remarks' => 'Received', 'status' => 'RFI_RESPONDED'])]);

        $result = app(NiumTransactionRfiSubmissionService::class)->submit($case);
        $this->assertSame('responded', $result->submission_state);
        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->method() === 'POST'
                && str_contains($request->url(), '/transactions/AUTH-123/rfi/upload')
                && data_get($body, 'rfiResponseRequest.0.rfiHashId') === 'rfi-submit'
                && data_get($body, 'rfiResponseRequest.0.rfiResponseInfo.firstName') === 'Ada'
                && ! array_key_exists('middleName', data_get($body, 'rfiResponseRequest.0.rfiResponseInfo', []))
                && filled($request->header('x-request-id')[0] ?? null);
        });

        try {
            app(NiumTransactionRfiSubmissionService::class)->submit($case->fresh());
            $this->fail('Expected the claimed one-shot case to reject a second submission.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('separate human approval', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_official_bank_name_address_and_source_of_funds_mapping(): void
    {
        [$provider, $user, $account, $transaction] = $this->transactionAccount('tx-mapping');
        $rfi = $this->providerRfi('rfi-mapping', 'RFI_REQUESTED', ['bankName', 'addressLine1', 'city', 'sourceOfFunds']);
        $rfi['type'] = 'BANK_NAME';
        Http::fake(['*' => Http::sequence()
            ->push($this->providerTransaction('tx-mapping', 'RFI_REQUESTED', [$rfi]))
            ->push(['status' => 'RFI_RESPONDED'])]);
        app(NiumTransactionRfiService::class)->fetchAndReconcile($provider, 'tx-mapping');
        $case = NiumRfiCase::query()->firstOrFail();
        $this->assertSame('bankName', data_get($case->evidence, 'requiredData.0.value'));
        $workflow = app(NiumRfiWorkflowService::class);
        $workflow->saveFactualDraft($case, [
            ['questionId' => 'bankName', 'answer' => 'Example Bank'],
            ['questionId' => 'addressLine1', 'answer' => '1 Main Road'],
            ['questionId' => 'city', 'answer' => 'Singapore'],
            ['questionId' => 'sourceOfFunds', 'answer' => 'Business revenue'],
        ], [], $user->id);
        $workflow->approve($case->fresh(), $user->id);

        app(NiumTransactionRfiSubmissionService::class)->submit($case->fresh());

        Http::assertSent(function ($request): bool {
            $info = data_get($request->data(), 'rfiResponseRequest.0.rfiResponseInfo');

            return data_get($info, 'bankName') === 'Example Bank'
                && data_get($info, 'address.addressLine1') === '1 Main Road'
                && data_get($info, 'address.city') === 'Singapore'
                && ! data_get($info, 'address.addressLine2')
                && data_get($info, 'additionalInfo.sourceOfFunds') === 'Business revenue';
        });
    }

    public function test_repeated_query_nudges_refetch_and_reconcile_newer_responded_state(): void
    {
        [$provider, $user, $account, $transaction] = $this->transactionAccount('tx-repeat');
        Http::fake(['*' => Http::sequence()
            ->push($this->providerTransaction('tx-repeat', 'RFI_REQUESTED', [$this->providerRfi('rfi-repeat', 'RFI_REQUESTED', ['bankName'])]))
            ->push($this->providerTransaction('tx-repeat', 'PENDING', [$this->providerRfi('rfi-repeat', 'RFI_RESPONDED', ['bankName'])]))]);

        $url = '/api/callbacks/nium/transaction-compliance?type=TRANSACTION&value=tx-repeat';
        $this->withHeader('x-partner-key', 'callback-key')->postJson($url)->assertAccepted();
        $this->withHeader('x-partner-key', 'callback-key')->postJson($url)->assertAccepted();

        Http::assertSentCount(2);
        $this->assertDatabaseCount('nium_compliance_events', 2);
        $this->assertSame('responded_under_review', NiumRfiCase::query()->firstOrFail()->status);
        $this->assertSame('PENDING', $transaction->fresh()->compliance_status);
    }

    public function test_query_nudge_with_immutable_event_id_still_deduplicates_true_redelivery(): void
    {
        $this->transactionAccount('tx-delivery-duplicate');
        Http::fake(['*' => Http::response($this->providerTransaction('tx-delivery-duplicate', 'PENDING'))]);
        $url = '/api/callbacks/nium/transaction-compliance?type=TRANSACTION&value=tx-delivery-duplicate';
        $payload = ['eventId' => 'immutable-delivery-id'];

        $this->withHeader('x-partner-key', 'callback-key')->postJson($url, $payload)->assertAccepted()->assertJsonPath('duplicate', false);
        $this->withHeader('x-partner-key', 'callback-key')->postJson($url, $payload)->assertAccepted()->assertJsonPath('duplicate', true);

        Http::assertSentCount(1);
        $this->assertDatabaseCount('nium_compliance_events', 1);
    }

    public function test_uncertain_and_deterministic_failures_are_terminal_without_retry(): void
    {
        [$provider, $user, $account, $transaction] = $this->transactionAccount('tx-outcomes');
        Http::fake(['*' => Http::sequence()
            ->push(['message' => 'timeout'], 408)
            ->push(['message' => 'rate limited'], 429)
            ->push(['message' => 'later'], 503)
            ->push(['message' => 'invalid factual response'], 422)]);
        foreach ([408, 429, 503] as $statusCode) {
            $unknown = $this->approvedCase($provider, $account, $transaction, 'rfi-unknown-'.$statusCode, 'AUTH-'.$statusCode, [
                ['questionId' => 'firstName', 'answer' => 'Ada', 'provenance' => ['source' => 'human_supplied', 'reviewer_id' => $user->id]],
            ]);
            $this->assertSame('unknown', app(NiumTransactionRfiSubmissionService::class)->submit($unknown)->submission_state);
            $this->assertTrue((bool) data_get($unknown->fresh()->provider_response_evidence, 'manual_reconciliation_required'));
        }
        Http::assertSentCount(3);

        $rejected = $this->approvedCase($provider, $account, $transaction, 'rfi-rejected', 'AUTH-REJECTED', [
            ['questionId' => 'bankName', 'answer' => 'Example Bank', 'provenance' => ['source' => 'human_supplied', 'reviewer_id' => $user->id]],
        ]);
        $this->assertSame('rejected', app(NiumTransactionRfiSubmissionService::class)->submit($rejected)->submission_state);
        $this->assertSame('deterministic_rejection', data_get($rejected->fresh()->provider_response_evidence, 'outcome'));
        Http::assertSentCount(4);
    }

    public function test_transaction_draft_requires_human_requested_fields_and_rejects_document_data(): void
    {
        [$provider, $user, $account, $transaction] = $this->transactionAccount('tx-draft');
        $case = NiumRfiCase::query()->create([
            'provider_id' => $provider->id, 'user_provider_account_id' => $account->id,
            'transaction_id' => $transaction->id, 'scope' => 'transaction',
            'provider_reference_fingerprint' => hash('sha256', 'rfi-draft'), 'status' => 'requested',
            'evidence' => ['rfiHashId' => 'rfi-draft', 'authCode' => 'AUTH-DRAFT', 'requiredData' => [['label' => 'First name', 'value' => 'firstName', 'type' => 'TEXT']]],
        ]);
        $workflow = app(NiumRfiWorkflowService::class);
        $draft = $workflow->saveFactualDraft($case, [['questionId' => 'firstName', 'answer' => 'Ada']], [], $user->id);
        $this->assertSame('draft', $draft->submission_state);
        try {
            $workflow->saveFactualDraft($case, [['questionId' => 'lastName', 'answer' => 'Lovelace']], [], $user->id);
            $this->fail('Expected an unrequested field to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('unrequested', $exception->getMessage());
        }
        try {
            $workflow->saveFactualDraft($case, [['questionId' => 'firstName', 'answer' => ['content' => base64_encode('document')]]], [], $user->id);
            $this->fail('Expected encoded document data to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('document data', $exception->getMessage());
        }
        $this->assertStringNotContainsString(base64_encode('document'), json_encode($case->fresh()->response_draft));
    }

    public function test_document_rfi_encodes_only_at_final_post_and_never_persists_base64(): void
    {
        Storage::fake('kyc_private');
        [$provider, $user, $account, $transaction] = $this->transactionAccount('tx-document');
        $contents = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";
        $document = $this->document($user, 'identity.pdf', 'application/pdf', $contents);
        $case = NiumRfiCase::query()->create([
            'provider_id' => $provider->id, 'user_provider_account_id' => $account->id,
            'transaction_id' => $transaction->id, 'scope' => 'transaction',
            'provider_reference_fingerprint' => hash('sha256', 'rfi-document'), 'status' => 'requested',
            'evidence' => ['rfiHashId' => 'rfi-document', 'authCode' => 'AUTH-DOCUMENT', 'type' => 'DOCUMENT',
                'documentType' => 'PASSPORT', 'requiredData' => [
                    ['label' => 'Identification type', 'value' => 'identificationType', 'type' => 'TEXT'],
                    ['label' => 'Document', 'value' => 'identificationDocument', 'type' => 'DOCUMENT'],
                ]],
        ]);
        $workflow = app(NiumRfiWorkflowService::class);
        $workflow->saveFactualDraft($case, [['questionId' => 'identificationType', 'answer' => 'PASSPORT']], [(string) $document->id], $user->id);
        $workflow->approve($case->fresh(), $user->id);
        Http::fake(['*' => Http::response(['status' => 'RFI_RESPONDED'])]);

        app(NiumTransactionRfiSubmissionService::class)->submit($case->fresh());
        $encoded = base64_encode($contents);
        Http::assertSent(fn ($request): bool => data_get($request->data(), 'rfiResponseRequest.0.rfiResponseInfo.identificationDoc.identificationType') === 'PASSPORT'
            && data_get($request->data(), 'rfiResponseRequest.0.rfiResponseInfo.identificationDoc.identificationDocument.0.fileName') === 'identity.pdf'
            && data_get($request->data(), 'rfiResponseRequest.0.rfiResponseInfo.identificationDoc.identificationDocument.0.fileType') === 'application/pdf'
            && data_get($request->data(), 'rfiResponseRequest.0.rfiResponseInfo.identificationDoc.identificationDocument.0.document') === $encoded);
        $this->assertStringNotContainsString($encoded, NiumRfiCase::query()->whereKey($case->id)->firstOrFail()->toJson());
        $this->assertStringNotContainsString($encoded, ApiRequestLog::query()->get()->toJson());
    }

    public function test_identification_type_only_rfi_sends_only_identification_type(): void
    {
        [$provider, $user, $account, $transaction] = $this->transactionAccount('tx-identification-type');
        $case = $this->approvedCase($provider, $account, $transaction, 'rfi-identification-type', 'AUTH-TYPE', [
            $this->answer('identificationType', 'PASSPORT', $user),
        ]);
        Http::fake(['*' => Http::response(['status' => 'RFI_RESPONDED'])]);

        app(NiumTransactionRfiSubmissionService::class)->submit($case);

        Http::assertSent(function ($request): bool {
            $identificationDoc = data_get($request->data(), 'rfiResponseRequest.0.rfiResponseInfo.identificationDoc');

            return $identificationDoc === ['identificationType' => 'PASSPORT'];
        });
    }

    public function test_salary_statement_shape_sends_only_current_required_identification_fields(): void
    {
        Storage::fake('kyc_private');
        [$provider, $user, $account, $transaction] = $this->transactionAccount('tx-salary-statement');
        $contents = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";
        $document = $this->document($user, 'salary-statement.pdf', 'application/pdf', $contents);
        $case = NiumRfiCase::query()->create([
            'provider_id' => $provider->id, 'user_provider_account_id' => $account->id,
            'transaction_id' => $transaction->id, 'scope' => 'transaction',
            'provider_reference_fingerprint' => hash('sha256', 'rfi-salary-statement'), 'status' => 'requested',
            'evidence' => ['rfiHashId' => 'rfi-salary-statement', 'authCode' => 'AUTH-SALARY', 'requiredData' => [
                ['label' => 'Salary Statement Document', 'value' => 'identificationDocument', 'type' => 'document'],
                ['label' => 'Salary Statement Generated on', 'value' => 'identificationIssuingDate', 'type' => 'data'],
                ['label' => 'Salary Statement Issuer', 'value' => 'identificationDocIssuingAuthority', 'type' => 'data'],
            ]],
        ]);
        $workflow = app(NiumRfiWorkflowService::class);
        $workflow->saveFactualDraft($case, [
            ['questionId' => 'identificationIssuingDate', 'answer' => '2026-08-01'],
            ['questionId' => 'identificationDocIssuingAuthority', 'answer' => 'Example Employer'],
        ], [(string) $document->id], $user->id);
        $workflow->approve($case->fresh(), $user->id);
        Http::fake(['*' => Http::response(['status' => 'RFI_RESPONDED'])]);

        app(NiumTransactionRfiSubmissionService::class)->submit($case->fresh());

        Http::assertSent(function ($request) use ($contents): bool {
            $identificationDoc = data_get($request->data(), 'rfiResponseRequest.0.rfiResponseInfo.identificationDoc');

            return array_keys($identificationDoc) === [
                'identificationIssuingDate', 'identificationDocIssuingAuthority', 'identificationDocument',
            ]
                && ! array_key_exists('identificationType', $identificationDoc)
                && ! array_key_exists('identificationValue', $identificationDoc)
                && $identificationDoc['identificationIssuingDate'] === '2026-08-01'
                && $identificationDoc['identificationDocIssuingAuthority'] === 'Example Employer'
                && $identificationDoc['identificationDocument'][0] === [
                    'fileName' => 'salary-statement.pdf',
                    'fileType' => 'application/pdf',
                    'document' => base64_encode($contents),
                ];
        });
    }

    public function test_requested_document_field_requires_an_approved_factual_document(): void
    {
        [$provider, $user, $account, $transaction] = $this->transactionAccount('tx-required-document');
        $case = $this->documentCase($provider, $account, $transaction, 'required');

        try {
            app(NiumRfiWorkflowService::class)->saveFactualDraft(
                $case,
                [['questionId' => 'identificationType', 'answer' => 'PASSPORT']],
                [],
                $user->id,
            );
            $this->fail('Expected the requested document to be required.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('approved factual supporting document', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_document_limits_and_unsupported_mime_are_enforced_before_post(): void
    {
        Storage::fake('kyc_private');
        [$provider, $user, $account, $transaction] = $this->transactionAccount('tx-document-limits');
        $case = $this->documentCase($provider, $account, $transaction, 'limits');
        $workflow = app(NiumRfiWorkflowService::class);
        $documents = [];
        foreach (range(1, 5) as $index) {
            $documents[] = $this->document($user, "doc-{$index}.pdf", 'application/pdf', 'pdf-'.$index);
        }
        try {
            $workflow->saveFactualDraft($case, [['questionId' => 'identificationType', 'answer' => 'PASSPORT']], array_map(fn ($doc) => (string) $doc->id, $documents), $user->id);
            $this->fail('Expected max four documents enforcement.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('at most four', $exception->getMessage());
        }

        $tooLarge = $this->document($user, 'large.pdf', 'application/pdf', 'x', 2 * 1024 * 1024 + 1);
        try {
            $workflow->saveFactualDraft($case, [['questionId' => 'identificationType', 'answer' => 'PASSPORT']], [(string) $tooLarge->id], $user->id);
            $this->fail('Expected per-file size enforcement.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not approved factual owned evidence', $exception->getMessage());
        }

        $unsupported = $this->document($user, 'identity.gif', 'image/gif', 'gif');
        try {
            $workflow->saveFactualDraft($case, [['questionId' => 'identificationType', 'answer' => 'PASSPORT']], [(string) $unsupported->id], $user->id);
            $this->fail('Expected MIME enforcement.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not approved factual owned evidence', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_four_maximum_size_files_keep_combined_original_bytes_below_ten_mb(): void
    {
        Storage::fake('kyc_private');
        [$provider, $user, $account, $transaction] = $this->transactionAccount('tx-document-total');
        $case = $this->documentCase($provider, $account, $transaction, 'total');
        $documentIds = [];
        foreach (range(1, 4) as $index) {
            $prefix = "%PDF-1.4\n";
            $suffix = "\n%%EOF";
            $contents = $prefix.str_repeat('x', 2 * 1024 * 1024 - strlen($prefix) - strlen($suffix)).$suffix;
            $documentIds[] = (string) $this->document($user, "maximum-{$index}.pdf", 'application/pdf', $contents)->id;
        }
        $workflow = app(NiumRfiWorkflowService::class);
        $workflow->saveFactualDraft($case, [['questionId' => 'identificationType', 'answer' => 'PASSPORT']], $documentIds, $user->id);
        $workflow->approve($case->fresh(), $user->id);
        Http::fake(['*' => Http::response(['status' => 'RFI_RESPONDED'])]);

        app(NiumTransactionRfiSubmissionService::class)->submit($case->fresh());

        Http::assertSent(fn ($request): bool => count((array) data_get(
            $request->data(),
            'rfiResponseRequest.0.rfiResponseInfo.identificationDoc.identificationDocument',
        )) === 4);
    }

    public function test_connection_timeout_is_unknown_and_never_retried(): void
    {
        [$provider, $user, $account, $transaction] = $this->transactionAccount('tx-timeout');
        $case = $this->approvedCase($provider, $account, $transaction, 'rfi-timeout', 'AUTH-TIMEOUT', [
            ['questionId' => 'firstName', 'answer' => 'Ada', 'provenance' => ['source' => 'human_supplied', 'reviewer_id' => $user->id]],
        ]);
        Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out')]);

        $result = app(NiumTransactionRfiSubmissionService::class)->submit($case);
        $this->assertSame('unknown', $result->submission_state);
        $this->assertSame('UNKNOWN', data_get($result->provider_response_evidence, 'outcome'));
        Http::assertSentCount(1);
    }

    public function test_malformed_success_response_is_unknown_and_cannot_be_reposted(): void
    {
        [$provider, $user, $account, $transaction] = $this->transactionAccount('tx-malformed');
        $case = $this->approvedCase($provider, $account, $transaction, 'rfi-malformed', 'AUTH-MALFORMED', [
            ['questionId' => 'bankName', 'answer' => 'Example Bank', 'provenance' => ['source' => 'human_supplied', 'reviewer_id' => $user->id]],
        ]);
        Http::fake(['*' => Http::response('not-json', 200, ['Content-Type' => 'text/plain'])]);

        $result = app(NiumTransactionRfiSubmissionService::class)->submit($case);
        $this->assertSame('unknown', $result->submission_state);
        $this->assertTrue((bool) data_get($result->provider_response_evidence, 'manual_reconciliation_required'));
        try {
            app(NiumTransactionRfiSubmissionService::class)->submit($result);
            $this->fail('Expected malformed-response case to remain one-shot.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('separate human approval', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_only_rfi_responded_is_accepted_as_successful_provider_state(): void
    {
        [$provider, $user, $account, $transaction] = $this->transactionAccount('tx-success-states');
        Http::fake(['*' => Http::sequence()
            ->push(['status' => 'PENDING', 'complianceId' => 'pending-id', 'remarks' => 'Still pending'])
            ->push(['status' => 'RFI_REQUESTED'])
            ->push(['status' => 'UNEXPECTED'])
            ->push(['complianceId' => 'missing-status-id', 'remarks' => 'No state'])]);

        foreach (['pending', 'requested', 'unexpected', 'missing'] as $suffix) {
            $case = $this->approvedCase($provider, $account, $transaction, 'rfi-'.$suffix, 'AUTH-'.$suffix, [
                ['questionId' => 'bankName', 'answer' => 'Example Bank', 'provenance' => ['source' => 'human_supplied', 'reviewer_id' => $user->id]],
            ]);
            $result = app(NiumTransactionRfiSubmissionService::class)->submit($case);
            $this->assertSame('unknown', $result->submission_state);
            $this->assertTrue((bool) data_get($result->provider_response_evidence, 'manual_reconciliation_required'));
            try {
                app(NiumTransactionRfiSubmissionService::class)->submit($result);
                $this->fail('Expected uncertain provider success state to remain one-shot.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('separate human approval', $exception->getMessage());
            }
        }
        Http::assertSentCount(4);
        $this->assertSame('PENDING', data_get(
            NiumRfiCase::query()->where('provider_reference_fingerprint', hash('sha256', 'rfi-pending'))->firstOrFail()->provider_response_evidence,
            'status',
        ));
        $this->assertSame('pending-id', data_get(
            NiumRfiCase::query()->where('provider_reference_fingerprint', hash('sha256', 'rfi-pending'))->firstOrFail()->provider_response_evidence,
            'compliance_id',
        ));
    }

    public function test_provider_success_followed_by_final_persistence_failure_stays_claimed_and_cannot_repost(): void
    {
        [$provider, $user, $account, $transaction] = $this->transactionAccount('tx-persist-failure');
        $case = $this->approvedCase($provider, $account, $transaction, 'rfi-persist-failure', 'AUTH-PERSIST', [
            ['questionId' => 'bankName', 'answer' => 'Example Bank', 'provenance' => ['source' => 'human_supplied', 'reviewer_id' => $user->id]],
        ]);
        $this->mock(NiumTransactionRfiOutcomePersister::class, function (MockInterface $mock): void {
            $mock->shouldReceive('persistClaimedOutcome')->once()->andThrow(new RuntimeException('simulated final persistence failure'));
        });
        Http::fake(['*' => Http::response(['status' => 'RFI_RESPONDED', 'complianceId' => 'provider-success'])]);

        try {
            app(NiumTransactionRfiSubmissionService::class)->submit($case);
            $this->fail('Expected manual reconciliation exception.');
        } catch (NiumTransactionRfiManualReconciliationException $exception) {
            $this->assertTrue($exception->safeEvidence['manual_reconciliation_required']);
            $this->assertNotEmpty($exception->safeEvidence['x_request_id']);
        }

        $claimed = $case->fresh();
        $this->assertSame('claimed', $claimed->submission_state);
        $this->assertNotEmpty(data_get($claimed->provider_response_evidence, 'x_request_id'));
        $this->assertNotEmpty(data_get($claimed->provider_response_evidence, 'request_correlation_fingerprint'));
        try {
            app(NiumTransactionRfiSubmissionService::class)->submit($claimed);
            $this->fail('Expected claimed case to block a second provider POST.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('separate human approval', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_zero_row_final_outcome_update_stays_claimed_and_cannot_repost(): void
    {
        [$provider, $user, $account, $transaction] = $this->transactionAccount('tx-zero-update');
        $case = $this->approvedCase($provider, $account, $transaction, 'rfi-zero-update', 'AUTH-ZERO', [
            ['questionId' => 'bankName', 'answer' => 'Example Bank', 'provenance' => ['source' => 'human_supplied', 'reviewer_id' => $user->id]],
        ]);
        $this->mock(NiumTransactionRfiOutcomePersister::class, function (MockInterface $mock): void {
            $mock->shouldReceive('persistClaimedOutcome')->once()->andReturn(0);
        });
        Http::fake(['*' => Http::response(['status' => 'RFI_RESPONDED'])]);

        $this->expectManualReconciliationFailure($case);
        $claimed = $case->fresh();
        $this->assertSame('claimed', $claimed->submission_state);
        $this->assertNotEmpty(data_get($claimed->provider_response_evidence, 'x_request_id'));
        try {
            app(NiumTransactionRfiSubmissionService::class)->submit($claimed);
            $this->fail('Expected zero-row outcome case to block a second provider POST.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('separate human approval', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    private function transactionAccount(string $transactionId): array
    {
        $provider = IntegrationProvider::query()->create(['code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        $user = User::factory()->create();
        $account = UserProviderAccount::query()->create([
            'user_id' => $user->id, 'provider_id' => $provider->id,
            'external_customer_id' => 'customer-test', 'external_account_id' => 'wallet-test', 'status' => 'active',
        ]);
        $transaction = Transaction::query()->create([
            'user_id' => $user->id, 'provider_id' => $provider->id,
            'external_transaction_id' => $transactionId, 'currency' => 'USD', 'amount' => 10, 'status' => 'pending',
        ]);

        return [$provider, $user, $account, $transaction];
    }

    private function expectManualReconciliationFailure(NiumRfiCase $case): void
    {
        try {
            app(NiumTransactionRfiSubmissionService::class)->submit($case);
            $this->fail('Expected manual reconciliation exception.');
        } catch (NiumTransactionRfiManualReconciliationException $exception) {
            $this->assertTrue($exception->safeEvidence['manual_reconciliation_required']);
            $this->assertNotEmpty($exception->safeEvidence['request_correlation_fingerprint']);
        }
    }

    private function providerTransaction(string $transactionId, string $status, array $rfis = []): array
    {
        return ['transactionId' => $transactionId, 'authCode' => 'AUTH-'.$transactionId,
            'systemReferenceNumber' => 'SYS-'.$transactionId, 'complianceStatus' => $status, 'rfiDetails' => $rfis];
    }

    private function providerRfi(string $hash, string $status, array $fields, ?string $rfiId = null): array
    {
        return ['rfiHashId' => $hash, 'rfiId' => $rfiId ?? 'id-'.$hash, 'rfiStatus' => $status,
            'description' => 'Provide requested transaction information', 'mandatory' => true,
            'transactionEntityType' => 'TRANSACTION', 'type' => 'INFORMATION', 'documentType' => null, 'remarks' => 'Required by compliance',
            'requiredData' => array_map(static fn (string $field): array => ['label' => $field, 'value' => $field, 'type' => 'TEXT'], $fields)];
    }

    private function approvedCase(IntegrationProvider $provider, UserProviderAccount $account, Transaction $transaction, string $hash, string $authCode, array $answers): NiumRfiCase
    {
        return NiumRfiCase::query()->create([
            'provider_id' => $provider->id, 'user_provider_account_id' => $account->id,
            'transaction_id' => $transaction->id, 'scope' => 'transaction',
            'provider_reference_fingerprint' => hash('sha256', $hash), 'status' => 'requested',
            'evidence' => ['rfiHashId' => $hash, 'authCode' => $authCode, 'requiredData' => array_map(
                static fn (array $answer): array => ['label' => $answer['questionId'], 'value' => $answer['questionId'], 'type' => 'TEXT'],
                $answers,
            )],
            'response_draft' => $answers, 'submission_state' => 'approved',
            'approved_by' => $account->user_id, 'approved_at' => now(),
            'contract_gate' => 'official_transaction_rfi_v1',
        ]);
    }

    private function answer(string $field, mixed $value, User $user): array
    {
        return ['questionId' => $field, 'answer' => $value,
            'provenance' => ['source' => 'human_supplied', 'reviewer_id' => $user->id]];
    }

    private function document(User $user, string $name, string $mime, string $contents, ?int $declaredSize = null): KycDocument
    {
        $profile = $user->kycProfile()->firstOrCreate([], [
            'status' => 'approved', 'applicant_type' => 'individual', 'legal_name' => 'Factual Document Owner',
            'address_line1' => '1 Test Road', 'city' => 'Singapore', 'country_code' => 'SG',
        ]);
        $path = 'rfi/'.$user->id.'/'.$name;
        Storage::disk('kyc_private')->put($path, $contents);

        return KycDocument::query()->create([
            'kyc_profile_id' => $profile->id, 'type' => 'passport_front', 'status' => 'approved',
            'file_url' => 'private://'.$path, 'storage_disk' => 'kyc_private', 'file_path' => $path,
            'original_name' => $name, 'mime_type' => $mime, 'file_size' => $declaredSize ?? strlen($contents),
            'file_hash' => hash('sha256', $contents), 'metadata' => ['factual' => true, 'synthetic' => false],
        ]);
    }

    private function documentCase(IntegrationProvider $provider, UserProviderAccount $account, Transaction $transaction, string $suffix): NiumRfiCase
    {
        return NiumRfiCase::query()->create([
            'provider_id' => $provider->id, 'user_provider_account_id' => $account->id,
            'transaction_id' => $transaction->id, 'scope' => 'transaction',
            'provider_reference_fingerprint' => hash('sha256', 'rfi-document-'.$suffix), 'status' => 'requested',
            'evidence' => ['rfiHashId' => 'rfi-document-'.$suffix, 'authCode' => 'AUTH-DOCUMENT', 'type' => 'DOCUMENT',
                'documentType' => 'PASSPORT', 'requiredData' => [
                    ['label' => 'Identification type', 'value' => 'identificationType', 'type' => 'TEXT'],
                    ['label' => 'Document', 'value' => 'identificationDocument', 'type' => 'DOCUMENT'],
                ]],
        ]);
    }
}
