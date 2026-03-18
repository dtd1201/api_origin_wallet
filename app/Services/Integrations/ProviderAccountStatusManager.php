<?php

namespace App\Services\Integrations;

use App\Models\UserProviderAccount;

class ProviderAccountStatusManager
{
    public function syncUserStatusFromProviderAccount(UserProviderAccount $providerAccount): void
    {
        $user = $providerAccount->user;

        if ($user === null) {
            return;
        }

        $normalizedStatus = strtolower((string) $providerAccount->status);

        if (in_array($normalizedStatus, ['active', 'approved', 'verified', 'completed'], true)) {
            $user->update([
                'status' => 'active',
                'kyc_status' => 'verified',
            ]);

            return;
        }

        if (in_array($normalizedStatus, ['rejected', 'failed', 'declined'], true)) {
            $user->update([
                'status' => 'pending',
                'kyc_status' => 'rejected',
            ]);
        }
    }

    public function normalizeProviderAccountSubmissionStatus(?string $status): string
    {
        $normalizedStatus = strtolower((string) $status);

        return match ($normalizedStatus) {
            'active', 'approved', 'verified', 'completed' => 'active',
            'rejected', 'declined' => 'rejected',
            'under_review', 'reviewing' => 'under_review',
            'failed', 'error' => 'failed',
            default => 'submitted',
        };
    }
}
