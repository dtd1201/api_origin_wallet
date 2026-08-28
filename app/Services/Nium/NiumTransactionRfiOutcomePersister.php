<?php

namespace App\Services\Nium;

use App\Models\NiumRfiCase;

class NiumTransactionRfiOutcomePersister
{
    public function persistClaimedOutcome(int $caseId, string $submissionState, array $evidence): int
    {
        return NiumRfiCase::query()
            ->whereKey($caseId)
            ->where('submission_state', 'claimed')
            ->update([
                'submission_state' => $submissionState,
                'provider_response_evidence' => $evidence,
                'reconciled_at' => now(),
            ]);
    }
}
