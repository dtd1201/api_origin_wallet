<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nium\DynamicConfirmation\DynamicBeneficiaryDetailsResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NiumDynamicBeneficiaryDetailsResponseTest extends TestCase
{
    private function registration(): array
    {
        return ['clientLegalEntity' => 'HK', 'beneficiaryName' => 'Synthetic Payee',
            'beneficiaryAccountType' => 'Individual', 'beneficiaryBankName' => 'Synthetic Bank',
            'beneficiaryBankAccountType' => 'Savings', 'beneficiaryAccountNumber' => '0012345678',
            'routingCodeType1' => 'SWIFT', 'routingCodeValue1' => 'TESTSGSGXXX',
            'destinationCountry' => 'SG', 'destinationCurrency' => 'SGD', 'payoutMethod' => 'LOCAL'];
    }

    private function response(array $changes = []): string
    {
        $fields = $this->registration();
        unset($fields['clientLegalEntity']);
        return json_encode(['beneficiaryHashId' => 'synthetic-payee', ...$fields,
            'beneficiaryBankCode' => null, 'routingCodeType2' => null, 'routingCodeValue2' => null,
            ...$changes], JSON_THROW_ON_ERROR);
    }

    public function test_direct_object_compares_full_values_without_inventing_entity_or_normalizing_zeros(): void
    {
        $matched = DynamicBeneficiaryDetailsResponse::verify($this->response(), $this->registration(),
            'synthetic-payee', 'synthetic-client', 'synthetic-customer');
        $this->assertSame('0012345678', $matched['beneficiaryAccountNumber']);
        $this->assertSame('TESTSGSGXXX', $matched['routingCodeValue1']);
        $this->assertArrayNotHasKey('clientLegalEntity', $matched);
    }

    public static function opaqueReceivingFormats(): array
    {
        return [
            ['HK', 'HKD', 'HK-ACCOUNT-A01', 'SWIFT', 'TESTHKHHXXX'],
            ['GB', 'GBP', '00012345', 'SORT CODE', '12-34-56'],
            ['DE', 'EUR', 'DE89370400440532013000', 'BANK CODE', '37040044'],
            ['JP', 'JPY', '0123456', 'BRANCH CODE', '001'],
            ['TH', 'THB', '123-4-56789-0', 'BANK CODE', '004'],
        ];
    }

    #[DataProvider('opaqueReceivingFormats')]
    public function test_comparison_is_generic_and_does_not_supply_country_format_rules(
        string $country, string $currency, string $account, string $routingType, string $routing): void
    {
        $registered = [...$this->registration(), 'destinationCountry' => $country, 'destinationCurrency' => $currency,
            'beneficiaryAccountNumber' => $account, 'routingCodeType1' => $routingType, 'routingCodeValue1' => $routing];
        unset($registered['beneficiaryBankName'], $registered['beneficiaryBankAccountType']);
        $response = $registered;
        unset($response['clientLegalEntity']);
        $matched = DynamicBeneficiaryDetailsResponse::verify(json_encode(['beneficiaryHashId' => 'synthetic-payee', ...$response], JSON_THROW_ON_ERROR),
            $registered, 'synthetic-payee', 'synthetic-client', 'synthetic-customer');
        $this->assertSame($account, $matched['beneficiaryAccountNumber']);
        $this->assertSame($routing, $matched['routingCodeValue1']);
    }

    #[DataProvider('opaqueReceivingFormats')]
    public function test_missing_masked_and_conflicting_values_are_rejected_for_each_format(
        string $country, string $currency, string $account, string $routingType, string $routing): void
    {
        $registered = [...$this->registration(), 'destinationCountry' => $country, 'destinationCurrency' => $currency,
            'beneficiaryAccountNumber' => $account, 'routingCodeType1' => $routingType, 'routingCodeValue1' => $routing];
        $response = $registered;
        unset($response['clientLegalEntity']);
        foreach (['missing', 'masked', 'conflicting'] as $case) {
            $candidate = $response;
            if ($case === 'missing') unset($candidate['beneficiaryAccountNumber']);
            if ($case === 'masked') $candidate['routingCodeValue1'] = 'XXXX'.substr($routing, -2);
            if ($case === 'conflicting') $candidate['beneficiaryAccountNumber'] = $account.'9';
            try {
                DynamicBeneficiaryDetailsResponse::verify(json_encode(['beneficiaryHashId' => 'synthetic-payee', ...$candidate], JSON_THROW_ON_ERROR),
                    $registered, 'synthetic-payee', 'synthetic-client', 'synthetic-customer');
                $this->fail('Unverifiable receiving details must be refused: '.$case);
            } catch (RuntimeException $exception) {
                $this->assertSame('dynamic_registered_account_customer_scope_unavailable', $exception->getMessage());
            }
        }
    }

    public static function mismatches(): array
    {
        return [
            [['beneficiaryHashId' => 'foreign']], [['destinationCountry' => 'HK']],
            [['destinationCurrency' => 'USD']], [['payoutMethod' => 'SWIFT']],
            [['beneficiaryName' => 'Other Payee']], [['beneficiaryAccountType' => 'Corporate']],
            [['beneficiaryBankName' => 'Other Bank']], [['beneficiaryBankAccountType' => 'Checking']],
            [['beneficiaryAccountNumber' => '12345678']], [['beneficiaryAccountNumber' => 12345678]],
            [['routingCodeType1' => 'SWIFT CODE']], [['routingCodeValue1' => 'OTHERSSGXXX']],
            [['beneficiaryBankCode' => '123']], [['routingCodeType2' => 'SORT CODE', 'routingCodeValue2' => '123456']],
            [['customerHashId' => 'foreign']], [['clientHashId' => null]], [['clientLegalEntity' => 'SG']],
            [['beneficiaryAccountNumber' => null]], [['routingCodeValue1' => null]],
        ];
    }

    #[DataProvider('mismatches')]
    public function test_conflicting_null_or_wrongly_typed_values_are_refused(array $changes): void
    {
        $this->expectException(RuntimeException::class);
        DynamicBeneficiaryDetailsResponse::verify($this->response($changes), $this->registration(),
            'synthetic-payee', 'synthetic-client', 'synthetic-customer');
    }

    public static function maskedValues(): array
    {
        return [['beneficiaryAccountNumber', '******5678'], ['beneficiaryAccountNumber', 'XXXX5678'],
            ['beneficiaryAccountNumber', '••••5678'], ['beneficiaryAccountNumber', '1234...5678'],
            ['routingCodeValue1', 'XXXXSGSGXXX'], ['routingCodeValue1', 'TEST****XXX'],
            ['beneficiaryName', '[REDACTED]']];
    }

    #[DataProvider('maskedValues')]
    public function test_even_matching_placeholders_in_registration_and_details_cannot_establish_binding(string $field, string $value): void
    {
        $registered = [...$this->registration(), $field => $value];
        $this->expectException(RuntimeException::class);
        DynamicBeneficiaryDetailsResponse::verify($this->response([$field => $value]), $registered,
            'synthetic-payee', 'synthetic-client', 'synthetic-customer');
    }

    public static function invalidBodies(): array
    {
        return [['null'], ['[]'], ['{"data":{}}'], ['{'],
            ['{"beneficiaryHashId":"synthetic-payee","beneficiaryHashId":"foreign"}']];
    }

    #[DataProvider('invalidBodies')]
    public function test_malformed_wrapped_or_duplicate_key_responses_are_refused(string $body): void
    {
        $this->expectException(RuntimeException::class);
        DynamicBeneficiaryDetailsResponse::verify($body, $this->registration(), 'synthetic-payee', 'synthetic-client', 'synthetic-customer');
    }

    public function test_missing_required_receiving_field_is_not_a_scope_attestation(): void
    {
        $response = json_decode($this->response(), true, 64, JSON_THROW_ON_ERROR);
        unset($response['routingCodeValue1']);
        $this->expectException(RuntimeException::class);
        DynamicBeneficiaryDetailsResponse::verify(json_encode($response, JSON_THROW_ON_ERROR), $this->registration(),
            'synthetic-payee', 'synthetic-client', 'synthetic-customer');
    }

    public function test_provider_mask_flag_cannot_be_overruled_by_equal_strings(): void
    {
        $this->expectException(RuntimeException::class);
        DynamicBeneficiaryDetailsResponse::verify($this->response(['accountNumberMasked' => true]), $this->registration(),
            'synthetic-payee', 'synthetic-client', 'synthetic-customer');
    }

    public function test_additional_registration_fields_need_exact_echoes_not_guessed_aliases(): void
    {
        $this->expectException(RuntimeException::class);
        DynamicBeneficiaryDetailsResponse::verify($this->response(['address' => 'Synthetic Road']),
            [...$this->registration(), 'beneficiaryAddress' => 'Synthetic Road'], 'synthetic-payee', 'synthetic-client', 'synthetic-customer');
    }
}
