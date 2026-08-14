<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\NiumVirtualAccount;
use App\Models\UserProviderAccount;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class NiumHkPaymentIdOneShotRunner
{
    private const ACCOUNT_ID = 7;
    private const PROTECTED_ACCOUNT_ID = 4;
    private const USER_ID = 9;
    private const CLAIM_KEY = 'nium_assign_payment_id_one_shot_v1';

    public function __construct(
        private readonly NiumPaymentIdService $paymentIdService,
        private readonly NiumProviderAccountStateService $accountStateService,
    ) {}

    public function audit(
        string $currencyCode,
        string $accountCategory,
        string $bankName,
    ): array {
        $contract = $this->contract($currencyCode, $accountCategory, $bankName);
        $account = $this->validatedAccount();
        $protectedFingerprint = $this->fingerprint(UserProviderAccount::query()->findOrFail(self::PROTECTED_ACCOUNT_ID));
        $this->assertPreconditions($account, $contract);
        $this->assertProtectedAccount($protectedFingerprint);

        return [
            'terminal' => 'HOLD_VAN_CONTRACT_NOT_PROVEN',
            'payload_fingerprint' => $contract['payload_fingerprint'],
            'assign_payment_id_post_count' => 0,
            'db_write_count' => 0,
        ];
    }

    public function run(
        string $currencyCode,
        string $accountCategory,
        string $bankName,
        bool $separateHumanApproval = false,
    ): array {
        if (! $separateHumanApproval) {
            throw new RuntimeException('Separate human approval of the exact Account 7 VAN tuple is required.');
        }

        $contract = $this->contract($currencyCode, $accountCategory, $bankName);
        $account = $this->validatedAccount();
        $providerId = (int) $account->provider_id;
        $protectedFingerprint = $this->fingerprint(UserProviderAccount::query()->findOrFail(self::PROTECTED_ACCOUNT_ID));
        $logMaxId = (int) (ApiRequestLog::query()->max('id') ?? 0);
        $this->claim($contract);

        try {
            $virtualAccount = $this->paymentIdService->assign(
                $account,
                $contract['currency_code'],
                $contract['account_category'],
                $contract['bank_name'],
            );
        } catch (Throwable) {
            $log = $this->newOperationLog($providerId, $logMaxId);
            $rejected = $log !== null
                && (int) $log->response_status >= 400
                && (int) $log->response_status < 500
                && $log->transport_outcome === 'response_received';
            $this->mark(
                $rejected ? 'rejected' : 'outcome_unknown',
                $log?->response_status,
                $log?->transport_outcome ?? 'ambiguous',
                $log?->id,
            );

            return $this->finish(
                $rejected ? 'REJECTED_NO_RETRY' : 'OUTCOME_UNKNOWN_NO_RETRY',
                $protectedFingerprint,
                $providerId,
                $logMaxId,
            );
        }

        $log = $this->newOperationLog($providerId, $logMaxId);
        if ($log === null || ! $log->is_success || (int) $log->response_status < 200
            || (int) $log->response_status >= 300 || $log->transport_outcome !== 'response_received') {
            $this->mark('outcome_unknown', $log?->response_status, $log?->transport_outcome ?? 'ambiguous', $log?->id);

            return $this->finish('OUTCOME_UNKNOWN_NO_RETRY', $protectedFingerprint, $providerId, $logMaxId);
        }

        $this->mark('assigned', (int) $log->response_status, 'response_received', (int) $log->id);

        return [
            ...$this->finish('ASSIGNED', $protectedFingerprint, $providerId, $logMaxId),
            'virtual_account_id' => $virtualAccount->id,
        ];
    }

    private function validatedAccount(): UserProviderAccount
    {
        $account = UserProviderAccount::query()->with('user')->whereKey(self::ACCOUNT_ID)
            ->where('user_id', self::USER_ID)->firstOrFail();
        $eligible = $this->accountStateService->assertEligible($account->user);
        if ((int) $eligible->getKey() !== self::ACCOUNT_ID
            || (int) $eligible->provider_id !== (int) $account->provider_id
            || (int) $eligible->user_id !== self::USER_ID) {
            throw new RuntimeException('Account 7 is not the exact authoritative eligible Nium account.');
        }

        return $account;
    }

    private function assertPreconditions(UserProviderAccount $account, array $contract): void
    {
        $metadata = (array) $account->metadata;
        if (array_key_exists(self::CLAIM_KEY, $metadata)) {
            throw new RuntimeException('HOLD_ASSIGN_PAYMENT_ID_ONE_SHOT_ALREADY_CLAIMED');
        }

        if (ApiRequestLog::query()->where('provider_id', $account->provider_id)->where('user_id', self::USER_ID)
            ->where('operation', 'assign_payment_id')->where('request_method', 'POST')->exists()) {
            throw new RuntimeException('HOLD_ASSIGN_PAYMENT_ID_OPERATION_HISTORY_EXISTS');
        }

        if (NiumVirtualAccount::query()->where('user_provider_account_id', self::ACCOUNT_ID)
            ->where('currency', $contract['currency_code'])->where('account_category', $contract['account_category'])
            ->whereNull('account_type')->where('status', 'assigned')->exists()) {
            throw new RuntimeException('HOLD_ASSIGN_PAYMENT_ID_TUPLE_ALREADY_ASSIGNED');
        }
    }

    private function claim(array $contract): void
    {
        DB::transaction(function () use ($contract): void {
            $account = UserProviderAccount::query()->with('user')->whereKey(self::ACCOUNT_ID)
                ->where('user_id', self::USER_ID)->lockForUpdate()->firstOrFail();
            $eligible = $this->accountStateService->assertEligible($account->user);
            if ((int) $eligible->getKey() !== self::ACCOUNT_ID
                || (int) $eligible->provider_id !== (int) $account->provider_id
                || (int) $eligible->user_id !== self::USER_ID) {
                throw new RuntimeException('Account 7 is not the exact authoritative eligible Nium account.');
            }
            $this->assertPreconditions($account, $contract);

            $metadata = (array) $account->metadata;
            $metadata[self::CLAIM_KEY] = array_filter([
                'version' => 1,
                'state' => 'submitting',
                'currency_code' => $contract['currency_code'],
                'account_category' => $contract['account_category'],
                'bank_name_fingerprint' => $contract['bank_name_fingerprint'],
                'payload_fingerprint' => $contract['payload_fingerprint'],
                'created_at' => now()->utc()->toISOString(),
                'updated_at' => now()->utc()->toISOString(),
            ], static fn (mixed $value): bool => $value !== null);
            $account->forceFill(['metadata' => $metadata])->save();
        });
    }

    private function mark(string $state, ?int $status, string $transport, ?int $logId): void
    {
        DB::transaction(function () use ($state, $status, $transport, $logId): void {
            $account = UserProviderAccount::query()->whereKey(self::ACCOUNT_ID)->lockForUpdate()->firstOrFail();
            $metadata = (array) $account->metadata;
            $metadata[self::CLAIM_KEY] = array_filter([
                ...($metadata[self::CLAIM_KEY] ?? []),
                'state' => $state,
                'provider_http_status' => $status,
                'transport_outcome' => $transport,
                'api_request_log_id' => $logId,
                'updated_at' => now()->utc()->toISOString(),
            ], static fn (mixed $value): bool => $value !== null);
            $account->forceFill(['metadata' => $metadata])->save();
        });
    }

    private function finish(string $terminal, string $protectedFingerprint, int $providerId, int $logMaxId): array
    {
        $posts = ApiRequestLog::query()->where('id', '>', $logMaxId)->where('provider_id', $providerId)
            ->where('user_id', self::USER_ID)
            ->where('operation', 'assign_payment_id')->where('request_method', 'POST')->count();
        $this->assertProtectedAccount($protectedFingerprint);
        if ($posts > 1 || ($terminal === 'ASSIGNED' && $posts !== 1)) {
            throw new RuntimeException('HOLD_ASSIGN_PAYMENT_ID_POSTCONDITION_FAILED');
        }

        return ['terminal' => $terminal, 'assign_payment_id_post_count' => $posts];
    }

    private function newOperationLog(int $providerId, int $logMaxId): ?ApiRequestLog
    {
        return ApiRequestLog::query()->where('id', '>', $logMaxId)->where('provider_id', $providerId)
            ->where('user_id', self::USER_ID)
            ->where('operation', 'assign_payment_id')->where('request_method', 'POST')->orderByDesc('id')->first();
    }

    private function contract(string $currencyCode, string $accountCategory, string $bankName): array
    {
        $currencyCode = strtoupper(trim($currencyCode));
        $accountCategory = strtoupper(trim($accountCategory));
        $bankName = trim($bankName);
        if (preg_match('/^[A-Z]{3}$/', $currencyCode) !== 1
            || ! in_array($accountCategory, ['SELF_FUNDING_ACCOUNT', 'COLLECTION_ACCOUNT'], true)
            || $bankName === '' || strlen($bankName) > 64
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.-]*$/', $bankName) !== 1) {
            throw new RuntimeException('Invalid explicit Nium Assign Payment ID tuple.');
        }

        $payload = [
            'bankName' => $bankName,
            'currencyCode' => $currencyCode,
            'accountCategory' => $accountCategory,
        ];

        return [
            'currency_code' => $currencyCode,
            'account_category' => $accountCategory,
            'bank_name' => $bankName,
            'bank_name_fingerprint' => substr(hash('sha256', $bankName), 0, 16),
            'payload_fingerprint' => substr(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 0, 16),
        ];
    }

    private function assertProtectedAccount(string $fingerprint): void
    {
        if ($this->fingerprint(UserProviderAccount::query()->findOrFail(self::PROTECTED_ACCOUNT_ID)) !== $fingerprint) {
            throw new RuntimeException('Protected Account 4 changed.');
        }
    }

    private function fingerprint(UserProviderAccount $account): string
    {
        $attributes = $account->getRawOriginal();
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
    }
}
