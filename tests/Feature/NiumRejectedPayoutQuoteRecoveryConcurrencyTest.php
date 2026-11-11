<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Balance;
use App\Services\Nium\NiumRejectedPayoutQuoteRecovery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\CreatesRejectedSgdQuoteIncident;
use Tests\Support\DynamicConfirmationDatabaseTestCase;
use Throwable;

/** Reuses the guarded disposable PostgreSQL setup; no wrapping test transaction. */
final class NiumRejectedPayoutQuoteRecoveryConcurrencyTest extends DynamicConfirmationDatabaseTestCase
{
    use CreatesRejectedSgdQuoteIncident;

    public function test_concurrent_recovery_preserves_one_receipt_and_the_original_evidence_without_io(): void
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'Recovery concurrency verification requires pcntl.');
        [$transfer, $operator, $clearance, $log, $evidence] = $this->rejectedIncident();
        $originalWorkflow = $transfer->raw_data['sgd_local_workflow'];
        $captured = $evidence->fresh()->getRawOriginal();
        $barrier = tempnam(sys_get_temp_dir(), 'sgd-recovery-start-');
        $outputs = [tempnam(sys_get_temp_dir(), 'sgd-recovery-result-'), tempnam(sys_get_temp_dir(), 'sgd-recovery-result-')];
        $children = [];
        DB::disconnect();

        try {
            foreach ([0, 1] as $worker) {
                $pid = pcntl_fork();
                if ($pid === 0) {
                    try {
                        DB::purge();
                        DB::reconnect();
                        DB::statement("SET lock_timeout = '5s'");
                        DB::statement("SET statement_timeout = '10s'");
                        Event::listen(QueryExecuted::class, static function (QueryExecuted $query): void {
                            if (str_contains($query->sql, '"transfers"') && str_contains($query->sql, 'for update')) {
                                usleep(150000); // Overlap the exclusive lease with the competing attempt.
                            }
                        });
                        $deadline = microtime(true) + 10;
                        while (file_get_contents($barrier) !== 'go') {
                            if (microtime(true) > $deadline) {
                                throw new RuntimeException('recovery_concurrency_barrier_timeout');
                            }
                            usleep(1000);
                        }
                        app(NiumRejectedPayoutQuoteRecovery::class)->recover($operator, $clearance, $this->rejectedProviderArtifact, true);
                        $result = ['status' => 'ok'];
                    } catch (RuntimeException $exception) {
                        $result = ['status' => 'rejected', 'reason' => $exception->getMessage()];
                    } catch (Throwable $exception) {
                        $result = ['status' => 'error', 'reason' => $exception::class];
                    }
                    try {
                        Http::assertNothingSent();
                    } catch (Throwable $exception) {
                        $result = ['status' => 'error', 'reason' => $exception::class];
                    }
                    file_put_contents($outputs[$worker], json_encode($result, JSON_THROW_ON_ERROR));
                    DB::disconnect();
                    exit(0);
                }
                $this->assertGreaterThan(0, $pid);
                $children[] = $pid;
            }
            file_put_contents($barrier, 'go');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            DB::purge();
            DB::reconnect();
            $results = array_map(fn ($path) => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR), $outputs);
            $this->assertContains('ok', array_column($results, 'status'));
            foreach ($results as $result) {
                if ($result['status'] !== 'ok') {
                    $this->assertSame('rejected', $result['status']);
                    $this->assertSame('SGD must be closed and all cooperating operations drained.', $result['reason']);
                }
            }
            // An explicit identical retry is safe even when one exclusive lease refused.
            $current = app(NiumRejectedPayoutQuoteRecovery::class)->recover($operator, $clearance, $this->rejectedProviderArtifact, true);
            $workflow = $current->raw_data['sgd_local_workflow'];
            $this->assertSame('draft', $current->status);
            $this->assertSame('draft', $workflow['state']);
            $this->assertCount(1, $workflow['rejected_quote_recoveries']);
            $this->assertSameRecoveryJson($originalWorkflow, $workflow['rejected_quote_recoveries'][NiumRejectedPayoutQuoteRecovery::REQUEST]['original_attempt']);
            $this->assertSame($captured, $evidence->fresh()->getRawOriginal());
            $this->assertSame(400, $log->fresh()->response_status);
            $this->assertNull($current->provider_operation_key);
            $this->assertNull($current->external_transfer_id);
            $this->assertNull($current->external_payment_id);
            $this->assertNull($current->fx_quote_id);
            $this->assertDatabaseCount('fx_quotes', 0);
            $this->assertDatabaseCount('ledger_entries', 0);
            $this->assertDatabaseCount('transfer_approvals', 0);
            $this->assertSame('85.00000000', Balance::query()->sole()->available_balance);
            $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
            Http::assertNothingSent();
        } finally {
            file_put_contents($barrier, 'go');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
            }
            unlink($barrier);
            foreach ($outputs as $path) {
                unlink($path);
            }
            DB::purge();
            DB::reconnect();
        }
    }
}
