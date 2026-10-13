<?php

namespace Tests\Unit;

use App\Services\Nium\NiumHkSubmitKycValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class NiumHkSubmitKycValidatorTest extends TestCase
{
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
            'isResident is outside HK manual schema' => [fn (array &$payload) => $payload['isResident'] = true],
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
