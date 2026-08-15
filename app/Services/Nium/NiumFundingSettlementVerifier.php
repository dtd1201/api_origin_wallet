<?php

namespace App\Services\Nium;

final class NiumFundingSettlementVerifier
{
    public function verify(array $transaction): array
    {
        $status = strtoupper(trim((string) ($transaction['status'] ?? '')));
        $complianceStatus = strtoupper(trim((string) ($transaction['complianceStatus'] ?? '')));
        $reviewRequired = in_array($complianceStatus, ['RFI_REQUESTED', 'ACTION_REQUIRED'], true);

        return [
            'accepted' => $status === 'APPROVED' && $complianceStatus === 'SETTLED',
            'reviewRequired' => $reviewRequired,
            'status' => $status,
            'complianceStatus' => $complianceStatus,
        ];
    }
}
