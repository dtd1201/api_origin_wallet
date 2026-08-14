<?php

namespace App\Services\Nium;

use App\Models\Beneficiary;
use Illuminate\Support\Arr;
use RuntimeException;

final class NiumBeneficiaryListService
{
    public function __construct(
        private readonly NiumService $niumService,
        private readonly NiumBeneficiaryAccountResolver $accountResolver,
    ) {}

    public function list(Beneficiary $beneficiary, array $filters): array
    {
        $beneficiary->loadMissing('user');
        $account = $this->accountResolver->resolve($beneficiary->user, $beneficiary->provider_id, false);
        $allowed = Arr::only($filters, ['beneficiaryName', 'beneficiaryAccountNumber', 'destinationCurrency', 'payoutMethod']);
        $response = $this->niumService->get(
            path: $this->niumService->path(
                (string) config('services.nium.beneficiary_endpoint'),
                ['client' => $this->niumService->clientId(), 'customer' => (string) $account->external_customer_id],
            ),
            query: array_filter($allowed, fn ($value) => filled($value)),
            user: $beneficiary->user,
        );

        $data = $response->json();
        if (! $response->successful() || ! is_array($data)) {
            throw new RuntimeException('Nium beneficiary list reconciliation failed.');
        }

        return array_is_list($data) ? $data : (array) ($data['content'] ?? $data['data'] ?? []);
    }

    public function reconcile(Beneficiary $beneficiary, string $payoutMethod): Beneficiary
    {
        $rows = $this->list($beneficiary, [
            'beneficiaryName' => $beneficiary->company_name ?: $beneficiary->full_name,
            'beneficiaryAccountNumber' => $beneficiary->account_number ?: $beneficiary->iban,
            'destinationCurrency' => $beneficiary->currency,
            'payoutMethod' => $payoutMethod,
        ]);
        $row = collect($rows)->first(fn ($item) => is_array($item)
            && (string) ($item['beneficiaryHashId'] ?? '') === (string) $beneficiary->external_beneficiary_id
            && strtoupper((string) ($item['destinationCountry'] ?? '')) === strtoupper((string) $beneficiary->country_code)
            && strtoupper((string) ($item['destinationCurrency'] ?? '')) === strtoupper((string) $beneficiary->currency)
            && strtoupper((string) ($item['payoutMethod'] ?? '')) === strtoupper($payoutMethod));

        if (! is_array($row) || ! filled($row['payoutHashId'] ?? null)) {
            throw new RuntimeException('Nium beneficiary list did not confirm the exact payout tuple.');
        }

        $raw = (array) $beneficiary->raw_data;
        $raw['nium']['reconciliation'] = [
            'beneficiaryHashId' => (string) $row['beneficiaryHashId'],
            'payoutHashId' => (string) $row['payoutHashId'],
            'confirmed_at' => now()->toISOString(),
        ];
        $beneficiary->update(['raw_data' => $raw]);

        return $beneficiary->fresh();
    }
}
