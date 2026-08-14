<?php

namespace App\Services\Nium;

final class NiumBeneficiaryExecutionAuthorization
{
    private ?array $authorization = null;

    public function authorize(int $accountId, int $beneficiaryId, string $tupleSha256, string $schemaSha256, string $preparationSha256): void
    {
        $this->authorization = compact('accountId', 'beneficiaryId', 'tupleSha256', 'schemaSha256', 'preparationSha256');
    }

    public function revoke(): void
    {
        $this->authorization = null;
    }

    public function allows(int $accountId, int $beneficiaryId, string $tupleSha256, string $schemaSha256, string $preparationSha256): bool
    {
        return $this->authorization === compact('accountId', 'beneficiaryId', 'tupleSha256', 'schemaSha256', 'preparationSha256');
    }
}
