<?php

namespace App\Services\Integrations;

use RuntimeException;

class ProviderOnboardingEligibilityException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly string $failedGate,
        public readonly int $customerId,
        public readonly int $providerId,
        public readonly ?int $providerSubmissionId,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function context(): array
    {
        return [
            'reason_code' => $this->reasonCode,
            'failed_gate' => $this->failedGate,
            'customer_id' => $this->customerId,
            'provider_id' => $this->providerId,
            'provider_submission_id' => $this->providerSubmissionId,
        ];
    }
}
