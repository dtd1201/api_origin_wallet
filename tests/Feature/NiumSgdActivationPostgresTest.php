<?php

namespace Tests\Feature;

use App\Models\Balance;
use App\Models\Transfer;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Nium\NiumSgdAdmissionFence;
use App\Services\Wallet\TransferApprovalService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\SgdActivationPostgresSafety;

/** Existing committed/forked SGD and HK suite, plus live admission races. */
class NiumSgdActivationPostgresTest extends NiumSgdReleasePostgresTest
{
    protected function postgresSafety(): string
    {
        return SgdActivationPostgresSafety::class;
    }

    public function test_exact_production_database_and_php_versions_are_used(): void
    {
        $this->assertSame('8.3.31', PHP_VERSION);
        $this->assertSame('140024', DB::selectOne('SHOW server_version_num')->server_version_num);
    }

    public function test_close_during_final_wallet_lock_is_rechecked_before_wire_marker(): void
    {
        [, $transfer, $checker] = $this->sgdExecutionFixture();
        $requested = $this->submitSgd($transfer);
        $atWire = false;
        $closed = false;
        Event::listen(RequestSending::class, function (RequestSending $event) use (&$atWire): void {
            $atWire = str_ends_with($event->request->url(), '/remittance');
        });
        DB::listen(function (QueryExecuted $query) use (&$atWire, &$closed): void {
            if ($atWire && str_contains($query->sql, '"balances"') && str_contains($query->sql, 'for update')) {
                $atWire = false;
                $closed = true;
                file_put_contents($this->sgdFenceDirectory.'/state', "CLOSED v1\n");
            }
        });
        $failed = app(TransferApprovalService::class)->approve($requested, $checker, manager: app(ProviderTransferManager::class));
        $this->assertTrue($closed);
        $this->assertSame('failed', $failed->status);
        $this->assertArrayNotHasKey('dispatch_started_at', $failed->raw_data['nium_execution']);
        $this->assertNotNull($failed->provider_operation_key);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/remittance'));
    }

    public function test_independent_worker_connections_cannot_submit_an_approved_payment_after_close(): void
    {
        [$provider, $transfer, $checker] = $this->sgdExecutionFixture();
        $requested = $this->submitSgd($transfer);
        $approved = app(TransferApprovalService::class)->approve($requested, $checker);
        file_put_contents($this->sgdFenceDirectory.'/state', "CLOSED v1\n");
        $this->race(function () use ($provider, $approved): void {
            try {
                app(ProviderTransferManager::class)->submitTransfer($provider, Transfer::query()->findOrFail($approved->id));
                throw new \LogicException('Closed admission permitted a worker payout.');
            } catch (RuntimeException $exception) {
                $this->assertSame(NiumSgdAdmissionFence::CLOSED, $exception->getMessage());
            }
        });
        $this->assertNull($approved->fresh()->provider_operation_key);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('transfer_approvals', 1);
    }
}
