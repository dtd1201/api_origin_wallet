<?php

namespace Tests\Unit;

use App\Models\KycDocument;
use App\Services\Nium\NiumHkSubmitKycPayloadFactory;
use App\Services\Nium\NiumHkSubmitKycValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class NiumHkSubmitKycValidatorTest extends TestCase
{
    #[DataProvider('residencyValues')]
    public function test_manual_applicant_factory_emits_strict_residency_and_address_schema(bool $isResident): void
    {
        $document = new KycDocument([
            'document_number' => 'P1234567',
            'issuing_country_code' => 'HK',
            'expires_at' => now()->addYear()->format('Y-m-d'),
            'metadata' => [
                'nium_file_id' => '11111111-1111-4111-8111-111111111111',
                'nium_file_state' => 'AVAILABLE',
            ],
        ]);

        $payload = app(NiumHkSubmitKycPayloadFactory::class)->buildApplicantManual(
            $document,
            'existing-provider-reference',
            $isResident,
            ['type' => 'proof_of_address', 'fileIds' => ['22222222-2222-4222-8222-222222222222']],
        );

        $this->assertSame($isResident, $payload['isResident']);
        $this->assertSame('proof_of_address', $payload['proofOfAddressDocument']['type']);
    }

    public static function residencyValues(): array
    {
        return ['resident' => [true], 'nonresident' => [false]];
    }

    public function test_hk_applicant_manual_contract_accepts_passport_and_optional_proof_of_address(): void
    {
        $payload = $this->payload();
        $payload['proofOfAddressDocument'] = [
            'type' => 'proof_of_address',
            'fileIds' => ['22222222-2222-4222-8222-222222222222'],
        ];

        app(NiumHkSubmitKycValidator::class)->assertManualApplicant($payload);

        $this->addToAssertionCount(1);
    }

    #[DataProvider('invalidPayloads')]
    public function test_hk_applicant_manual_contract_rejects_unsupported_or_incomplete_fields(callable $mutate): void
    {
        $payload = $this->payload();
        $mutate($payload);

        $this->expectException(RuntimeException::class);
        app(NiumHkSubmitKycValidator::class)->assertManualApplicant($payload);
    }

    public static function invalidPayloads(): array
    {
        return [
            'missing isResident' => [function (array &$payload): void {
                unset($payload['isResident']);
            }],
            'non-boolean isResident' => [fn (array &$payload) => $payload['isResident'] = 'false'],
            'missing file ids' => [function (array &$payload): void {
                unset($payload['proofOfIdentityDocument'][0]['fileIds']);
            }],
            'empty file ids' => [fn (array &$payload) => $payload['proofOfIdentityDocument'][0]['fileIds'] = []],
            'invalid file id' => [fn (array &$payload) => $payload['proofOfIdentityDocument'][0]['fileIds'] = ['not-a-uuid']],
            'invalid passport number' => [fn (array &$payload) => $payload['proofOfIdentityDocument'][0]['identificationNumber'] = 'P 123'],
            'invalid address type' => [fn (array &$payload) => $payload['proofOfAddressDocument'] = [
                'type' => 'utility_bill', 'fileIds' => ['22222222-2222-4222-8222-222222222222'],
            ]],
        ];
    }

    private function payload(): array
    {
        return [
            'region' => 'HK',
            'entityType' => 'applicant',
            'entityReferenceId' => 'existing-provider-reference',
            'kycMode' => 'manual_kyc',
            'isResident' => false,
            'proofOfIdentityDocument' => [[
                'type' => 'passport',
                'identificationNumber' => 'P1234567',
                'issuanceCountry' => 'HK',
                'expiryDate' => now()->addYear()->format('Y-m-d'),
                'fileIds' => ['11111111-1111-4111-8111-111111111111'],
            ]],
        ];
    }
}
