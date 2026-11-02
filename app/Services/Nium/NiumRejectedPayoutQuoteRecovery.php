<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\Balance;
use App\Models\FxQuote;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Incident #5 only. Local evidence review/reset; never provider I/O or payment authority. */
final class NiumRejectedPayoutQuoteRecovery
{
    public const REQUEST = 'e12e1acc-1dde-4f87-835c-93f3b3875524';

    public const REFERENCE = 'OW-0de5a925-391a-41bd-8322-7a8c7e782fad';

    public const ERROR = ['code' => 'fx_constraint_violated_input',
        'description' => 'Conversion schedule cannot be immediate for execution type manual'];

    public function recover(User $operator, array $clearance, string $providerArtifact, bool $apply = false): Transfer
    {
        // A successful CLOSED+exclusive lease prevents reopening and cooperating quote
        // writers for the entire check/commit. Never wait while holding database locks.
        $directory = config('sgd_admission.directory');
        if (! is_string($directory) || realpath($directory) !== $directory || is_link($directory)) {
            throw new RuntimeException('A verified closed admission fence is required.');
        }
        $path = $directory.'/drain.lock';
        $lease = ! is_link($path) ? @fopen($path, 'rb') : false;
        if ($lease === false) {
            throw new RuntimeException('Admission drain is unavailable.');
        }
        try {
            $opened = fstat($lease);
            $current = @lstat($path);
            if (! flock($lease, LOCK_EX | LOCK_NB) || $current === false
                || $opened['ino'] !== $current['ino'] || $opened['dev'] !== $current['dev']
                || ($opened['mode'] & 0170000) !== 0100000 || is_link($directory.'/state')
                || @file_get_contents($directory.'/state') !== "CLOSED v1\n") {
                throw new RuntimeException('SGD must be closed and all cooperating operations drained.');
            }

            return DB::transaction(function () use ($operator, $clearance, $providerArtifact, $apply): Transfer {
                $operator = User::query()->findOrFail($operator->id);
                if ($operator->id === 12 || $operator->status !== 'active' || ! $operator->hasPermission('transfers.approve')) {
                    throw new RuntimeException('An active independent authorized recovery operator is required.');
                }
                $locked = Transfer::query()->lockForUpdate()->findOrFail(5);
                app(NiumSgdLocalCugScope::class)->assertTransfer($locked);
                app(NiumSgdLocalPayoutWorkflow::class)->assertIntent($locked);
                if ($locked->user_id !== 12 || $locked->provider_id !== 10 || $locked->beneficiary_id !== 9
                    || NiumSgdLocalCugScope::principal($locked->source_amount) !== '5'
                    || $locked->purpose_code !== 'IR008' || $locked->client_reference !== self::REFERENCE) {
                    throw new RuntimeException('Transfer does not match the reviewed incident.');
                }
                $this->assertClearance($clearance, $operator);
                if ($providerArtifact === '' || strlen($providerArtifact) > 16777216
                    || ! hash_equals($clearance['provider_review']['artifact_sha256'], hash('sha256', $providerArtifact))) {
                    throw new RuntimeException('The independently reviewed provider artifact is missing or changed.');
                }
                $clearanceHash = hash('sha256', json_encode($clearance, JSON_THROW_ON_ERROR));
                $workflow = $locked->raw_data['sgd_local_workflow'];
                $receipt = $workflow['rejected_quote_recoveries'][self::REQUEST] ?? null;
                if ($receipt !== null) {
                    if (($receipt['clearance_sha256'] ?? null) !== $clearanceHash) {
                        throw new RuntimeException('Recovery is already recorded with different evidence.');
                    }

                    return $locked; // An identical replay never changes a subsequent review/payment.
                }
                if ($locked->status !== 'draft' || ($workflow['state'] ?? null) !== 'lock_unknown'
                    || ($workflow['lock_request_id'] ?? null) !== self::REQUEST
                    || $locked->fx_quote_id !== null || $locked->provider_operation_key !== null
                    || $locked->external_transfer_id !== null || $locked->fx_rate !== null
                    || $locked->submitted_at !== null || $locked->completed_at !== null
                    || $locked->approvals()->exists() || isset($workflow['customer_authorization'])
                    || isset($workflow['review']) || isset($locked->raw_data['sgd_local_approval'])
                    || isset($locked->raw_data['nium_execution'])) {
                    throw new RuntimeException('The incident has payment authority or a conflicting operation.');
                }
                $context = app(NiumPayoutFxLockService::class)->context($locked->provider, $locked->user);
                $logs = ApiRequestLog::query()->where('provider_id', 10)->where('user_id', 12)
                    ->where('request_headers->x-request-id', self::REQUEST)->lockForUpdate()->get();
                if ($logs->count() !== 1 || $logs->sole()->id !== 4763) {
                    throw new RuntimeException('A single matching original audit record is required.');
                }
                $log = $logs->sole();
                $evidence = $log->niumExchangeEvidence()->lockForUpdate()->first();
                $endpoint = app(NiumService::class)->path((string) config('services.nium.quote_endpoint'), ['client' => '[REDACTED]']);
                $expectedUrl = rtrim($context['base_url'], '/').'/'.ltrim(str_replace(urlencode('[REDACTED]'), '[REDACTED]', $endpoint), '/');
                if ($log->operation !== 'create_payout_fx_lock' || $log->request_method !== 'POST'
                    || $log->request_url !== $expectedUrl || $log->client_hash_id !== $context['client']
                    || $log->response_status !== 400 || $log->is_success !== false
                    || $log->transport_outcome !== 'response_received'
                    || ($log->response_body['response_received'] ?? null) !== true
                    || ($log->related_transfer_id !== null && $log->related_transfer_id !== 5)
                    || $evidence === null || $evidence->capture_version !== 1 || ! $evidence->response_received
                    || $evidence->request_method !== 'POST' || $evidence->request_url !== $expectedUrl
                    || ($evidence->request_headers['x-request-id'] ?? null) !== [self::REQUEST]) {
                    throw new RuntimeException('The authoritative exchange is missing, uncertain or contradictory.');
                }
                $request = $evidence->raw_request_body;
                $response = $evidence->raw_response_body;
                foreach (['request' => $request, 'response' => $response] as $kind => $body) {
                    if (! is_string($body) || ! hash_equals((string) $evidence->{$kind.'_body_sha256'}, hash('sha256', $body))
                        || ($clearance[$kind.'_body_sha256'] ?? null) !== hash('sha256', $body)) {
                        throw new RuntimeException('Captured exchange integrity or clearance binding failed.');
                    }
                }
                $payload = json_decode($request, true, 512, JSON_THROW_ON_ERROR);
                $expected = NiumPayoutQuoteContract::request($context['customer'], 'USD', 'SGD', '5');
                $expected['executionType'] = 'manual'; // Validate the rejected historical request, never send it.
                $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
                if (! NiumPayoutFxLockContract::sameBinding($payload, $expected)
                    || (! NiumPayoutFxLockContract::sameBinding($data, self::ERROR)
                        && ! NiumPayoutFxLockContract::sameBinding($data, ['errors' => [self::ERROR]]))) {
                    throw new RuntimeException('Only the exact captured validation rejection is recoverable.');
                }
                $since = $log->request_started_at;
                if ($since === null || $log->request_finished_at === null || $log->request_finished_at->lessThan($since)
                    || ! isset($workflow['lock_requested_at'])
                    || abs($since->diffInSeconds($workflow['lock_requested_at'], false)) > 60) {
                    throw new RuntimeException('Original request timing is not bound to this workflow.');
                }
                // Include the whole claim/start second: created_at may have only second precision.
                $since = Carbon::parse($workflow['lock_requested_at'])->min($since)->startOfSecond();
                // Serialize wallet inspection too. The fence excludes cooperating new SGD claims.
                $balances = Balance::query()->where('provider_id', 10)->where('user_id', 12)->lockForUpdate()->get();
                if ($balances->isEmpty() || $balances->contains(fn ($b) => bccomp($b->reserved_balance, '0', 8) !== 0)
                    || ApiRequestLog::query()->where('related_transfer_id', 5)
                        ->where(fn ($q) => $q->where('operation', 'transfer_money')->orWhere('request_url', 'like', '%/remittance'))->exists()
                    || FxQuote::query()->where('provider_id', 10)->where('user_id', 12)
                        ->where(function ($q) use ($since): void {
                            $q->where('created_at', '>=', $since)->orWhere('raw_data->lock_request_uuid', self::REQUEST)
                                ->orWhere('expires_at', '>', now());
                        })->exists()
                    || LedgerEntry::query()
                        ->where(fn ($q) => $q->where('reference', 'like', 'transfer:5:%')
                            ->orWhere(fn ($q) => $q->where('source_type', 'transfer')->where('source_id', '5'))
                            ->orWhere(fn ($q) => $q->where('provider_id', 10)->where('user_id', 12)->where('created_at', '>=', $since)))->exists()
                    || Transfer::query()->where('provider_id', 10)->where('user_id', 12)->whereKeyNot(5)
                        ->where(function ($q): void {
                            $q->whereIn('status', ['submitting', 'submission_unknown', 'pending'])
                                ->orWhereIn('raw_data->sgd_local_workflow->state', ['locking', 'lock_unknown', 'locked']);
                        })->exists()
                    || ApiRequestLog::query()->where('provider_id', 10)->where('user_id', 12)->whereKeyNot(4763)
                        ->where('created_at', '>=', $since)
                        ->where(fn ($q) => $q->where('operation', 'create_payout_fx_lock')->orWhere('operation', 'transfer_money')
                            ->orWhere('request_method', '!=', 'GET'))->exists()) {
                    throw new RuntimeException('Quote, wallet, ledger or provider-operation evidence conflicts with recovery.');
                }
                if ($apply) {
                    $workflow['rejected_quote_recoveries'][self::REQUEST] = [
                        'api_request_log_id' => 4763, 'request_uuid' => self::REQUEST,
                        'request_body_sha256' => $evidence->request_body_sha256,
                        'response_body_sha256' => $evidence->response_body_sha256,
                        'clearance_sha256' => $clearanceHash, 'provider_review' => $clearance['provider_review'],
                        'operator_id' => $operator->id, 'recovered_at' => now()->toISOString(),
                        'original_attempt' => $workflow,
                    ];
                    $workflow['state'] = 'draft'; // A new customer Review is permitted; no quote/consent created.
                    $locked->update(['raw_data' => array_merge($locked->raw_data, ['sgd_local_workflow' => $workflow])]);
                }

                return $locked;
            });
        } finally {
            flock($lease, LOCK_UN);
            fclose($lease);
        }
    }

    private function assertClearance(array $clearance, User $operator): void
    {
        $review = $clearance['provider_review'] ?? [];
        if (($clearance['transfer_id'] ?? null) !== 5 || ($clearance['audit_id'] ?? null) !== 4763
            || ($clearance['request_uuid'] ?? null) !== self::REQUEST
            || ($clearance['reviewed_by'] ?? null) !== $operator->id
            || ! is_string($review['reference'] ?? null) || ! preg_match('/^[A-Za-z0-9_.:\/-]{3,160}$/D', $review['reference'])
            || ! is_string($review['artifact_sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $review['artifact_sha256'])
            || ($review['no_quote_created'] ?? null) !== true || ($review['no_conversion'] ?? null) !== true
            || ($review['no_fx_hold'] ?? null) !== true || ($review['no_debit_or_fee'] ?? null) !== true) {
            throw new RuntimeException('Verified authoritative provider clearance for the original request is required.');
        }
        // This is a named operator assertion tied to an independently reviewed provider
        // artifact, not machine verification of the external artifact's authenticity.
    }
}
