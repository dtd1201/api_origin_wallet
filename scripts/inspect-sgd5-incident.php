<?php

// Operator-only read report. No provider service calls, recovery, quotes or mutations.
use App\Models\ApiRequestLog;
use App\Models\Balance;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

try {
    $options = getopt('', ['release:']);
    $root = $options['release'] ?? null;
    if (PHP_VERSION !== '8.3.31' || ! is_string($root) || realpath($root) !== $root) {
        throw new RuntimeException;
    }
    require $root.'/vendor/autoload.php';
    if (realpath((new ReflectionClass(Transfer::class))->getFileName()) !== $root.'/app/Models/Transfer.php') {
        throw new RuntimeException;
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('production') || DB::getDriverName() !== 'pgsql') {
        throw new RuntimeException;
    }
    $report = DB::transaction(function (): array {
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $transfer = Transfer::query()->findOrFail(5);
        $log = ApiRequestLog::query()->find(4763);
        $evidence = $log?->niumExchangeEvidence;
        $request = $evidence?->raw_request_body;
        $response = $evidence?->raw_response_body;
        $workflow = $transfer->raw_data['sgd_local_workflow'] ?? [];
        $policy = config('services.nium.sgd_local_zero_fee');
        $canonical = function ($value) use (&$canonical) {
            if (is_array($value)) {
                ksort($value);
                foreach ($value as &$child) {
                    $child = $canonical($child);
                }
            }

            return $value;
        };
        $fence = config('sgd_admission.directory');

        return [
            'report_only_not_recovery_authorization' => true,
            'transfer' => $transfer->only(['id', 'user_id', 'provider_id', 'beneficiary_id', 'source_currency', 'target_currency',
                'source_amount', 'purpose_code', 'client_reference', 'reference_text', 'status', 'fx_quote_id',
                'provider_operation_key', 'external_transfer_id', 'provider_status', 'submitted_at', 'completed_at']),
            'workflow_state' => $workflow['state'] ?? null, 'lock_request_id' => $workflow['lock_request_id'] ?? null,
            'customer_acceptance_present' => isset($workflow['customer_authorization']),
            'checker_snapshot_present' => isset($transfer->raw_data['sgd_local_approval']),
            'recovery_recorded' => isset($workflow['rejected_quote_recoveries']['e12e1acc-1dde-4f87-835c-93f3b3875524']),
            'approval_count' => $transfer->approvals()->count(),
            'wallet_reservations' => Balance::query()->where('provider_id', $transfer->provider_id)->where('user_id', $transfer->user_id)
                ->get(['currency', 'reserved_balance'])->toArray(),
            'transfer_ledger' => LedgerEntry::query()->where('reference', 'like', 'transfer:5:%')
                ->get(['id', 'entry_type', 'currency', 'amount', 'status', 'reference'])->toArray(),
            'audit' => $log?->only(['id', 'operation', 'request_method', 'response_status', 'transport_outcome', 'is_success',
                'request_started_at', 'request_finished_at']),
            'capture_version' => $evidence?->capture_version, 'response_received' => $evidence?->response_received,
            'request_body_sha256' => $evidence?->request_body_sha256, 'response_body_sha256' => $evidence?->response_body_sha256,
            'request_hash_valid' => is_string($request) && hash_equals((string) $evidence->request_body_sha256, hash('sha256', $request)),
            'response_hash_valid' => is_string($response) && hash_equals((string) $evidence->response_body_sha256, hash('sha256', $response)),
            'remittance_audits' => ApiRequestLog::query()->where('related_transfer_id', 5)->where('operation', 'transfer_money')
                ->get(['id', 'external_reference', 'response_status', 'transport_outcome', 'is_success'])->toArray(),
            'payout_fx_enabled' => config('services.nium.payout_fx_enabled'),
            'zero_fee_policy_sha256' => hash('sha256', json_encode($canonical($policy), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'zero_fee_policy_expires_at' => $policy['expires_at'] ?? null,
            'fence_closed' => is_string($fence) && ! is_link($fence.'/state') && @file_get_contents($fence.'/state') === "CLOSED v1\n",
        ];
    });
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "FAIL incident inspection; no provider call or recovery was performed.\n");
    exit(1);
}
