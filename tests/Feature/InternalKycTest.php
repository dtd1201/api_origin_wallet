<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Currenxie\CurrenxiePayloadMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\RedirectOnboardingProvider;
use Tests\TestCase;

class InternalKycTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_submit_platform_style_business_kyc_profile_for_review(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
            'kyc_status' => 'unverified',
        ]);

        $response = $this->withToken($this->issueTokenFor($user))
            ->putJson("/api/user/users/{$user->id}/kyc-profile", $this->businessKycPayload());

        $response
            ->assertAccepted()
            ->assertJsonPath('kyc_status', 'pending')
            ->assertJsonPath('kyc_profile.status', 'submitted')
            ->assertJsonPath('kyc_profile.applicant_type', 'business')
            ->assertJsonFragment(['type' => 'passport'])
            ->assertJsonPath('kyc_profile.related_persons.0.relationship_type', 'authorized_representative')
            ->assertJsonFragment(['key' => 'business_registration']);

        $this->assertDatabaseHas('kyc_profiles', [
            'user_id' => $user->id,
            'status' => 'submitted',
            'applicant_type' => 'business',
            'business_name' => 'Acme Inc',
        ]);
        $this->assertDatabaseHas('kyc_documents', [
            'type' => 'business_registration',
            'document_number' => 'ACME-001',
        ]);
        $this->assertDatabaseHas('kyc_related_persons', [
            'relationship_type' => 'beneficial_owner',
            'legal_name' => 'John Owner',
        ]);
        $this->assertDatabaseHas('kyc_requirements', [
            'key' => 'beneficial_owner',
            'status' => 'submitted',
        ]);
        $this->assertDatabaseHas('aml_screenings', [
            'user_id' => $user->id,
            'subject_type' => 'kyc_profile',
            'subject_name' => 'Acme Inc',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('aml_screenings', [
            'user_id' => $user->id,
            'subject_type' => 'kyc_related_person',
            'subject_name' => 'John Owner',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => 'pending',
            'kyc_status' => 'pending',
        ]);
    }

    public function test_admin_can_approve_internal_kyc_and_activate_user(): void
    {
        $admin = $this->createAdminUser();
        $user = User::factory()->create([
            'status' => 'pending',
            'kyc_status' => 'pending',
        ]);
        $this->submitKycProfile($user);
        $this->runAmlForProfile($admin, $user);

        $response = $this->withToken($this->issueTokenFor($admin))
            ->postJson("/api/admin/users/{$user->id}/kyc-profile/approve", [
                'review_note' => 'Documents match profile.',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('user.status', 'active')
            ->assertJsonPath('user.kyc_status', 'verified')
            ->assertJsonPath('kyc_profile.status', 'verified')
            ->assertJsonPath('kyc_profile.documents.0.status', 'approved')
            ->assertJsonPath('kyc_profile.reviewed_by_user_id', $admin->id);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'kyc.approved',
            'entity_type' => 'kyc_profile',
        ]);
        $this->assertDatabaseHas('kyc_requirements', [
            'key' => 'identity_document',
            'status' => 'approved',
        ]);
    }

    public function test_admin_cannot_approve_kyc_profile_until_aml_is_clear(): void
    {
        $admin = $this->createAdminUser();
        $user = User::factory()->create([
            'status' => 'pending',
            'kyc_status' => 'pending',
        ]);
        $this->submitKycProfile($user);

        $this->withToken($this->issueTokenFor($admin))
            ->postJson("/api/admin/users/{$user->id}/kyc-profile/approve")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'All AML screenings must be clear or manually cleared before KYC/KYB approval.');

        $this->runAmlForProfile($admin, $user);

        $this->withToken($this->issueTokenFor($admin))
            ->postJson("/api/admin/users/{$user->id}/kyc-profile/approve")
            ->assertOk()
            ->assertJsonPath('user.kyc_status', 'verified');
    }

    public function test_potential_aml_match_blocks_kyc_approval_until_manual_clear(): void
    {
        $admin = $this->createAdminUser();
        $user = User::factory()->create([
            'status' => 'pending',
            'kyc_status' => 'pending',
        ]);
        $this->submitKycProfile($user);

        $screening = $user->fresh('kycProfile.amlScreenings')->kycProfile->amlScreenings->first();
        $screening->update([
            'raw_data' => [
                'metadata' => [
                    'aml' => [
                        'matches' => [
                            [
                                'list_type' => 'sanctions',
                                'source' => 'internal_test_list',
                                'matched_name' => 'Acme Inc',
                                'score' => 97,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->runAmlForProfile($admin, $user)
            ->assertJsonPath('aml_screenings.0.status', 'potential_match');

        $this->withToken($this->issueTokenFor($admin))
            ->postJson("/api/admin/users/{$user->id}/kyc-profile/approve")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'All AML screenings must be clear or manually cleared before KYC/KYB approval.');

        $this->withToken($this->issueTokenFor($admin))
            ->postJson("/api/admin/aml-screenings/{$screening->id}/clear", [
                'review_note' => 'False positive after manual AML review.',
            ])
            ->assertOk()
            ->assertJsonPath('aml_screening.status', 'manual_clear');

        $this->withToken($this->issueTokenFor($admin))
            ->postJson("/api/admin/users/{$user->id}/kyc-profile/approve")
            ->assertOk()
            ->assertJsonPath('user.kyc_status', 'verified');
    }

    public function test_admin_cannot_approve_kyc_profile_with_missing_required_requirements(): void
    {
        $admin = $this->createAdminUser();
        $user = User::factory()->create([
            'status' => 'pending',
            'kyc_status' => 'pending',
        ]);
        $this->withToken($this->issueTokenFor($user))
            ->putJson("/api/user/users/{$user->id}/kyc-profile", [
                ...$this->individualKycPayload(),
                'documents' => [
                    [
                        'type' => 'passport',
                        'file_url' => 'https://files.example.com/passport-front.jpg',
                    ],
                ],
            ])
            ->assertAccepted();

        $this->withToken($this->issueTokenFor($admin))
            ->postJson("/api/admin/users/{$user->id}/kyc-profile/approve")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'All required KYC requirements must be submitted before approval.');
    }

    public function test_admin_can_reject_internal_kyc_with_requirement_feedback(): void
    {
        $admin = $this->createAdminUser();
        $user = User::factory()->create([
            'status' => 'pending',
            'kyc_status' => 'pending',
        ]);
        $this->submitKycProfile($user);
        $this->runAmlForProfile($admin, $user);

        $response = $this->withToken($this->issueTokenFor($admin))
            ->postJson("/api/admin/users/{$user->id}/kyc-profile/reject", [
                'rejection_reason' => 'Document is unreadable.',
                'requirements' => [
                    [
                        'key' => 'identity_document',
                        'status' => 'needs_more_info',
                        'rejection_reason' => 'Passport image is blurry.',
                    ],
                ],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('user.status', 'pending')
            ->assertJsonPath('user.kyc_status', 'rejected')
            ->assertJsonPath('kyc_profile.status', 'rejected')
            ->assertJsonPath('kyc_profile.rejection_reason', 'Document is unreadable.');

        $this->assertDatabaseHas('kyc_requirements', [
            'key' => 'identity_document',
            'status' => 'needs_more_info',
            'rejection_reason' => 'Passport image is blurry.',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'kyc.rejected',
            'entity_type' => 'kyc_profile',
        ]);
    }

    public function test_admin_can_approve_provider_kyc_submission_after_internal_kyc_is_verified(): void
    {
        config()->set('integrations.providers.hosted_provider.onboarding', RedirectOnboardingProvider::class);
        config()->set('services.hosted_provider.base_url', 'https://api.hosted-provider.test');

        $admin = $this->createAdminUser();
        $user = User::factory()->create([
            'status' => 'pending',
            'kyc_status' => 'pending',
        ]);
        $provider = IntegrationProvider::query()->create([
            'code' => 'HOSTED_PROVIDER',
            'name' => 'Hosted Provider',
            'status' => 'active',
        ]);

        $this->withToken($this->issueTokenFor($admin))
            ->postJson("/api/admin/users/{$user->id}/kyc-profile/providers/{$provider->code}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'User internal KYC must be verified before approving provider submission.');

        $this->submitKycProfile($user);
        $this->runAmlForProfile($admin, $user);
        $this->withToken($this->issueTokenFor($admin))
            ->postJson("/api/admin/users/{$user->id}/kyc-profile/approve")
            ->assertOk();

        $this->withToken($this->issueTokenFor($admin))
            ->postJson("/api/admin/users/{$user->id}/kyc-profile/providers/{$provider->code}/approve", [
                'review_note' => 'Approved to submit to Hosted Provider.',
            ])
            ->assertOk()
            ->assertJsonPath('provider.code', 'HOSTED_PROVIDER')
            ->assertJsonPath('kyc_provider_submission.status', 'approved')
            ->assertJsonPath('kyc_provider_submission.reviewed_by_user_id', $admin->id);

        $this->assertDatabaseHas('kyc_provider_submissions', [
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'kyc_provider_submission.approved',
            'entity_type' => 'kyc_provider_submission',
        ]);
    }

    public function test_provider_onboarding_requires_verified_internal_kyc(): void
    {
        config()->set('integrations.providers.hosted_provider.onboarding', RedirectOnboardingProvider::class);
        config()->set('services.hosted_provider.base_url', 'https://api.hosted-provider.test');

        $user = User::factory()->create([
            'kyc_status' => 'pending',
        ]);
        $user->profile()->create([
            'user_type' => 'business',
        ]);

        $provider = IntegrationProvider::query()->create([
            'code' => 'HOSTED_PROVIDER',
            'name' => 'Hosted Provider',
            'status' => 'active',
        ]);

        $this->withToken($this->issueTokenFor($user))
            ->postJson("/api/user/users/{$user->id}/provider-accounts/{$provider->code}/link", [
                'force' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'User internal KYC must be verified before provider onboarding.');

        $user->update(['kyc_status' => 'verified']);

        $this->withToken($this->issueTokenFor($user))
            ->postJson("/api/user/users/{$user->id}/provider-accounts/{$provider->code}/link", [
                'force' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Provider KYC submission must be approved internally before sending to this provider.');

        $this->approveProviderSubmission($user, $provider);

        $this->withToken($this->issueTokenFor($user))
            ->postJson("/api/user/users/{$user->id}/provider-accounts/{$provider->code}/link", [
                'force' => true,
            ])
            ->assertOk()
            ->assertJsonPath('onboarding.next_action', 'redirect_to_provider');

        $this->assertDatabaseHas('kyc_provider_submissions', [
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'status' => 'submitted',
        ]);
    }

    public function test_provider_payload_can_reuse_internal_kyc_snapshot(): void
    {
        $user = User::factory()->create([
            'full_name' => 'Jane Doe',
            'kyc_status' => 'verified',
        ]);
        $user->profile()->create([
            'user_type' => 'business',
            'country_code' => 'US',
        ]);
        $this->submitKycProfile($user);
        $user->kycProfile()->update([
            'status' => 'verified',
            'reviewed_at' => now(),
        ]);
        User::query()->whereKey($user->id)->update([
            'kyc_status' => 'verified',
        ]);

        $payload = app(CurrenxiePayloadMapper::class)
            ->buildCustomerPayload($user->fresh(['profile', 'kycProfile.documents', 'kycProfile.relatedPersons.documents', 'kycProfile.requirements']));

        $this->assertSame('verified', $payload['internal_kyc']['status']);
        $this->assertSame('verified', $payload['internal_kyc']['profile']['status']);
        $identityDocument = collect($payload['internal_kyc']['documents'])
            ->firstWhere('type', 'passport');

        $this->assertSame('P1234567', $identityDocument['document_number']);
        $this->assertSame('authorized_representative', $payload['internal_kyc']['related_persons'][0]['relationship_type']);
        $this->assertNotEmpty($payload['internal_kyc']['aml_screenings']);
        $this->assertNotNull(
            collect($payload['internal_kyc']['requirements'])->firstWhere('key', 'identity_document')
        );
    }

    private function submitKycProfile(User $user): void
    {
        $this->withToken($this->issueTokenFor($user))
            ->putJson("/api/user/users/{$user->id}/kyc-profile", $this->businessKycPayload())
            ->assertAccepted();
    }

    private function runAmlForProfile(User $admin, User $user): TestResponse
    {
        return $this->withToken($this->issueTokenFor($admin))
            ->postJson("/api/admin/users/{$user->id}/kyc-profile/aml-screenings/run")
            ->assertOk();
    }

    private function approveProviderSubmission(User $user, IntegrationProvider $provider): void
    {
        $user->kycProviderSubmissions()->updateOrCreate(
            ['provider_id' => $provider->id],
            [
                'status' => 'approved',
                'approved_at' => now(),
            ],
        );
    }

    private function createAdminUser(): User
    {
        $user = User::factory()->create();
        $user->roles()->create([
            'role_code' => 'admin',
        ]);

        return $user;
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

    /**
     * @return array<string, mixed>
     */
    private function individualKycPayload(): array
    {
        return [
            'applicant_type' => 'individual',
            'legal_name' => 'Jane Doe',
            'date_of_birth' => '1990-01-01',
            'nationality_country_code' => 'US',
            'residence_country_code' => 'US',
            'address_line1' => '100 Main Street',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
            'country_code' => 'US',
            'documents' => [
                [
                    'type' => 'passport',
                    'file_url' => 'https://files.example.com/passport-front.jpg',
                    'side' => 'front',
                    'document_number' => 'P1234567',
                    'issuing_country_code' => 'US',
                    'issued_at' => '2021-01-01',
                    'expires_at' => '2031-01-01',
                ],
                [
                    'type' => 'proof_of_address',
                    'file_url' => 'https://files.example.com/utility-bill.pdf',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function businessKycPayload(): array
    {
        return [
            ...$this->individualKycPayload(),
            'applicant_type' => 'business',
            'business_name' => 'Acme Inc',
            'business_registration_number' => 'ACME-001',
            'tax_id' => 'TAX-001',
            'registered_country_code' => 'US',
            'documents' => [
                ...$this->individualKycPayload()['documents'],
                [
                    'type' => 'business_registration',
                    'file_url' => 'https://files.example.com/business-registration.pdf',
                    'document_number' => 'ACME-001',
                    'issuing_country_code' => 'US',
                ],
            ],
            'related_persons' => [
                [
                    'relationship_type' => 'authorized_representative',
                    'legal_name' => 'Jane Doe',
                    'date_of_birth' => '1990-01-01',
                    'nationality_country_code' => 'US',
                    'residence_country_code' => 'US',
                    'documents' => [
                        [
                            'type' => 'passport',
                            'file_url' => 'https://files.example.com/representative-passport.jpg',
                            'document_number' => 'P1234567',
                            'issuing_country_code' => 'US',
                        ],
                    ],
                ],
                [
                    'relationship_type' => 'beneficial_owner',
                    'legal_name' => 'John Owner',
                    'date_of_birth' => '1985-02-01',
                    'nationality_country_code' => 'US',
                    'ownership_percentage' => 55,
                ],
            ],
        ];
    }
}
