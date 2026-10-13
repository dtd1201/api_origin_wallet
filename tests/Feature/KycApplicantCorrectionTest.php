<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\IntegrationProvider;
use App\Models\KycProfileCorrection;
use App\Models\KycDocumentUpload;
use App\Models\User;
use App\Models\UserProviderAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class KycApplicantCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_eligible_customer_can_submit_encrypted_correction_without_mutating_approved_records_or_calling_nium(): void
    {
        Http::preventStrayRequests();
        $context = $this->context();

        $state = $this->withToken($this->issueTokenFor($context['user']))
            ->getJson($this->endpoint($context['user']))
            ->assertOk()->assertJsonPath('eligible', true)->json();

        $this->withToken($this->issueTokenFor($context['user']))
            ->postJson($this->endpoint($context['user']), [
                'correction_version' => $state['correction_version'],
                'source_fingerprint' => $state['source_fingerprint'],
                'legal_name' => 'NGUYEN VAN THANH PHUONG',
                'first_name' => 'THANH PHUONG',
                'last_name' => 'NGUYEN VAN',
                'date_of_birth' => '1991-02-03',
            ])->assertCreated()
            ->assertJsonPath('correction.status', 'submitted')
            ->assertJsonPath('correction.proposed_values.first_name', 'THANH PHUONG');

        $applicant = $context['applicant']->fresh();
        $this->assertSame('OLD LEGAL NAME', $applicant->legal_name);
        $this->assertSame('OLD GIVEN', $applicant->first_name);
        $this->assertSame('OLD SURNAME', $applicant->last_name);
        $this->assertSame('submitted', $context['submission']->fresh()->status);
        $this->assertSame($context['customer_hash_id'], $context['account']->fresh()->external_customer_id);
        $this->assertSame($context['reference_id'], data_get($context['account']->fresh()->metadata, 'nium_entity_kyc_states.ref_applicant.provider_reference_id'));

        $correction = KycProfileCorrection::query()->sole();
        $raw = (array) \DB::table('kyc_profile_corrections')->where('id', $correction->id)->first();
        $this->assertStringNotContainsString('THANH PHUONG', (string) $raw['proposed_values']);
        $this->assertStringNotContainsString($context['reference_id'], (string) $raw['applicant_reference_id']);
        $audit = AuditLog::query()->where('action', 'kyc.correction_submitted')->sole();
        $this->assertStringNotContainsString('THANH PHUONG', json_encode($audit->new_data, JSON_THROW_ON_ERROR));
        Http::assertNothingSent();
    }

    public function test_other_user_cannot_read_or_submit_correction(): void
    {
        $context = $this->context();
        $other = User::factory()->create();

        $this->withToken($this->issueTokenFor($other))->getJson($this->endpoint($context['user']))->assertForbidden();
        $this->withToken($this->issueTokenFor($other))->postJson($this->endpoint($context['user']), [])->assertForbidden();
    }

    public function test_stale_version_and_concurrent_active_correction_are_rejected(): void
    {
        $context = $this->context();
        $token = $this->issueTokenFor($context['user']);
        $state = $this->withToken($token)->getJson($this->endpoint($context['user']))->json();
        $payload = [
            'correction_version' => $state['correction_version'],
            'source_fingerprint' => $state['source_fingerprint'],
            'legal_name' => 'NEW LEGAL NAME',
            'first_name' => 'NEW GIVEN',
            'last_name' => 'NEW SURNAME',
            'date_of_birth' => '1990-01-01',
        ];

        $this->withToken($token)->postJson($this->endpoint($context['user']), [
            ...$payload,
            'correction_version' => 99,
        ])->assertUnprocessable()->assertJsonValidationErrors('correction_version');

        $this->withToken($token)->postJson($this->endpoint($context['user']), $payload)->assertCreated();
        $this->withToken($token)->postJson($this->endpoint($context['user']), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('correction');
        $this->assertDatabaseCount('kyc_profile_corrections', 1);
    }

    public function test_invalid_or_out_of_scope_payload_is_rejected(): void
    {
        $context = $this->context();
        $token = $this->issueTokenFor($context['user']);
        $state = $this->withToken($token)->getJson($this->endpoint($context['user']))->json();

        $this->withToken($token)->postJson($this->endpoint($context['user']), [
            'correction_version' => $state['correction_version'],
            'source_fingerprint' => $state['source_fingerprint'],
            'legal_name' => 'NAME',
            'first_name' => '',
            'last_name' => 'SURNAME',
            'date_of_birth' => now()->addDay()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['first_name', 'date_of_birth']);

        $this->withToken($token)->postJson($this->endpoint($context['user']), [
            'correction_version' => $state['correction_version'],
            'source_fingerprint' => $state['source_fingerprint'],
            'legal_name' => 'NAME',
            'first_name' => '',
            'last_name' => 'SURNAME',
            'date_of_birth' => now()->addDay()->toDateString(),
            'nationality_country_code' => 'VN',
        ])->assertUnprocessable()->assertJsonValidationErrors('correction');

        $context['account']->update(['provider_sub_status' => 'under_review']);
        $this->withToken($token)->getJson($this->endpoint($context['user']))
            ->assertOk()->assertJsonPath('eligible', false);
    }

    public function test_user_sees_request_changes_reason_and_can_submit_the_next_version_without_losing_history(): void
    {
        $context = $this->context();
        $token = $this->issueTokenFor($context['user']);
        $state = $this->withToken($token)->getJson($this->endpoint($context['user']))->json();
        $payload = [
            'correction_version' => $state['correction_version'],
            'source_fingerprint' => $state['source_fingerprint'],
            'legal_name' => 'FIRST PROPOSAL', 'first_name' => 'FIRST', 'last_name' => 'PROPOSAL',
            'date_of_birth' => '1991-02-03',
        ];
        $this->withToken($token)->postJson($this->endpoint($context['user']), $payload)->assertCreated();
        $first = KycProfileCorrection::query()->sole();
        $first->update([
            'status' => 'needs_more_info', 'active_slot' => null,
            'review_note' => 'Surname must match the passport machine-readable page.', 'resolved_at' => now(),
        ]);

        $next = $this->withToken($token)->getJson($this->endpoint($context['user']))
            ->assertOk()
            ->assertJsonPath('eligible', true)
            ->assertJsonPath('latest_resolved_correction.status', 'needs_more_info')
            ->assertJsonPath('latest_resolved_correction.review_note', 'Surname must match the passport machine-readable page.')
            ->assertJsonPath('correction_version', 2)
            ->json();

        $this->withToken($token)->postJson($this->endpoint($context['user']), [
            'correction_version' => $next['correction_version'],
            'source_fingerprint' => $next['source_fingerprint'],
            'legal_name' => 'SECOND PROPOSAL', 'first_name' => 'SECOND', 'last_name' => 'PROPOSAL',
            'date_of_birth' => '1991-02-03',
        ])->assertCreated()->assertJsonPath('correction.correction_version', 2);

        $this->assertDatabaseCount('kyc_profile_corrections', 2);
        $this->assertSame('needs_more_info', $first->fresh()->status);
    }

    public function test_admin_scoped_request_allows_only_requested_fields_and_passport_without_mutating_live_kyc(): void
    {
        Http::preventStrayRequests();
        Storage::fake('kyc_private');
        $context = $this->context();
        $admin = User::factory()->create();
        $admin->roles()->create(['role_code' => 'admin']);
        $adminToken = $this->issueTokenFor($admin);

        $created = $this->withToken($adminToken)->postJson(
            "/api/admin/users/{$context['user']->id}/kyc-profile/corrections/request-update",
            ['subject' => 'applicant', 'fields' => ['first_name', 'last_name'], 'document_types' => ['passport'], 'instruction' => 'Correct the passport name and upload the passport.'],
        )->assertCreated()->assertJsonPath('correction.status', 'requested')->json('correction');

        $this->withToken($adminToken)->postJson(
            "/api/admin/users/{$context['user']->id}/kyc-profile/corrections/request-update",
            ['subject' => 'applicant', 'fields' => ['first_name'], 'instruction' => 'Duplicate'],
        )->assertUnprocessable()->assertJsonValidationErrors('correction');

        $token = $this->issueTokenFor($context['user']);
        $state = $this->withToken($token)->getJson($this->endpoint($context['user']))
            ->assertOk()->assertJsonPath('correction.request_instruction', 'Correct the passport name and upload the passport.')
            ->assertJsonPath('correction.requested_document_types.0', 'passport')->json();

        $documentContents = "%PDF-1.4\npassport\n%%EOF";
        $documentHash = hash('sha256', $documentContents);
        $documentPath = "kyc/{$context['user']->id}/documents/{$documentHash}-passport.pdf";
        Storage::disk('kyc_private')->put($documentPath, $documentContents);
        $this->registerUpload($context['user'], $documentPath, $documentHash, $documentContents);
        $payload = [
            'correction_id' => $created['id'], 'correction_version' => $state['correction_version'],
            'source_fingerprint' => $state['source_fingerprint'],
            'legal_name' => 'CHANGED OUTSIDE SCOPE', 'first_name' => 'NEW GIVEN',
            'last_name' => 'NEW SURNAME', 'date_of_birth' => '1990-01-01',
            'documents' => [[
                'type' => 'passport', 'file_url' => 'https://api.example.test/api/kyc-documents/1/hash',
                'storage_disk' => 'kyc_private', 'file_path' => $documentPath,
                'file_hash' => $documentHash, 'mime_type' => 'application/pdf', 'file_size' => strlen($documentContents),
            ]],
        ];
        $this->withToken($token)->postJson($this->endpoint($context['user']), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('fields');

        $payload['legal_name'] = 'OLD LEGAL NAME';
        $this->withToken($token)->postJson($this->endpoint($context['user']), $payload)
            ->assertCreated()->assertJsonPath('correction.status', 'submitted');

        $this->assertSame('OLD GIVEN', $context['applicant']->fresh()->first_name);
        $this->assertSame('submitted', $context['submission']->fresh()->status);
        $this->assertDatabaseCount('kyc_documents', 0);
        $raw = (array) \DB::table('kyc_profile_corrections')->where('id', $created['id'])->first();
        $this->assertStringNotContainsString($documentPath, (string) $raw['proposed_documents']);
        Http::assertNothingSent();
    }

    public function test_admin_request_scope_supports_single_fields_document_only_and_combined_requests(): void
    {
        Storage::fake('kyc_private');

        foreach ([
            [['date_of_birth'], [], ['date_of_birth' => '1991-02-03']],
            [['first_name'], [], ['first_name' => 'NEW GIVEN']],
            [['last_name'], [], ['last_name' => 'NEW SURNAME']],
            [[], ['passport'], []],
            [['first_name', 'last_name', 'date_of_birth'], ['passport'], [
                'first_name' => 'NEW GIVEN', 'last_name' => 'NEW SURNAME', 'date_of_birth' => '1991-02-03',
            ]],
        ] as [$fields, $documentTypes, $values]) {
            $context = $this->context();
            $admin = User::factory()->create();
            $admin->roles()->create(['role_code' => 'admin']);
            $created = $this->withToken($this->issueTokenFor($admin))->postJson(
                "/api/admin/users/{$context['user']->id}/kyc-profile/corrections/request-update",
                ['subject' => 'applicant', 'fields' => $fields, 'document_types' => $documentTypes, 'instruction' => 'Provide only the requested data.'],
            )->assertCreated()->json('correction');

            $state = $this->withToken($this->issueTokenFor($context['user']))
                ->getJson($this->endpoint($context['user']))->assertOk()->json();
            $payload = [
                'correction_id' => $created['id'],
                'correction_version' => $state['correction_version'],
                'source_fingerprint' => $state['source_fingerprint'],
                ...$values,
            ];
            if ($documentTypes !== []) {
                $contents = "%PDF-1.4\npassport-{$context['user']->id}\n%%EOF";
                $hash = hash('sha256', $contents);
                $path = "kyc/{$context['user']->id}/documents/{$hash}-passport.pdf";
                Storage::disk('kyc_private')->put($path, $contents);
                $this->registerUpload($context['user'], $path, $hash, $contents);
                $payload['documents'] = [[
                    'type' => 'passport', 'file_url' => "https://api.example.test/api/kyc-documents/{$context['user']->id}/{$hash}",
                    'storage_disk' => 'kyc_private', 'file_path' => $path, 'file_hash' => $hash,
                    'mime_type' => 'application/pdf', 'file_size' => strlen($contents),
                ]];
            }

            $this->withToken($this->issueTokenFor($context['user']))
                ->postJson($this->endpoint($context['user']), $payload)
                ->assertCreated()->assertJsonPath('correction.status', 'submitted');
            if ($documentTypes !== []) {
                $documentEndpoint = "/api/kyc-documents/{$context['user']->id}/{$hash}";
                $documentResponse = $this->withToken($this->issueTokenFor($admin))->get($documentEndpoint)->assertOk();
                $this->assertStringContainsString('private', (string) $documentResponse->headers->get('Cache-Control'));
                $this->assertStringContainsString('no-store', (string) $documentResponse->headers->get('Cache-Control'));
                $other = User::factory()->create();
                $this->withToken($this->issueTokenFor($other))->get($documentEndpoint)->assertForbidden();
                $financeAdmin = User::factory()->create();
                $financeAdmin->roles()->create(['role_code' => 'finance_operator']);
                $this->withToken($this->issueTokenFor($financeAdmin))->get($documentEndpoint)->assertForbidden();
            }
            $this->assertSame('OLD GIVEN', $context['applicant']->fresh()->first_name);
        }
    }

    public function test_passport_evidence_rejects_cross_user_path_and_integrity_metadata_mismatch(): void
    {
        Storage::fake('kyc_private');
        $context = $this->context();
        $admin = User::factory()->create();
        $admin->roles()->create(['role_code' => 'admin']);
        $created = $this->withToken($this->issueTokenFor($admin))->postJson(
            "/api/admin/users/{$context['user']->id}/kyc-profile/corrections/request-update",
            ['subject' => 'applicant', 'fields' => [], 'document_types' => ['passport'], 'instruction' => 'Upload passport.'],
        )->assertCreated()->json('correction');
        $state = $this->withToken($this->issueTokenFor($context['user']))->getJson($this->endpoint($context['user']))->json();
        $contents = "%PDF-1.4\npassport\n%%EOF";
        $hash = hash('sha256', $contents);
        $other = User::factory()->create();
        $otherPath = "kyc/{$other->id}/documents/{$hash}-passport.pdf";
        Storage::disk('kyc_private')->put($otherPath, $contents);
        $base = [
            'correction_id' => $created['id'], 'correction_version' => $state['correction_version'],
            'source_fingerprint' => $state['source_fingerprint'],
        ];
        $document = [
            'type' => 'passport', 'file_url' => "https://api.example.test/api/kyc-documents/{$other->id}/{$hash}",
            'storage_disk' => 'kyc_private', 'file_path' => $otherPath, 'file_hash' => $hash,
            'mime_type' => 'application/pdf', 'file_size' => strlen($contents),
        ];
        $token = $this->issueTokenFor($context['user']);
        $this->withToken($token)->postJson($this->endpoint($context['user']), [...$base, 'documents' => [$document]])
            ->assertUnprocessable()->assertJsonValidationErrors('documents');

        $ownPath = "kyc/{$context['user']->id}/documents/{$hash}-passport.pdf";
        Storage::disk('kyc_private')->put($ownPath, $contents);
        $this->registerUpload($context['user'], $ownPath, $hash, $contents);
        $document['file_path'] = $ownPath;
        $document['file_size']++;
        $this->withToken($token)->postJson($this->endpoint($context['user']), [...$base, 'documents' => [$document]])
            ->assertUnprocessable()->assertJsonValidationErrors('documents');
        $document['file_size'] = strlen($contents);
        $document['mime_type'] = 'image/jpeg';
        $this->withToken($token)->postJson($this->endpoint($context['user']), [...$base, 'documents' => [$document]])
            ->assertUnprocessable()->assertJsonValidationErrors('documents');
    }

    private function context(): array
    {
        $user = User::factory()->create(['status' => 'active', 'kyc_status' => 'verified']);
        $profile = $user->kycProfile()->create([
            'status' => 'verified',
            'applicant_type' => 'business',
            'legal_name' => 'GREENTECH-LIKE LIMITED',
            'business_name' => 'GREENTECH-LIKE LIMITED',
            'address_line1' => '1 Test Road',
            'city' => 'Hong Kong',
            'country_code' => 'HK',
            'registered_country_code' => 'HK',
            'metadata' => ['nium_region' => 'HK', 'nium_kyc_type' => 'full'],
        ]);
        $applicant = $profile->relatedPersons()->create([
            'relationship_type' => 'applicant',
            'status' => 'approved',
            'legal_name' => 'OLD LEGAL NAME',
            'first_name' => 'OLD GIVEN',
            'last_name' => 'OLD SURNAME',
            'date_of_birth' => '1990-01-01',
            'ownership_percentage' => 25,
            'metadata' => ['passport_name_fields_version' => 1],
        ]);
        $provider = IntegrationProvider::query()->firstOrCreate(
            ['code' => 'nium'],
            ['name' => 'Nium', 'status' => 'active'],
        );
        $customerHashId = (string) Str::uuid();
        $referenceId = (string) Str::uuid();
        $account = UserProviderAccount::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'external_customer_id' => $customerHashId,
            'external_reference' => (string) Str::uuid(),
            'status' => 'submitted',
            'provider_status' => 'pending',
            'provider_sub_status' => 'awaiting_kyc',
            'customer_id_verified_at' => now(),
            'reconciliation_status' => 'reconciled',
            'metadata' => ['nium_entity_kyc_states' => ['ref_applicant' => [
                'entity_type' => 'applicant',
                'kyc_status' => 'retry',
                'kyc_mode' => 'biometric_kyc',
                'external_id' => 'origin-wallet-applicant-'.$applicant->id,
                'provider_reference_id' => $referenceId,
            ]]],
        ]);
        $submission = $profile->providerSubmissions()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'provider_account_id' => $account->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        return compact('user', 'profile', 'applicant', 'provider', 'account', 'submission', 'customerHashId', 'referenceId') + [
            'customer_hash_id' => $customerHashId,
            'reference_id' => $referenceId,
        ];
    }

    private function registerUpload(User $user, string $path, string $hash, string $contents): KycDocumentUpload
    {
        return KycDocumentUpload::query()->create([
            'user_id' => $user->id,
            'storage_disk' => 'kyc_private',
            'file_path' => $path,
            'file_hash' => $hash,
            'mime_type' => 'application/pdf',
            'file_size' => strlen($contents),
            'status' => 'uploaded',
            'expires_at' => now()->addDays(7),
        ]);
    }

    private function endpoint(User $user): string
    {
        return "/api/user/users/{$user->id}/kyc-profile/applicant-correction";
    }

    private function issueTokenFor(User $user): string
    {
        $plainToken = Str::random(80);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'test-token',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);
        return $plainToken;
    }
}
