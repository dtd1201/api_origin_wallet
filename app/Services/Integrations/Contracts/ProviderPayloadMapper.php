<?php

namespace App\Services\Integrations\Contracts;

use App\Models\User;

interface ProviderPayloadMapper
{
    public function buildCustomerPayload(User $user): array;

    public function buildAccountPayload(User $user, array $customerResponse): array;

    public function extractCustomerId(array $customerResponse): ?string;

    public function extractAccountId(array $accountResponse): ?string;

    public function extractAccountName(User $user, array $accountResponse): ?string;
}
