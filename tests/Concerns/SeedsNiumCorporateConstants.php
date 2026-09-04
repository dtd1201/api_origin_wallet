<?php

namespace Tests\Concerns;

use App\Models\NiumCorporateConstant;

trait SeedsNiumCorporateConstants
{
    protected function seedNiumCorporateConstants(): void
    {
        $categories = [
            'businessType' => ['PRIVATE_COMPANY', 'private_company'],
            'industrySector' => ['is112', 'IS144'],
            'countryName' => ['HK', 'SG', 'VN', 'IN', 'GB', 'US'],
            'countryOfOperation' => ['HK', 'SG', 'VN', 'IN', 'GB', 'US'],
            'intendedUseOfAccount' => ['iu002', 'iu003', 'IU003'],
            'annualTurnover' => ['SG011'],
            'totalEmployees' => ['EM009'],
            'averageTransactionValue' => ['tc001', 'ATVSG01', 'ATVSG02'],
            'monthlyTransactionVolume' => ['eu008', 'MVSG05', 'MVSG10'],
            'monthlyTransactions' => ['tc001', 'ATC02', 'ATC03'],
            'documentType' => [
                'passport', 'national_id', 'drivers_licence', 'business_registration_doc',
                'nar1', 'nnc1', 'proof_of_address', 'proof_of_business_address', 'ownership_chart',
                'bank_statement', 'utility_bill', 'tax_document', 'loa',
            ],
        ];

        foreach (['AU', 'EU', 'HK', 'NL', 'SG', 'UK', 'US', 'VN'] as $region) {
            foreach ($categories as $category => $codes) {
                NiumCorporateConstant::query()->updateOrCreate([
                    'region' => $region,
                    'customer_type' => 'CORPORATE',
                    'country_code' => '',
                    'constant_type' => $category,
                ], [
                    'values' => collect($codes)->map(fn (string $code): array => [
                        'label' => "Nium fixture {$code}",
                        'value' => $code,
                    ])->all(),
                    'fetched_at' => now(),
                    'expires_at' => now()->addDay(),
                ]);
            }
        }
    }
}
