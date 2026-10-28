<?php

namespace App\Services\Nium;

use App\Models\NiumVirtualAccountOperation as Operation;

final class NiumVirtualAccountOperationState
{
    public static function isTerminal(string $state): bool
    {
        return in_array($state, [Operation::ASSIGNED, Operation::FAILED], true);
    }

    public static function isUnresolved(string $state): bool
    {
        return in_array($state, [
            Operation::CLAIMED, Operation::PREFLIGHT_FAILED, Operation::SUBMITTING,
            Operation::PENDING, Operation::UNKNOWN, Operation::MANUAL_REVIEW,
        ], true);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, match ($from) {
            Operation::CLAIMED => [Operation::SUBMITTING],
            Operation::SUBMITTING => [Operation::PENDING, Operation::UNKNOWN, Operation::MANUAL_REVIEW, Operation::ASSIGNED, Operation::FAILED],
            Operation::PENDING => [Operation::UNKNOWN, Operation::MANUAL_REVIEW, Operation::ASSIGNED, Operation::FAILED],
            Operation::UNKNOWN => [Operation::MANUAL_REVIEW, Operation::ASSIGNED, Operation::FAILED],
            Operation::MANUAL_REVIEW => [Operation::ASSIGNED, Operation::FAILED],
            default => [],
        }, true);
    }

    /** Generic legacy FAILED rows do not establish that a provider POST was rejected. */
    public static function hasConclusiveLegacyAssignment(Operation $operation): bool
    {
        return $operation->status === Operation::ASSIGNED && $operation->resolved_at !== null
            && NiumPaymentIdValidator::isValid($operation->provider_payment_id);
    }
}
