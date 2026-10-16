<?php

namespace App\Services\Nium;

use App\Models\NiumRfiCase;
use App\Models\UserProviderAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use RuntimeException;

final class NiumCorporateRfiAuthoritativeSubmissionService
{
    public function __construct(
        private readonly NiumCorporateRfiPostEnvelopeAdapter $adapter,
        private readonly NiumRfiWorkflowService $workflow,
        private readonly NiumService $niumService,
    ) {}

    public function submit(NiumRfiCase $case): NiumRfiCase
    {
        if (config('services.nium.customer_rfi_authoritative_submission_enabled') !== true) {
            throw new RuntimeException('NIUM_RFI_AUTHORITATIVE_SUBMISSION_DISABLED: authoritative Corporate RFI POST is not activated.');
        }

        $this->workflow->assertProviderContractConfirmed($case);
        if ($case->origin !== 'authoritative_fetch') {
            throw new RuntimeException('Authoritative Corporate RFI submission requires authoritative provider evidence.');
        }

        $envelope = $this->adapter->prepare($case);
        if (($envelope['executable'] ?? null) !== false) {
            throw new RuntimeException('Authoritative Corporate RFI submission requires the reviewed non-executable candidate contract.');
        }

        $account = UserProviderAccount::query()->with('user')->whereKey($case->user_provider_account_id)
            ->where('provider_id', $case->provider_id)->firstOrFail();
        if ($account->user === null) {
            throw new RuntimeException('Authoritative Corporate RFI submission requires a local customer owner.');
        }

        // Complete every deterministic endpoint and provider configuration check before claiming.
        $this->niumService->assertConfigured();
        $endpoint = $this->niumService->path(
            (string) data_get($envelope, 'transport.endpointTemplate'),
            (array) data_get($envelope, 'transport.pathParameters', []),
        );
        $templateFingerprint = hash('sha256', (string) data_get($envelope, 'reviewMetadata.templateId'));
        $requestId = (string) Str::uuid();
        $claimed = $this->workflow->claimForProviderSubmission($case, $requestId, [
            'submission_mode' => 'authoritative_legacy',
            'provider_reference_fingerprint' => $case->provider_reference_fingerprint,
            'template_fingerprint' => $templateFingerprint,
            'outcome' => 'PENDING',
            'reason' => 'provider_submission_claimed',
            'manual_reconciliation_required' => true,
        ]);

        try {
            $response = $this->niumService->postSensitiveWithRequestId(
                $endpoint,
                $envelope['candidateBody'],
                $account->user,
                $requestId,
                'corporate_rfi_authoritative_submit',
                $claimed->provider_reference_fingerprint,
            );
        } catch (ConnectionException) {
            return $this->persist($claimed, 'unknown', $requestId, $templateFingerprint, null, 'UNKNOWN', 'network_or_transport_uncertainty', true);
        } catch (NiumEvidencePersistenceException $exception) {
            $status = filter_var($exception->safeEvidence['http_status'] ?? null, FILTER_VALIDATE_INT);
            $responseReceived = is_int($status);

            return $this->persist(
                $claimed,
                'unknown',
                $requestId,
                $templateFingerprint,
                $responseReceived ? $status : null,
                'UNKNOWN',
                $responseReceived
                    ? 'provider_response_received_but_evidence_persistence_failed'
                    : 'transport_evidence_persistence_failed_after_attempt',
                true,
            );
        }

        $status = $response->status();
        if (in_array($status, [400, 422], true)) {
            return $this->persist($claimed, 'rejected', $requestId, $templateFingerprint, $status, 'REJECTED', 'deterministic_validation_rejection', false);
        }
        if (in_array($status, [408, 429], true) || $status >= 500) {
            return $this->persist($claimed, 'unknown', $requestId, $templateFingerprint, $status, 'UNKNOWN', 'provider_outcome_uncertain', true);
        }
        if ($status !== 200 || ! is_array($response->json())) {
            return $this->persist($claimed, 'unknown', $requestId, $templateFingerprint, $status, 'UNKNOWN', 'unexpected_or_malformed_provider_response', true);
        }

        $providerStatus = strtoupper(trim((string) data_get($response->json(), 'status')));
        if ($providerStatus !== 'RFI_RESPONDED') {
            return $this->persist($claimed, 'unknown', $requestId, $templateFingerprint, $status, 'UNKNOWN', 'unconfirmed_provider_acknowledgement', true);
        }

        return $this->persist($claimed, 'provider_acknowledged', $requestId, $templateFingerprint, $status, 'ACKNOWLEDGED', 'awaiting_authoritative_get_reconciliation', true);
    }

    private function persist(
        NiumRfiCase $case,
        string $state,
        string $requestId,
        string $templateFingerprint,
        ?int $httpStatus,
        string $outcome,
        string $reason,
        bool $reconcile,
    ): NiumRfiCase {
        $affected = NiumRfiCase::query()->whereKey($case->id)->where('submission_state', 'claimed')->update([
            'submission_state' => $state,
            'provider_response_evidence' => array_filter([
                'submission_mode' => 'authoritative_legacy',
                'x_request_id' => $requestId,
                'request_correlation_fingerprint' => hash('sha256', $requestId),
                'provider_reference_fingerprint' => $case->provider_reference_fingerprint,
                'template_fingerprint' => $templateFingerprint,
                'http_status' => $httpStatus,
                'outcome' => $outcome,
                'reason' => $reason,
                'manual_reconciliation_required' => $reconcile,
                'recorded_at' => now()->toISOString(),
            ], static fn (mixed $value): bool => $value !== null),
        ]);
        if ($affected !== 1) {
            throw new RuntimeException('Authoritative Corporate RFI outcome requires manual reconciliation after persistence uncertainty.');
        }

        return $case->fresh();
    }
}
