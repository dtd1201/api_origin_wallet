<?php

namespace Tests\Feature;

use App\Models\KycProfile;
use App\Models\KycRelatedPerson;
use App\Models\User;
use App\Services\Nium\NiumHkKycIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class NiumHkKycIdentityResolverTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('eligiblePassports')]
    public function test_resolves_canonical_passport_documents(string $type, string $status): void
    {
        $person = $this->person('HK');
        $this->passport($person, compact('type', 'status'));

        $identity = app(NiumHkKycIdentityResolver::class)->resolve($person->fresh());

        $this->assertSame('passport', $identity['type']);
        $this->assertSame('P1234567', $identity['identification_number']);
        $this->assertSame('GB', $identity['issuance_country']);
        $this->assertSame('2099-12-31', $identity['expiry_date']);
        $this->assertTrue($identity['is_resident']);
    }

    public static function eligiblePassports(): array
    {
        return [['passport', 'approved'], ['passport_front', 'approved'], ['passport', 'verified']];
    }

    public function test_non_hk_residence_is_not_resident(): void
    {
        $person = $this->person('VN');
        $this->passport($person);
        $this->assertFalse(app(NiumHkKycIdentityResolver::class)->resolve($person->fresh())['is_resident']);
    }

    #[DataProvider('invalidDocuments')]
    public function test_rejects_invalid_or_ineligible_document(array $overrides): void
    {
        $person = $this->person(array_key_exists('residence', $overrides) ? $overrides['residence'] : 'HK');
        unset($overrides['residence']);
        $this->passport($person, $overrides);
        $this->expectException(RuntimeException::class);
        app(NiumHkKycIdentityResolver::class)->resolve($person->fresh());
    }

    public static function invalidDocuments(): array
    {
        return [
            'expired' => [['expires_at' => '2020-01-01']],
            'missing number' => [['document_number' => null]],
            'missing country' => [['issuing_country_code' => null]],
            'invalid country' => [['issuing_country_code' => 'VNM']],
            'missing residence' => [['residence' => null]],
            'invalid residence' => [['residence' => 'HKG']],
            'national id' => [['type' => 'national_id']],
            'unapproved' => [['status' => 'pending']],
        ];
    }

    public function test_superseded_passport_is_not_authoritative(): void
    {
        $person = $this->person('HK');
        $old = $this->passport($person, ['document_number' => 'OLD']);
        $this->passport($person, ['document_number' => 'NEW', 'metadata' => ['previous_document_id' => $old->id]]);
        $this->assertSame('NEW', app(NiumHkKycIdentityResolver::class)->resolve($person->fresh())['identification_number']);
    }

    public function test_valid_legacy_factual_identity_is_a_fallback(): void
    {
        $person = $this->person('VN', ['nium_biometric_identity' => $this->legacyIdentity()]);
        $identity = app(NiumHkKycIdentityResolver::class)->resolve($person);
        $this->assertSame('LEGACY-1', $identity['identification_number']);
        $this->assertFalse($identity['is_resident']);
    }

    public function test_equivalent_document_remains_authoritative_over_legacy_metadata(): void
    {
        $legacy = $this->legacyIdentity(['identification_number' => 'P1234567', 'issuance_country' => 'GB']);
        $person = $this->person('HK', ['nium_biometric_identity' => $legacy]);
        $this->passport($person);
        $identity = app(NiumHkKycIdentityResolver::class)->resolve($person->fresh());
        $this->assertSame('P1234567', $identity['identification_number']);
        $this->assertTrue($identity['is_resident']);
    }

    public function test_conflicting_document_and_legacy_metadata_fail_closed(): void
    {
        $person = $this->person('HK', ['nium_biometric_identity' => $this->legacyIdentity()]);
        $this->passport($person);
        $this->expectException(RuntimeException::class);
        app(NiumHkKycIdentityResolver::class)->resolve($person->fresh());
    }

    private function person(?string $residence, array $metadata = []): KycRelatedPerson
    {
        $user = User::factory()->create();
        $profile = KycProfile::query()->create([
            'user_id' => $user->id, 'status' => 'approved', 'applicant_type' => 'business',
            'legal_name' => 'Test Company', 'address_line1' => '1 Test Road',
            'city' => 'Hong Kong', 'country_code' => 'HK',
        ]);
        return $profile->relatedPersons()->create([
            'relationship_type' => 'applicant', 'status' => 'approved', 'legal_name' => 'Test Person',
            'ownership_percentage' => 100, 'residence_country_code' => $residence, 'metadata' => $metadata,
        ]);
    }

    private function passport(KycRelatedPerson $person, array $overrides = [])
    {
        return $person->documents()->create(array_merge([
            'kyc_profile_id' => $person->kyc_profile_id, 'type' => 'passport', 'status' => 'approved',
            'document_number' => 'P1234567', 'issuing_country_code' => 'GB', 'expires_at' => '2099-12-31',
            'file_url' => 'private://passport', 'metadata' => [],
        ], $overrides));
    }

    private function legacyIdentity(array $overrides = []): array
    {
        return array_merge([
            'type' => 'passport', 'identification_number' => 'LEGACY-1', 'issuance_country' => 'VN',
            'expiry_date' => '2099-12-31', 'factual' => true, 'synthetic' => false,
            'source' => 'operator_verified_factual_identity_v1',
        ], $overrides);
    }
}
