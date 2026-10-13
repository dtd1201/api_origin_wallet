<?php

namespace App\Services\Kyc;

use App\Models\UserProviderAccount;

final class KycCorrectionSyncPolicy
{
    public function evaluate(?UserProviderAccount $account): array
    {
        if (! config('services.nium.customer_update_enabled', false)) {
            return ['allowed' => false, 'reason' => 'Nium Customer Update is disabled; this correction cannot be approved yet.'];
        }
        $status = strtolower((string) $account?->provider_status);
        if (! in_array($status, (array) config('services.nium.customer_update_allowed_statuses', []), true)) {
            return ['allowed' => false, 'reason' => "Nium status [{$status}] is not approved for Customer Update."];
        }
        if ($account?->security_conflict_at !== null || filled($account?->security_conflict_reason)) {
            return ['allowed' => false, 'reason' => 'The provider account has a security conflict.'];
        }

        return ['allowed' => true, 'reason' => null];
    }
}
