<?php

namespace App\Jobs\Nium;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\KycProviderSubmission;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Compliance\ComplianceEvidenceService;
use App\Services\Nium\NiumCustomerDocumentPreparationService;
use App\Services\Nium\NiumCustomerOnboardingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ContinueNiumCustomerOnboardingJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 20;

    /** @var list<int> */
    public array $backoff = [30, 60, 120, 300];

    public int $uniqueFor = 3600;

    private string $continuationId;

    public function __construct(
        public readonly int $userId,
        public readonly int $providerId,
    ) {
        $this->continuationId = (string) Str::uuid();
    }

    public function uniqueId(): string
    {
        return $this->userId.':'.$this->providerId;
    }

    public function handle(
        NiumCustomerDocumentPreparationService $documentPreparationService,
        NiumCustomerOnboardingService $onboardingService,
        ComplianceEvidenceService $complianceEvidenceService,
    ): void {
        $attempt = $this->attempts();
        try {
            $user = User::query()->findOrFail($this->userId);
            $provider = IntegrationProvider::query()->findOrFail($this->providerId);

            if (strtolower((string) $provider->code) !== 'nium') {
                throw new RuntimeException('Nium onboarding continuation requires the Nium provider.');
            }

            $providerAccount = UserProviderAccount::query()
                ->where('user_id', $user->id)
                ->where('provider_id', $provider->id)
                ->first();

            if ($providerAccount === null) {
                $this->logAttempt($attempt, 'provider_account_missing', 0);

                throw new RuntimeException('Nium onboarding continuation requires an existing provider account.');
            }

            if (filled($providerAccount->external_customer_id)) {
                $this->logAttempt($attempt, 'customer_exists', 0);

                return;
            }

            $preparation = $documentPreparationService->prepare($user);
            $pendingDocumentCount = (int) ($preparation['pending_document_count'] ?? 0);

            if ($pendingDocumentCount > 0) {
                $this->logAttempt($attempt, 'documents_processing', $pendingDocumentCount);
                $this->release($this->retryDelay($attempt));

                return;
            }

            $providerAccount = $onboardingService->syncUser($provider, $user);
            $state = filled($providerAccount->external_customer_id)
                ? 'customer_created'
                : 'customer_creation_deferred';

            if (filled($providerAccount->external_customer_id)) {
                $submission = KycProviderSubmission::query()
                    ->where('user_id', $user->id)
                    ->where('provider_id', $provider->id)
                    ->first();

                if ($submission !== null && $submission->status !== 'submitted') {
                    $complianceEvidenceService->markNiumSubmissionSubmitted($submission, $providerAccount->id);
                }
            }

            $this->logAttempt($attempt, $state, 0);
        } catch (Throwable $exception) {
            $this->logAttempt($attempt, 'continuation_failed', 0);

            throw $exception;
        }
    }

    private function retryDelay(int $attempt): int
    {
        return $this->backoff[min(max($attempt - 1, 0), count($this->backoff) - 1)];
    }

    private function logAttempt(int $attempt, string $state, int $pendingDocumentCount): void
    {
        ApiRequestLog::query()->firstOrCreate(
            [
                'provider_id' => $this->providerId,
                'user_id' => $this->userId,
                'operation' => 'nium_customer_onboarding_continuation',
                'external_reference' => $this->continuationId.':'.$attempt,
            ],
            [
                'request_method' => 'QUEUE',
                'request_url' => 'queue://nium/customer-onboarding/continuation',
                'endpoint_path' => 'nium/customer-onboarding/continuation',
                'request_body' => [
                    'user_id' => $this->userId,
                    'provider_id' => $this->providerId,
                    'attempt' => $attempt,
                    'state' => $state,
                    'pending_document_count' => $pendingDocumentCount,
                ],
                'transport_outcome' => $state,
                'is_success' => in_array($state, ['customer_exists', 'customer_created'], true),
            ],
        );
    }
}
