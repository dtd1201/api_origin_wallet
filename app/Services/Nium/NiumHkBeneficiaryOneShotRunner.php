<?php

namespace App\Services\Nium;

use App\Models\Beneficiary;
use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\UserProviderAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class NiumHkBeneficiaryOneShotRunner
{
    private const ACCOUNT_ID = 7;
    private const PROTECTED_ACCOUNT_ID = 4;
    private const CLAIM_KEY = 'nium_beneficiary_first_payout_v1';

    public function __construct(
        private readonly NiumBeneficiaryService $beneficiaryService,
        private readonly NiumBeneficiaryExecutionAuthorization $executionAuthorization,
    ) {}

    public function run(int $beneficiaryId, array $approvedTuple, bool $humanApproved): array
    {
        if (! $humanApproved) {
            throw new RuntimeException('Separate human approval is required for Add Beneficiary.');
        }

        $protected = $this->fingerprint(UserProviderAccount::query()->findOrFail(self::PROTECTED_ACCOUNT_ID));
        $preflight = Beneficiary::query()->findOrFail($beneficiaryId);
        if ((bool) data_get($preflight->raw_data, 'nium.verify_before_create', false)) {
            throw new RuntimeException('HOLD_ACCOUNT_VERIFICATION_SEPARATE_APPROVAL_REQUIRED');
        }
        $this->beneficiaryService->assertReadyForCreate($preflight);
        $preparationSha = $this->beneficiaryService->preparationFingerprint($preflight);
        $baseline = (int) (ApiRequestLog::query()->max('id') ?? 0);
        $context = $this->claim($beneficiaryId, $approvedTuple, $baseline, $preparationSha);

        try {
            $this->executionAuthorization->authorize(
                self::ACCOUNT_ID,
                $beneficiaryId,
                $context['tuple_sha256'],
                $context['schema_sha256'],
                $context['preparation_sha256'],
            );
            $beneficiary = $this->beneficiaryService->createBeneficiary($context['provider'], $context['beneficiary']);
            $terminal = 'CREATED';
        } catch (ConnectionException|NiumEvidencePersistenceException) {
            $terminal = 'OUTCOME_UNKNOWN_NO_RETRY';
            $beneficiary = Beneficiary::query()->findOrFail($beneficiaryId);
        } catch (Throwable $exception) {
            $beneficiary = Beneficiary::query()->findOrFail($beneficiaryId);
            $terminal = $beneficiary->status === 'rejected_no_retry'
                ? 'REJECTED_NO_RETRY'
                : 'OUTCOME_UNKNOWN_NO_RETRY';
        } finally {
            $this->executionAuthorization->revoke();
        }

        [$terminal, $evidence] = $this->classifyEvidence($context['account'], $baseline, $terminal, $beneficiary);
        if ($this->fingerprint(UserProviderAccount::query()->findOrFail(self::PROTECTED_ACCOUNT_ID)) !== $protected) {
            $terminal = 'OUTCOME_UNKNOWN_NO_RETRY';
            $this->finish($beneficiaryId, $terminal, $evidence);
            throw new RuntimeException('Protected Account 4 changed during beneficiary execution.');
        }
        $this->finish($beneficiaryId, $terminal, $evidence);

        return ['terminal' => $terminal, 'beneficiary_id' => $beneficiary->id];
    }

    private function claim(int $beneficiaryId, array $tuple, int $baseline, string $preflightPreparationSha): array
    {
        return DB::transaction(function () use ($beneficiaryId, $tuple, $baseline, $preflightPreparationSha): array {
            $account = UserProviderAccount::query()->whereKey(self::ACCOUNT_ID)->lockForUpdate()->firstOrFail();
            $beneficiary = Beneficiary::query()->whereKey($beneficiaryId)->lockForUpdate()->firstOrFail();
            if ($beneficiary->user_id !== $account->user_id || $beneficiary->provider_id !== $account->provider_id) {
                throw new RuntimeException('Beneficiary is not bound to exact Account 7.');
            }
            if ((bool) data_get($beneficiary->raw_data, 'nium.verify_before_create', false)) {
                throw new RuntimeException('HOLD_ACCOUNT_VERIFICATION_SEPARATE_APPROVAL_REQUIRED');
            }

            $lockedPreparationSha = $this->beneficiaryService->preparationFingerprint($beneficiary);
            $approvedPreparationSha = (string) data_get($beneficiary->raw_data, 'nium.schema_approval.beneficiary_preparation_sha256');
            if (! hash_equals($preflightPreparationSha, $lockedPreparationSha)
                || ! hash_equals($approvedPreparationSha, $lockedPreparationSha)) {
                throw new RuntimeException('HOLD_BENEFICIARY_PREPARATION_CHANGED');
            }

            $expected = [
                'beneficiary_id' => $beneficiary->id,
                'destinationCountry' => strtoupper((string) $beneficiary->country_code),
                'destinationCurrency' => strtoupper((string) $beneficiary->currency),
                'payoutMethod' => strtoupper((string) data_get($beneficiary->raw_data, 'nium.payoutMethod')),
                'schema_sha256' => (string) data_get($beneficiary->raw_data, 'nium.schema_approval.schema_sha256'),
                'beneficiary_preparation_sha256' => $lockedPreparationSha,
            ];
            if ($tuple !== $expected) {
                throw new RuntimeException('Approved beneficiary/corridor tuple does not match Account 7 facts.');
            }
            if (filled($beneficiary->external_beneficiary_id)) {
                throw new RuntimeException('An accepted beneficiary already exists for the approved tuple.');
            }
            $acceptedExists = Beneficiary::query()
                ->where('user_id', $beneficiary->user_id)
                ->where('provider_id', $beneficiary->provider_id)
                ->where('country_code', $beneficiary->country_code)
                ->where('currency', $beneficiary->currency)
                ->whereNotNull('external_beneficiary_id')
                ->get()
                ->contains(fn (Beneficiary $item) => strtoupper((string) data_get($item->raw_data, 'nium.payoutMethod')) === $expected['payoutMethod']);
            if ($acceptedExists) {
                throw new RuntimeException('An accepted beneficiary already exists for the approved tuple.');
            }

            $metadata = (array) $account->metadata;
            if (isset($metadata[self::CLAIM_KEY])) {
                throw new RuntimeException('HOLD_BENEFICIARY_ALREADY_CLAIMED');
            }
            $tupleSha = hash('sha256', json_encode($tuple));
            $claim = [
                'state' => 'submitting',
                'beneficiary_id' => $beneficiary->id,
                'tuple_sha256' => $tupleSha,
                'schema_sha256' => $expected['schema_sha256'],
                'beneficiary_preparation_sha256' => $lockedPreparationSha,
                'provider_log_baseline_id' => $baseline,
                'claimed_at' => now()->toISOString(),
            ];
            $metadata[self::CLAIM_KEY] = $claim;
            $account->update(['metadata' => $metadata]);
            $raw = (array) $beneficiary->raw_data;
            $raw['nium']['one_shot_claim'] = $claim;
            $beneficiary->update(['raw_data' => $raw, 'status' => 'submitting']);

            return [
                'account' => $account->fresh(),
                'beneficiary' => $beneficiary->fresh('user.profile'),
                'provider' => IntegrationProvider::query()->findOrFail($account->provider_id),
                'tuple_sha256' => $tupleSha,
                'schema_sha256' => $expected['schema_sha256'],
                'preparation_sha256' => $lockedPreparationSha,
            ];
        });
    }

    private function finish(int $beneficiaryId, string $terminal, array $evidence): void
    {
        DB::transaction(function () use ($beneficiaryId, $terminal, $evidence): void {
            $account = UserProviderAccount::query()->whereKey(self::ACCOUNT_ID)->lockForUpdate()->firstOrFail();
            $metadata = (array) $account->metadata;
            $metadata[self::CLAIM_KEY]['state'] = strtolower($terminal);
            $metadata[self::CLAIM_KEY]['finished_at'] = now()->toISOString();
            $metadata[self::CLAIM_KEY] = [...$metadata[self::CLAIM_KEY], ...$evidence];
            $account->update(['metadata' => $metadata]);
            $beneficiary = Beneficiary::query()->whereKey($beneficiaryId)->lockForUpdate()->firstOrFail();
            $raw = (array) $beneficiary->raw_data;
            $raw['nium']['one_shot_claim'] = $metadata[self::CLAIM_KEY];
            $beneficiary->update(['raw_data' => $raw]);
        });
    }

    private function classifyEvidence(UserProviderAccount $account, int $baseline, string $terminal, Beneficiary $beneficiary): array
    {
        $logs = ApiRequestLog::query()
            ->where('id', '>', $baseline)
            ->where('provider_id', $account->provider_id)
            ->where('user_id', $account->user_id)
            ->where('request_method', 'POST')
            ->where('operation', 'beneficiary_create')
            ->where('endpoint_path', 'like', '%/beneficiaries')
            ->orderBy('id')
            ->get();
        $log = $logs->count() === 1 ? $logs->first() : null;

        $evidence = [
            'provider_http_status' => $log?->response_status,
            'transport_outcome' => $log?->transport_outcome ?? 'ambiguous',
            'api_request_log_id' => $log?->id,
        ];
        if ($logs->count() !== 1) {
            return ['OUTCOME_UNKNOWN_NO_RETRY', $evidence];
        }

        $status = (int) $log->response_status;
        $definiteRejection = $status >= 400 && $status < 500 && ! in_array($status, [408, 429], true)
            && $log->transport_outcome === 'response_received';
        $created = $status >= 200 && $status < 300 && $log->transport_outcome === 'response_received'
            && filled($beneficiary->external_beneficiary_id);

        return match (true) {
            $terminal === 'CREATED' && $created => ['CREATED', $evidence],
            $terminal === 'REJECTED_NO_RETRY' && $definiteRejection => ['REJECTED_NO_RETRY', $evidence],
            default => ['OUTCOME_UNKNOWN_NO_RETRY', $evidence],
        };
    }

    private function fingerprint(UserProviderAccount $account): string
    {
        return hash('sha256', json_encode($account->getAttributes()));
    }
}
