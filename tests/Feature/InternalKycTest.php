<?php

namespace Tests\Feature;

use App\Contracts\Aml\AmlScreeningProvider;
use App\Models\ApiToken;
use App\Models\IntegrationProvider;
use App\Models\KycProfile;
use App\Models\User;
use App\Services\Currenxie\CurrenxiePayloadMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\RedirectOnboardingProvider;
use Tests\Fixtures\FakeAmlScreeningProvider;
use Tests\TestCase;

class InternalKycTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(AmlScreeningProvider::class, new FakeAmlScreeningProvider);
    }

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
            ->assertJsonPath('kyc_profile.metadata.registered_date', '2020-01-15')
            ->assertJsonPath('kyc_profile.metadata.nium_business_type', 'PRIVATE_COMPANY')
            ->assertJsonFragment(['type' => 'passport_front'])
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

    public function test_supplied_nium_address_relationship_requires_a_strict_json_boolean(): void
    {
        foreach (['true', 'false', '1', '0', 1, 0] as $invalidValue) {
            $user = User::factory()->create([
                'status' => 'active',
                'kyc_status' => 'unverified',
            ]);
            $payload = $this->businessKycPayload();
            $payload['registered_country_code'] = 'SG';
            $payload['metadata']['nium_v5_fields']['addresses'] = [
                'isBusinessAddressSameAsRegisteredAddress' => $invalidValue,
            ];
            Http::fake(fn () => throw new \RuntimeException('Unexpected registry HTTP request.'));

            $this->withToken($this->issueTokenFor($user))
                ->putJson("/api/user/users/{$user->id}/kyc-profile", $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(
                    'metadata.nium_v5_fields.addresses.isBusinessAddressSameAsRegisteredAddress',
                );

            Http::assertNothingSent();
        }
    }

    public function test_supplied_true_nium_address_relationship_prohibits_distinct_business_address(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
            'kyc_status' => 'unverified',
        ]);
        $payload = $this->businessKycPayload();
        $payload['registered_country_code'] = 'SG';
        $payload['metadata']['nium_v5_fields']['addresses'] = [
            'isBusinessAddressSameAsRegisteredAddress' => true,
            'businessAddress' => [
                'address_line1' => '2 Business Road',
            ],
        ];
        Http::fake(fn () => throw new \RuntimeException('Unexpected registry HTTP request.'));

        $this->withToken($this->issueTokenFor($user))
            ->putJson("/api/user/users/{$user->id}/kyc-profile", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('metadata.nium_v5_fields.addresses.businessAddress');

        Http::assertNothingSent();
    }

    public function test_supplied_false_nium_address_relationship_requires_complete_business_address(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
            'kyc_status' => 'unverified',
        ]);
        $payload = $this->businessKycPayload();
        $payload['registered_country_code'] = 'SG';
        $payload['metadata']['nium_v5_fields']['addresses'] = [
            'isBusinessAddressSameAsRegisteredAddress' => false,
            'businessAddress' => [
                'address_line1' => '2 Business Road',
            ],
        ];
        Http::fake(fn () => throw new \RuntimeException('Unexpected registry HTTP request.'));

        $this->withToken($this->issueTokenFor($user))
            ->putJson("/api/user/users/{$user->id}/kyc-profile", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'metadata.nium_v5_fields.addresses.businessAddress.city',
                'metadata.nium_v5_fields.addresses.businessAddress.state',
                'metadata.nium_v5_fields.addresses.businessAddress.postal_code',
                'metadata.nium_v5_fields.addresses.businessAddress.country_code',
            ]);

        Http::assertNothingSent();
    }

    #[DataProvider('missingSgCorporateAddressContractProvider')]
    public function test_sg_corporate_address_contract_requires_explicit_source_containers_and_declaration(
        string $scenario,
        string $expectedField,
    ): void {
        $payload = $this->sgCorporateKycPayload();

        if ($scenario === 'nium fields absent') {
            unset($payload['metadata']['nium_v5_fields']);
        } elseif ($scenario === 'addresses absent') {
            unset($payload['metadata']['nium_v5_fields']['addresses']);
        } elseif ($scenario === 'addresses empty') {
            $payload['metadata']['nium_v5_fields']['addresses'] = [];
        } elseif ($scenario === 'relationship missing') {
            unset(
                $payload['metadata']['nium_v5_fields']['addresses']
                    ['isBusinessAddressSameAsRegisteredAddress'],
            );
        } elseif ($scenario === 'relationship null') {
            $payload['metadata']['nium_v5_fields']['addresses']
                ['isBusinessAddressSameAsRegisteredAddress'] = null;
        }

        $this->assertSgCorporateAddressContractRejected($payload, $expectedField);
    }

    public static function missingSgCorporateAddressContractProvider(): array
    {
        return [
            'nium fields absent' => [
                'nium fields absent',
                'metadata.nium_v5_fields',
            ],
            'addresses absent' => [
                'addresses absent',
                'metadata.nium_v5_fields.addresses',
            ],
            'addresses empty' => [
                'addresses empty',
                'metadata.nium_v5_fields.addresses',
            ],
            'relationship missing' => [
                'relationship missing',
                'metadata.nium_v5_fields.addresses.isBusinessAddressSameAsRegisteredAddress',
            ],
            'relationship null' => [
                'relationship null',
                'metadata.nium_v5_fields.addresses.isBusinessAddressSameAsRegisteredAddress',
            ],
        ];
    }

    #[DataProvider('acceptedTrueSgCorporateBusinessAddressProvider')]
    public function test_sg_corporate_address_contract_accepts_exact_true_relationship_empty_shapes(
        bool $includeBusinessAddress,
        mixed $businessAddress,
    ): void {
        $payload = $this->sgCorporateKycPayload();

        if ($includeBusinessAddress) {
            $payload['metadata']['nium_v5_fields']['addresses']['businessAddress'] = $businessAddress;
        }

        $profile = $this->submitSgCorporateKycPayload($payload);
        $persistedAddresses = data_get($profile->metadata, 'nium_v5_fields.addresses');

        $this->assertSame(
            $this->expectedPersistedSgCorporateAddresses(
                $payload['metadata']['nium_v5_fields']['addresses'],
            ),
            $persistedAddresses,
        );
        $this->assertTrue($persistedAddresses['isBusinessAddressSameAsRegisteredAddress']);
        $this->assertIsBool($persistedAddresses['isBusinessAddressSameAsRegisteredAddress']);
    }

    public static function acceptedTrueSgCorporateBusinessAddressProvider(): array
    {
        return [
            'business address absent' => [false, null],
            'business address null' => [true, null],
            'business address empty array' => [true, []],
            'address line two null only' => [true, ['address_line2' => null]],
            'address line two empty only' => [true, ['address_line2' => '']],
            'address line two whitespace only' => [true, ['address_line2' => '   ']],
        ];
    }

    #[DataProvider('rejectedTrueSgCorporateBusinessAddressProvider')]
    public function test_sg_corporate_address_contract_rejects_malformed_true_relationship_shapes(
        mixed $businessAddress,
        array $forbiddenResponseFragments,
    ): void {
        $payload = $this->sgCorporateKycPayload();
        $payload['metadata']['nium_v5_fields']['addresses']['businessAddress'] = $businessAddress;

        $this->assertSgCorporateAddressContractRejected(
            $payload,
            'metadata.nium_v5_fields.addresses.businessAddress',
            $forbiddenResponseFragments,
        );
    }

    public static function rejectedTrueSgCorporateBusinessAddressProvider(): array
    {
        return [
            'required child present even when empty' => [
                ['city' => ''],
                [],
            ],
            'non-empty optional line two' => [
                ['address_line2' => 'synthetic_nonempty_line_two'],
                ['synthetic_nonempty_line_two'],
            ],
            'unknown child only' => [
                ['synthetic_unknown_child' => 'synthetic_unknown_value'],
                ['synthetic_unknown_child', 'synthetic_unknown_value'],
            ],
            'approved empty child plus unknown child' => [
                [
                    'address_line2' => '',
                    'synthetic_unknown_child' => 'synthetic_unknown_value',
                ],
                ['synthetic_unknown_child', 'synthetic_unknown_value'],
            ],
            'numeric list' => [
                ['synthetic_list_value'],
                ['synthetic_list_value'],
            ],
            'scalar' => [
                'synthetic_scalar_value',
                ['synthetic_scalar_value'],
            ],
            'nested value' => [
                ['address_line2' => ['synthetic_nested_value']],
                ['synthetic_nested_value'],
            ],
        ];
    }

    #[DataProvider('rejectedFalseSgCorporateBusinessAddressProvider')]
    public function test_sg_corporate_address_contract_rejects_malformed_false_relationship_shapes(
        bool $includeBusinessAddress,
        mixed $businessAddress,
        string $expectedField,
        array $forbiddenResponseFragments = [],
    ): void {
        $payload = $this->sgCorporateKycPayload(false);

        if ($includeBusinessAddress) {
            $payload['metadata']['nium_v5_fields']['addresses']['businessAddress'] = $businessAddress;
        }

        $this->assertSgCorporateAddressContractRejected(
            $payload,
            $expectedField,
            $forbiddenResponseFragments,
        );
    }

    public static function rejectedFalseSgCorporateBusinessAddressProvider(): array
    {
        $valid = self::validSgCorporateBusinessAddress();
        $parent = 'metadata.nium_v5_fields.addresses.businessAddress';
        $cases = [
            'business address absent' => [false, null, $parent],
            'business address null' => [true, null, $parent],
            'business address empty array' => [true, [], $parent],
            'business address numeric list' => [
                true,
                ['synthetic_list_value'],
                $parent,
                ['synthetic_list_value'],
            ],
            'business address scalar' => [
                true,
                'synthetic_scalar_value',
                $parent,
                ['synthetic_scalar_value'],
            ],
            'unknown child only' => [
                true,
                ['synthetic_unknown_child' => 'synthetic_unknown_value'],
                $parent,
                ['synthetic_unknown_child', 'synthetic_unknown_value'],
            ],
            'valid fields plus unknown child' => [
                true,
                [...$valid, 'synthetic_unknown_child' => 'synthetic_unknown_value'],
                $parent,
                ['synthetic_unknown_child', 'synthetic_unknown_value'],
            ],
            'malformed country' => [
                true,
                [...$valid, 'country_code' => 'SGP'],
                "{$parent}.country_code",
            ],
        ];

        foreach (['address_line1', 'city', 'state', 'postal_code', 'country_code'] as $field) {
            $missing = $valid;
            unset($missing[$field]);
            $cases["missing {$field}"] = [true, $missing, "{$parent}.{$field}"];
            $cases["empty {$field}"] = [true, [...$valid, $field => ''], "{$parent}.{$field}"];
            $cases["whitespace {$field}"] = [true, [...$valid, $field => '   '], $parent];
            $cases["non-string {$field}"] = [
                true,
                [...$valid, $field => ['synthetic_nested_value']],
                "{$parent}.{$field}",
                ['synthetic_nested_value'],
            ];
        }

        return $cases;
    }

    #[DataProvider('acceptedFalseSgCorporateBusinessAddressProvider')]
    public function test_sg_corporate_address_contract_accepts_valid_false_relationship_shapes(
        bool $includeAddressLineTwo,
        mixed $addressLineTwo,
    ): void {
        $payload = $this->sgCorporateKycPayload(false);
        $businessAddress = self::validSgCorporateBusinessAddress();

        if ($includeAddressLineTwo) {
            $businessAddress['address_line2'] = $addressLineTwo;
        }

        $payload['metadata']['nium_v5_fields']['addresses']['businessAddress'] = $businessAddress;
        $profile = $this->submitSgCorporateKycPayload($payload);
        $persistedAddresses = data_get($profile->metadata, 'nium_v5_fields.addresses');

        $this->assertSame(
            $this->expectedPersistedSgCorporateAddresses(
                $payload['metadata']['nium_v5_fields']['addresses'],
            ),
            $persistedAddresses,
        );
        $this->assertFalse($persistedAddresses['isBusinessAddressSameAsRegisteredAddress']);
        $this->assertIsBool($persistedAddresses['isBusinessAddressSameAsRegisteredAddress']);
    }

    public static function acceptedFalseSgCorporateBusinessAddressProvider(): array
    {
        return [
            'address line two absent' => [false, null],
            'address line two null' => [true, null],
            'address line two empty' => [true, ''],
            'complete valid address' => [true, 'Synthetic Unit'],
        ];
    }

    #[DataProvider('nonSgCorporateAndIndividualAddressContractProvider')]
    public function test_sg_corporate_address_contract_does_not_affect_other_profile_types(
        string $applicantType,
        string $region,
    ): void {
        $payload = $applicantType === 'business'
            ? $this->businessKycPayload()
            : $this->individualKycPayload();
        $payload['registered_country_code'] = $region;
        $payload['metadata']['nium_region'] = $region;
        $payload['metadata']['nium_v5_fields']['addresses'] = [
            'isBusinessAddressSameAsRegisteredAddress' => 'synthetic_non_boolean',
            'businessAddress' => 'synthetic_non_object',
        ];
        $payload['metadata']['synthetic_marker'] = [
            'scope' => $applicantType,
            'region' => $region,
        ];
        $user = User::factory()->create([
            'status' => 'active',
            'kyc_status' => 'unverified',
        ]);
        Http::fake(fn () => throw new \RuntimeException('Unexpected registry HTTP request.'));

        $this->withToken($this->issueTokenFor($user))
            ->putJson("/api/user/users/{$user->id}/kyc-profile", $payload)
            ->assertAccepted();

        $persistedAddresses = data_get(
            $user->kycProfile()->firstOrFail()->metadata,
            'nium_v5_fields.addresses',
        );
        $this->assertSame(
            $payload['metadata']['nium_v5_fields']['addresses'],
            $persistedAddresses,
        );
        $this->assertSame($region, data_get(
            $user->kycProfile()->firstOrFail()->metadata,
            'nium_region',
        ));
        $this->assertSame(
            $payload['metadata']['synthetic_marker'],
            data_get($user->kycProfile()->firstOrFail()->metadata, 'synthetic_marker'),
        );
        Http::assertNothingSent();
    }

    public static function nonSgCorporateAndIndividualAddressContractProvider(): array
    {
        return [
            'non-SG corporate' => ['business', 'US'],
            'SG individual' => ['individual', 'SG'],
            'non-SG individual' => ['individual', 'US'],
        ];
    }

    #[DataProvider('niumRegionResolutionProvider')]
    public function test_nium_region_resolution_uses_the_shared_region_contract(
        bool $includeExplicitRegion,
        ?string $explicitRegion,
        string $registeredCountry,
        string $expectedRegion,
    ): void {
        $payload = $this->businessKycPayload();
        $payload['registered_country_code'] = $registeredCountry;

        if ($includeExplicitRegion) {
            $payload['metadata'] = ['nium_region' => $explicitRegion];
        } else {
            unset($payload['metadata']);
        }

        if ($expectedRegion === 'SG') {
            $forbiddenFragments = $registeredCountry === 'ZZ' ? ['ZZ'] : [];
            $this->assertSgCorporateAddressContractRejected(
                $payload,
                'metadata.nium_v5_fields',
                $forbiddenFragments,
            );

            return;
        }

        $this->assertNonSgRegionSubmissionAccepted($payload);
    }

    public static function niumRegionResolutionProvider(): array
    {
        return [
            'explicit SG' => [true, 'SG', 'US', 'SG'],
            'explicit lowercase sg' => [true, 'sg', 'US', 'SG'],
            'explicit trimmed lowercase sg' => [true, ' sg ', 'US', 'SG'],
            'explicit US' => [true, 'US', 'US', 'US'],
            'explicit EU' => [true, 'EU', 'DE', 'EU'],
            'explicit region overrides unsupported-country SG fallback' => [true, 'US', 'ZZ', 'US'],
            'registered SG fallback' => [false, null, 'SG', 'SG'],
            'registered GB fallback' => [false, null, 'GB', 'UK'],
            'registered NL fallback' => [false, null, 'NL', 'NL'],
            'listed European country fallback' => [false, null, 'DE', 'EU'],
            'directly supported non-SG country fallback' => [false, null, 'US', 'US'],
            'unsupported country defaults to SG' => [false, null, 'ZZ', 'SG'],
            'explicit null uses registered-country fallback' => [true, null, 'GB', 'UK'],
        ];
    }

    #[DataProvider('invalidNiumRegionProvider')]
    public function test_invalid_nium_region_is_rejected_before_sg_contract_or_persistence(
        mixed $invalidRegion,
        array $forbiddenResponseFragments,
    ): void {
        $payload = $this->businessKycPayload();
        $payload['registered_country_code'] = 'SG';
        $payload['metadata'] = ['nium_region' => $invalidRegion];

        $this->assertSgCorporateAddressContractRejected(
            $payload,
            'metadata.nium_region',
            $forbiddenResponseFragments,
        );
    }

    public static function invalidNiumRegionProvider(): array
    {
        return [
            'empty string' => ['', []],
            'whitespace string' => ['   ', []],
            'unsupported string' => ['synthetic_unknown_region', ['synthetic_unknown_region']],
            'integer zero' => [0, []],
            'integer one' => [1, []],
            'boolean false' => [false, []],
            'boolean true' => [true, []],
            'empty array' => [[], []],
            'list array' => [['synthetic_list_region'], ['synthetic_list_region']],
            'associative array' => [
                ['region' => 'synthetic_associative_region'],
                ['synthetic_associative_region'],
            ],
            'nested array' => [
                [['region' => 'synthetic_nested_region']],
                ['synthetic_nested_region'],
            ],
            'object' => [
                (object) ['region' => 'synthetic_object_region'],
                ['synthetic_object_region'],
            ],
        ];
    }

    public function test_nium_region_resolution_normalizes_valid_explicit_region_before_persistence(): void
    {
        $payload = $this->businessKycPayload();
        $payload['registered_country_code'] = 'SG';
        $payload['metadata'] = [
            'nium_region' => ' us ',
            'synthetic_marker' => [
                'contract' => 'explicit_region',
                'version' => 1,
            ],
            'nium_v5_fields' => [
                'addresses' => [
                    'synthetic_address_marker' => 'preserved',
                ],
            ],
        ];
        $user = User::factory()->create([
            'status' => 'active',
            'kyc_status' => 'unverified',
        ]);

        $this->assertNonSgRegionSubmissionAccepted($payload, $user);
        $this->assertSame(
            'US',
            data_get($user->kycProfile()->firstOrFail()->metadata, 'nium_region'),
        );
        $this->assertSame(
            $payload['metadata']['synthetic_marker'],
            data_get($user->kycProfile()->firstOrFail()->metadata, 'synthetic_marker'),
        );
        $this->assertSame(
            $payload['metadata']['nium_v5_fields'],
            data_get($user->kycProfile()->firstOrFail()->metadata, 'nium_v5_fields'),
        );
    }

    public function test_metadata_sibling_preservation_keeps_explicit_null_region_and_country_fallback(): void
    {
        $payload = $this->businessKycPayload();
        $payload['registered_country_code'] = 'US';
        $payload['metadata'] = [
            'nium_region' => null,
            'synthetic_marker' => [
                'contract' => 'explicit_null',
                'preserved' => true,
            ],
            'nium_v5_fields' => [
                'addresses' => [
                    'synthetic_address_marker' => 'explicit_null_preserved',
                ],
            ],
        ];
        $user = User::factory()->create([
            'status' => 'active',
            'kyc_status' => 'unverified',
        ]);

        $this->assertNonSgRegionSubmissionAccepted($payload, $user);
        $persistedMetadata = (array) $user->kycProfile()->firstOrFail()->metadata;
        $this->assertArrayHasKey('nium_region', $persistedMetadata);
        $this->assertNull($persistedMetadata['nium_region']);
        $this->assertSame(
            $payload['metadata']['synthetic_marker'],
            $persistedMetadata['synthetic_marker'],
        );
        $this->assertSame(
            $payload['metadata']['nium_v5_fields'],
            $persistedMetadata['nium_v5_fields'],
        );
    }

    public function test_nium_region_resolution_uses_existing_sg_metadata_when_resubmission_omits_metadata(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
            'kyc_status' => 'unverified',
        ]);
        $profile = $this->existingBusinessKycProfile($user, ['nium_region' => 'SG']);
        $profileBefore = $profile->fresh()->getRawOriginal();
        $payload = $this->businessKycPayload();
        unset($payload['metadata']);
        Http::fake(fn () => throw new \RuntimeException('Unexpected registry HTTP request.'));

        $this->withToken($this->issueTokenFor($user))
            ->putJson("/api/user/users/{$user->id}/kyc-profile", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('metadata.nium_v5_fields');

        Http::assertNothingSent();
        $this->assertSame($profileBefore, $profile->fresh()->getRawOriginal());
        $this->assertDatabaseCount('kyc_profiles', 1);
        $this->assertDatabaseCount('kyc_related_persons', 0);
        $this->assertDatabaseCount('kyc_documents', 0);
        $this->assertDatabaseCount('kyc_requirements', 0);
        $this->assertDatabaseCount('aml_screenings', 0);
        $this->assertSame('active', $user->fresh()->status);
        $this->assertSame('unverified', $user->fresh()->kyc_status);
    }

    public function test_nium_region_resolution_preserves_existing_non_sg_metadata_when_resubmission_omits_metadata(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
            'kyc_status' => 'unverified',
        ]);
        $existingMetadata = [
            'nium_region' => 'US',
            'synthetic_marker' => [
                'contract' => 'metadata_omitted',
                'preserved' => true,
            ],
            'nium_v5_fields' => [
                'addresses' => [
                    'synthetic_address_marker' => 'existing_preserved',
                ],
            ],
        ];
        $this->existingBusinessKycProfile($user, $existingMetadata);
        $payload = $this->businessKycPayload();
        unset($payload['metadata']);
        $this->assertNonSgRegionSubmissionAccepted($payload, $user);
        $persistedMetadata = (array) $user->kycProfile()->firstOrFail()->metadata;
        $this->assertSame('US', $persistedMetadata['nium_region']);
        $this->assertSame(
            $existingMetadata['synthetic_marker'],
            $persistedMetadata['synthetic_marker'],
        );
        $this->assertSame(
            $existingMetadata['nium_v5_fields'],
            $persistedMetadata['nium_v5_fields'],
        );
        $this->assertArrayHasKey('business_registry_verification', $persistedMetadata);
    }

    public function test_nium_region_resolution_submitted_explicit_region_replaces_existing_sg_metadata(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
            'kyc_status' => 'unverified',
        ]);
        $this->existingBusinessKycProfile($user, ['nium_region' => 'SG']);
        $payload = $this->businessKycPayload();
        $payload['metadata'] = ['nium_region' => 'US'];
        $this->assertNonSgRegionSubmissionAccepted($payload, $user);
        $this->assertSame('US', data_get($user->kycProfile()->firstOrFail()->metadata, 'nium_region'));
    }

    public function test_nium_region_resolution_submitted_metadata_without_region_replaces_existing_sg_metadata(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
            'kyc_status' => 'unverified',
        ]);
        $this->existingBusinessKycProfile($user, ['nium_region' => 'SG']);
        $payload = $this->businessKycPayload();
        $payload['metadata'] = [
            'synthetic_marker' => [
                'contract' => 'submitted_without_region',
                'preserved' => true,
            ],
            'nium_v5_fields' => [
                'addresses' => [
                    'synthetic_address_marker' => 'replacement_preserved',
                ],
            ],
        ];
        $this->assertNonSgRegionSubmissionAccepted($payload, $user);
        $persistedMetadata = (array) $user->kycProfile()->firstOrFail()->metadata;
        $this->assertArrayNotHasKey('nium_region', $persistedMetadata);
        $this->assertSame(
            $payload['metadata']['synthetic_marker'],
            $persistedMetadata['synthetic_marker'],
        );
        $this->assertSame(
            $payload['metadata']['nium_v5_fields'],
            $persistedMetadata['nium_v5_fields'],
        );
    }

    public function test_metadata_preservation_registry_merge_adds_only_verification_child(): void
    {
        $payload = $this->sgCorporateKycPayload();
        $payload['metadata']['synthetic_marker'] = [
            'contract' => 'registry_merge',
            'preserved' => true,
        ];
        $submittedAddresses = $payload['metadata']['nium_v5_fields']['addresses'];
        $profile = $this->submitSgCorporateKycPayload($payload);
        $persistedMetadata = (array) $profile->metadata;

        $this->assertSame('SG', $persistedMetadata['nium_region']);
        $this->assertSame(
            $payload['metadata']['synthetic_marker'],
            $persistedMetadata['synthetic_marker'],
        );
        $this->assertSame(
            $submittedAddresses,
            $persistedMetadata['nium_v5_fields']['addresses'],
        );
        $this->assertArrayHasKey('business_registry_verification', $persistedMetadata);
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
            'key' => 'identity_document_front',
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
        $this->app->instance(AmlScreeningProvider::class, new FakeAmlScreeningProvider('match'));

        $this->runAmlForProfile($admin, $user)
            ->assertJsonPath('aml_screenings.0.status', 'manual_review')
            ->assertJsonPath('aml_screenings.0.compliance_decision', 'pending_review');

        $this->withToken($this->issueTokenFor($admin))
            ->postJson("/api/admin/users/{$user->id}/kyc-profile/approve")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'All AML screenings must be clear or manually cleared before KYC/KYB approval.');

        $this->withToken($this->issueTokenFor($admin))
            ->postJson("/api/admin/aml-screenings/{$screening->id}/clear", [
                'review_note' => 'False positive after manual AML review.',
            ])
            ->assertOk()
            ->assertJsonPath('aml_screening.status', 'completed')
            ->assertJsonPath('aml_screening.compliance_decision', 'clear');

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
                        'key' => 'identity_document_front',
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
            'key' => 'identity_document_front',
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

    public function test_admin_cannot_approve_corporate_provider_submission_without_nium_metadata(): void
    {
        $admin = $this->createAdminUser();
        $user = User::factory()->create([
            'status' => 'active',
            'kyc_status' => 'verified',
        ]);
        $user->kycProfile()->create([
            'status' => 'verified',
            'applicant_type' => 'business',
            'legal_name' => 'Incomplete Corporate Customer',
            'business_name' => 'Incomplete Corporate Customer',
            'registered_country_code' => 'US',
            'address_line1' => '100 Main Street',
            'city' => 'New York',
            'country_code' => 'US',
            'metadata' => [],
        ]);
        $provider = IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);

        $this->withToken($this->issueTokenFor($admin))
            ->postJson("/api/admin/users/{$user->id}/kyc-profile/providers/{$provider->code}/approve")
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Corporate KYC metadata is incomplete for Nium provider approval: registered_date, nium_business_type.',
            );

        $this->assertDatabaseMissing('kyc_provider_submissions', [
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'status' => 'approved',
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
            ->firstWhere('type', 'passport_front');

        $this->assertSame('P1234567', $identityDocument['document_number']);
        $this->assertSame('authorized_representative', $payload['internal_kyc']['related_persons'][0]['relationship_type']);
        $this->assertNotEmpty($payload['internal_kyc']['aml_screenings']);
        $this->assertNotNull(
            collect($payload['internal_kyc']['requirements'])->firstWhere('key', 'identity_document_front')
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
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $forbiddenResponseFragments
     */
    private function assertSgCorporateAddressContractRejected(
        array $payload,
        string $expectedField,
        array $forbiddenResponseFragments = [],
    ): void {
        $user = User::factory()->create([
            'status' => 'active',
            'kyc_status' => 'unverified',
        ]);
        $statusBefore = $user->status;
        $kycStatusBefore = $user->kyc_status;
        $this->assertNull($user->kycProfile()->first());
        Http::fake(fn () => throw new \RuntimeException('Unexpected registry HTTP request.'));

        $response = $this->withToken($this->issueTokenFor($user))
            ->putJson("/api/user/users/{$user->id}/kyc-profile", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($expectedField);

        foreach ($forbiddenResponseFragments as $fragment) {
            $response->assertDontSee($fragment);
        }

        Http::assertNothingSent();
        $this->assertNull($user->kycProfile()->first());
        $this->assertDatabaseCount('kyc_profiles', 0);
        $this->assertDatabaseCount('kyc_related_persons', 0);
        $this->assertDatabaseCount('kyc_documents', 0);
        $this->assertDatabaseCount('kyc_requirements', 0);
        $this->assertDatabaseCount('aml_screenings', 0);
        $this->assertSame($statusBefore, $user->fresh()->status);
        $this->assertSame($kycStatusBefore, $user->fresh()->kyc_status);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function submitSgCorporateKycPayload(array $payload): KycProfile
    {
        config([
            'services.business_registry.sg.datastore_url' => 'https://registry.test/search',
            'services.business_registry.sg.dataset_ids' => [
                'A' => 'synthetic-acra-dataset',
            ],
        ]);
        Http::fake(fn () => Http::response([
            'result' => [
                'records' => [
                    [
                        'uen' => 'ACME001',
                        'entity_name' => 'Acme Inc',
                        'entity_status_description' => 'Registered',
                    ],
                ],
            ],
        ]));
        $user = User::factory()->create([
            'status' => 'active',
            'kyc_status' => 'unverified',
        ]);

        $this->withToken($this->issueTokenFor($user))
            ->putJson("/api/user/users/{$user->id}/kyc-profile", $payload)
            ->assertAccepted();

        Http::assertSentCount(1);
        $this->assertDatabaseHas('kyc_profiles', [
            'user_id' => $user->id,
            'status' => 'submitted',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => 'pending',
            'kyc_status' => 'pending',
        ]);

        return $user->kycProfile()->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function sgCorporateKycPayload(bool $relationship = true): array
    {
        $payload = $this->businessKycPayload();
        $payload['registered_country_code'] = 'SG';
        $payload['metadata']['nium_region'] = 'SG';
        $payload['metadata']['nium_v5_fields']['addresses'] = [
            'isBusinessAddressSameAsRegisteredAddress' => $relationship,
        ];

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    private static function validSgCorporateBusinessAddress(): array
    {
        return [
            'address_line1' => '88 Synthetic Road',
            'city' => 'Singapore',
            'state' => 'SG-04',
            'postal_code' => '049321',
            'country_code' => 'SG',
        ];
    }

    /**
     * @param  array<string, mixed>  $addresses
     * @return array<string, mixed>
     */
    private function expectedPersistedSgCorporateAddresses(array $addresses): array
    {
        $addressLineTwo = data_get($addresses, 'businessAddress.address_line2');

        if (is_string($addressLineTwo) && trim($addressLineTwo) === '') {
            $addresses['businessAddress']['address_line2'] = null;
        }

        return $addresses;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function existingBusinessKycProfile(User $user, array $metadata): KycProfile
    {
        return $user->kycProfile()->create([
            'status' => 'submitted',
            'applicant_type' => 'business',
            'legal_name' => 'Existing Synthetic Business',
            'business_name' => 'Existing Synthetic Business',
            'business_registration_number' => 'EXISTING-001',
            'registered_country_code' => 'US',
            'address_line1' => 'Existing Synthetic Address',
            'city' => 'Existing City',
            'state' => 'EX',
            'postal_code' => '00000',
            'country_code' => 'US',
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertNonSgRegionSubmissionAccepted(array $payload, ?User $user = null): void
    {
        $user ??= User::factory()->create([
            'status' => 'active',
            'kyc_status' => 'unverified',
        ]);
        Http::fake(fn () => throw new \RuntimeException('Unexpected registry HTTP request.'));

        $this->withToken($this->issueTokenFor($user))
            ->putJson("/api/user/users/{$user->id}/kyc-profile", $payload)
            ->assertAccepted();

        Http::assertNothingSent();
        $this->assertDatabaseHas('kyc_profiles', [
            'user_id' => $user->id,
            'status' => 'submitted',
        ]);
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
                    'type' => 'passport_front',
                    'file_url' => 'https://files.example.com/passport-front.jpg',
                    'side' => 'front',
                    'document_number' => 'P1234567',
                    'issuing_country_code' => 'US',
                    'issued_at' => '2021-01-01',
                    'expires_at' => '2031-01-01',
                ],
                [
                    'type' => 'passport_back',
                    'file_url' => 'https://files.example.com/passport-back.jpg',
                    'side' => 'back',
                    'document_number' => 'P1234567',
                    'issuing_country_code' => 'US',
                    'issued_at' => '2021-01-01',
                    'expires_at' => '2031-01-01',
                ],
                [
                    'type' => 'proof_of_address',
                    'file_url' => 'https://files.example.com/utility-bill.pdf',
                ],
                [
                    'type' => 'selfie_liveness',
                    'file_url' => 'https://files.example.com/selfie-liveness.jpg',
                    'metadata' => [
                        'liveness_session_id' => 'live_applicant_001',
                    ],
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
            'metadata' => [
                'registered_date' => '2020-01-15',
                'nium_business_type' => 'PRIVATE_COMPANY',
            ],
            'documents' => [
                ...$this->individualKycPayload()['documents'],
                [
                    'type' => 'business_registration',
                    'file_url' => 'https://files.example.com/business-registration.pdf',
                    'document_number' => 'ACME-001',
                    'issuing_country_code' => 'US',
                ],
                [
                    'type' => 'proof_of_business_address',
                    'file_url' => 'https://files.example.com/business-address.pdf',
                    'issuing_country_code' => 'US',
                ],
                [
                    'type' => 'ownership_structure',
                    'file_url' => 'https://files.example.com/ownership-structure.pdf',
                    'issuing_country_code' => 'US',
                ],
                [
                    'type' => 'certificate_of_incorporation',
                    'file_url' => 'https://files.example.com/certificate-of-incorporation.pdf',
                    'issuing_country_code' => 'US',
                ],
                [
                    'type' => 'account_opening_application_form',
                    'file_url' => 'https://files.example.com/account-opening-application.pdf',
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
                            'type' => 'passport_front',
                            'file_url' => 'https://files.example.com/representative-passport-front.jpg',
                            'side' => 'front',
                            'document_number' => 'P1234567',
                            'issuing_country_code' => 'US',
                        ],
                        [
                            'type' => 'passport_back',
                            'file_url' => 'https://files.example.com/representative-passport-back.jpg',
                            'side' => 'back',
                            'document_number' => 'P1234567',
                            'issuing_country_code' => 'US',
                        ],
                        [
                            'type' => 'proof_of_address',
                            'file_url' => 'https://files.example.com/representative-address.pdf',
                        ],
                        [
                            'type' => 'selfie_liveness',
                            'file_url' => 'https://files.example.com/representative-liveness.jpg',
                        ],
                    ],
                ],
                [
                    'relationship_type' => 'beneficial_owner',
                    'legal_name' => 'John Owner',
                    'date_of_birth' => '1985-02-01',
                    'nationality_country_code' => 'US',
                    'ownership_percentage' => 55,
                    'documents' => [
                        [
                            'type' => 'passport_front',
                            'file_url' => 'https://files.example.com/owner-passport-front.jpg',
                            'side' => 'front',
                            'document_number' => 'P7654321',
                            'issuing_country_code' => 'US',
                        ],
                    ],
                ],
            ],
        ];
    }
}
