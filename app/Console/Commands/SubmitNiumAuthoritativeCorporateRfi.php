<?php

namespace App\Console\Commands;

use App\Models\IntegrationProvider;
use App\Models\NiumRfiCase;
use App\Services\Nium\NiumCorporateRfiAuthoritativeSubmissionService;
use App\Services\Nium\NiumCorporateRfiSubmissionService;
use App\Services\Nium\NiumRfiWorkflowService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class SubmitNiumAuthoritativeCorporateRfi extends Command
{
    public const APPROVAL = 'SUBMIT_NIUM_AUTHORITATIVE_CORPORATE_RFI';

    protected $signature = 'nium:submit-authoritative-corporate-rfi
        {case : Exact Nium RFI case database ID}
        {--submit : Submit the single validated case; otherwise inspect only}
        {--expect-reference-fingerprint= : Exact expected provider-reference SHA-256 fingerprint}
        {--approve= : Exact human approval marker required with --submit}
        {--operator= : Required operator and ticket context with --submit}';

    protected $description = 'Inspect or explicitly submit one approved authoritative Nium Legacy Corporate RFI';

    public function handle(
        NiumCorporateRfiSubmissionService $inspection,
        NiumCorporateRfiAuthoritativeSubmissionService $submission,
        NiumRfiWorkflowService $workflow,
    ): int {
        $caseId = filter_var($this->argument('case'), FILTER_VALIDATE_INT);
        if (! is_int($caseId) || $caseId < 1) {
            $this->error('A positive case ID is required.');

            return self::FAILURE;
        }

        try {
            $case = $this->loadEligibleCase($caseId);
            $inspection->preparePostEnvelope($case);

            if (! (bool) $this->option('submit')) {
                $this->safeResult($case, 'INSPECTED');

                return self::SUCCESS;
            }

            $this->assertOperatorAuthorization($case);
            if (config('services.nium.customer_rfi_authoritative_submission_enabled') !== true) {
                throw new RuntimeException('Authoritative Corporate RFI submission is disabled.');
            }
            $workflow->assertProviderContractConfirmed($case);

            if (! $this->confirm("Submit authoritative Corporate RFI case {$caseId}?", false)) {
                throw new RuntimeException('Operator confirmation was not granted.');
            }

            // Re-read after confirmation so stale provider or approval state cannot be submitted.
            $result = $submission->submit($this->loadEligibleCase($caseId));
            $this->safeResult($result, strtoupper((string) data_get($result->provider_response_evidence, 'outcome', 'UNKNOWN')));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Corporate RFI command refused the requested operation: '.$this->safeFailure($exception));

            return self::FAILURE;
        }
    }

    private function loadEligibleCase(int $caseId): NiumRfiCase
    {
        $case = NiumRfiCase::query()->findOrFail($caseId);
        $provider = IntegrationProvider::query()->find($case->provider_id);
        if ($provider === null || strtolower(trim((string) $provider->code)) !== 'nium' || $provider->status !== 'active') {
            throw new RuntimeException('provider identity is not eligible');
        }
        if ($case->scope !== 'customer' || $case->transaction_id !== null || $case->origin !== 'authoritative_fetch') {
            throw new RuntimeException('case is not an authoritative customer RFI');
        }
        if ($case->status !== 'requested'
            || strtoupper(trim((string) data_get($case->evidence, 'rfiStatus'))) !== 'RFI_REQUESTED') {
            throw new RuntimeException('authoritative RFI is no longer requested');
        }
        if (! in_array($case->submission_state, ['approved', 'admin_approved'], true)
            || $case->approved_at === null || $case->claimed_at !== null) {
            throw new RuntimeException('case is not approved and unclaimed');
        }
        $rfiHashId = data_get($case->evidence, 'rfiHashId');
        if (! is_string($rfiHashId)
            || ! hash_equals(hash('sha256', $rfiHashId), (string) $case->provider_reference_fingerprint)) {
            throw new RuntimeException('provider reference fingerprint does not match');
        }

        return $case;
    }

    private function assertOperatorAuthorization(NiumRfiCase $case): void
    {
        if ((string) $this->option('approve') !== self::APPROVAL) {
            throw new RuntimeException('exact approval marker is required');
        }
        if (trim((string) $this->option('operator')) === '') {
            throw new RuntimeException('operator and ticket context are required');
        }
        $expected = strtolower(trim((string) $this->option('expect-reference-fingerprint')));
        if (preg_match('/^[a-f0-9]{64}$/', $expected) !== 1
            || ! hash_equals(strtolower((string) $case->provider_reference_fingerprint), $expected)) {
            throw new RuntimeException('expected provider-reference fingerprint does not match');
        }
    }

    private function safeResult(NiumRfiCase $case, string $outcome): void
    {
        $correlation = data_get($case->provider_response_evidence, 'request_correlation_fingerprint');
        $correlation = is_string($correlation) && preg_match('/^[a-f0-9]{64}$/', $correlation) === 1
            ? $correlation
            : 'none';
        $state = preg_match('/^[a-z_]{2,40}$/', (string) $case->submission_state) === 1
            ? $case->submission_state
            : 'unknown';
        $outcome = preg_match('/^[A-Z_]{2,40}$/', $outcome) === 1 ? $outcome : 'UNKNOWN';
        $this->info("case_id={$case->id} state={$state} outcome={$outcome} correlation={$correlation}");
    }

    private function safeFailure(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        return match (true) {
            str_contains($message, 'disabled') => 'submission disabled',
            str_contains($message, 'contract') => 'contract gate rejected',
            str_contains($message, 'confirmation') => 'operator confirmation rejected',
            str_contains($message, 'approval marker'), str_contains($message, 'operator and ticket') => 'operator authorization rejected',
            str_contains($message, 'fingerprint') => 'case identity rejected',
            str_contains($message, 'no longer requested') => 'case is stale',
            str_contains($message, 'authoritative customer'), str_contains($message, 'provider identity') => 'case selection rejected',
            str_contains($message, 'approved and unclaimed'), str_contains($message, 'human approval') => 'case state rejected',
            default => 'validated operation failed',
        };
    }
}
