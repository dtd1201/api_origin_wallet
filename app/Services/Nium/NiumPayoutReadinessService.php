<?php

namespace App\Services\Nium;

use App\Models\NiumComplianceEvent;
use App\Models\Transaction;

final class NiumPayoutReadinessService
{
    public function report(array $orchestrationEvidence, ?Transaction $transaction = null): array
    {
        $stages = [
            'supported_corridor' => ['selected_corridor'],
            'beneficiary_requirements' => ['requirements_schema'],
            'beneficiary_validation' => ['beneficiary_validated'],
            'beneficiary' => ['beneficiary_created'],
            'transfer_validation' => ['transfer_validated'],
            'transfer' => ['transfer_submitted'],
            'transaction_compliance' => ['persisted_transaction_compliance_clear'],
            'transaction_lifecycle' => ['transaction_terminal'],
        ];
        $result = [];
        $upstreamPassed = true;
        foreach ($stages as $stage => $requirements) {
            $evidence = $orchestrationEvidence;
            $evidence['persisted_transaction_compliance_clear'] = $this->transactionComplianceClear($transaction);
            $missing = array_values(array_filter($requirements, static fn ($key) => ($evidence[$key] ?? false) !== true));
            if (! $upstreamPassed) {
                $result[$stage] = ['status' => 'HOLD', 'blockers' => ['upstream_stage_incomplete']];
                continue;
            }
            $result[$stage] = $missing === [] ? ['status' => 'PASS', 'blockers' => []] : ['status' => 'HOLD', 'blockers' => $missing];
            $upstreamPassed = $missing === [];
        }
        return $result;
    }

    private function transactionComplianceClear(?Transaction $transaction): bool
    {
        if ($transaction === null || ! $transaction->exists || $transaction->compliance_review_required
            || ! in_array(strtoupper((string) $transaction->compliance_status), ['CLEAR', 'COMPLETED', 'RESOLVED', 'APPROVED'], true)) {
            return false;
        }

        return ! NiumComplianceEvent::query()->where('transaction_id', $transaction->id)
            ->where(function ($query): void {
                $query->where('requires_action', true)->orWhere('review_status', 'pending');
            })->exists();
    }
}
