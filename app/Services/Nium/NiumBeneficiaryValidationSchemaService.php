<?php

namespace App\Services\Nium;

use App\Models\User;
use RuntimeException;

final class NiumBeneficiaryValidationSchemaService
{
    public function __construct(
        private readonly NiumService $niumService,
        private readonly NiumBeneficiaryAccountResolver $accountResolver,
    ) {}

    public function fetchRaw(User $user, string $currencyCode, string $payoutMethod, string $destinationCountry): string
    {
        foreach (compact('currencyCode', 'payoutMethod', 'destinationCountry') as $value) {
            if (! filled($value)) {
                throw new RuntimeException('Nium beneficiary validation schema dimensions must be explicit.');
            }
        }

        $account = $this->accountResolver->resolve($user, requireWallet: false);
        $response = $this->niumService->get(
            path: $this->niumService->path(
                (string) config('services.nium.beneficiary_validation_schema_endpoint'),
                [
                    'client' => $this->niumService->clientId(),
                    'customer' => (string) $account->external_customer_id,
                    'currencyCode' => strtoupper($currencyCode),
                ],
            ),
            query: [
                'payoutMethod' => strtoupper($payoutMethod),
                'destinationCountry' => strtoupper($destinationCountry),
            ],
            user: $user,
        );

        if (! $response->successful()) {
            throw new RuntimeException('Nium beneficiary validation schema lookup failed.');
        }

        return $response->body();
    }

    public function metadata(string $schema): array
    {
        return ['sha256' => hash('sha256', $schema), 'length' => strlen($schema)];
    }

    public function approval(
        string $rawSchema,
        string $currencyCode,
        string $destinationCountry,
        string $payoutMethod,
        array $approvedFields,
        array $requiredFields,
        string $reviewedAt,
        string $beneficiaryPreparationSha256,
    ): array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $beneficiaryPreparationSha256) !== 1) {
            throw new RuntimeException('HOLD_BENEFICIARY_PREPARATION_FINGERPRINT_INVALID');
        }

        $metadata = $this->metadata($rawSchema);

        return [
            'schema_sha256' => $metadata['sha256'],
            'schema_length' => $metadata['length'],
            'beneficiary_preparation_sha256' => $beneficiaryPreparationSha256,
            'currency_code' => strtoupper($currencyCode),
            'destination_country' => strtoupper($destinationCountry),
            'payout_method' => strtoupper($payoutMethod),
            'approved_fields' => array_values($approvedFields),
            'required_fields' => array_values($requiredFields),
            'reviewed_at' => $reviewedAt,
            'review_source' => 'human_reviewed_factual_nium_schema',
        ];
    }
}
