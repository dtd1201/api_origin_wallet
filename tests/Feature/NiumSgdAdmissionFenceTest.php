<?php

namespace Tests\Feature;

use App\Models\Balance;
use App\Models\Beneficiary;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Services\Integrations\ProviderHttpClient;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Nium\NiumPayoutPreDispatchException;
use App\Services\Nium\NiumService;
use App\Services\Nium\NiumSgdAdmissionFence;
use App\Services\Nium\NiumSgdLocalPayoutWorkflow;
use App\Services\Nium\NiumTransferService;
use App\Services\Wallet\TransferApprovalService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesSgdLocalExecutionFixtures;
use Tests\Concerns\RefreshesCommittedPayoutDatabase;
use Tests\TestCase;

/** Local synthetic HTTP and :memory: DB only. No production/provider connections. */
class NiumSgdAdmissionFenceTest extends TestCase
{
    use CreatesSgdLocalExecutionFixtures, RefreshesCommittedPayoutDatabase;

    private function closeFence(): void
    {
        file_put_contents($this->sgdFenceDirectory.'/state', "CLOSED v1\n");
    }

    private function clearHttp(): void
    {
        Http::swap(new Factory(app('events')));
        Http::preventStrayRequests();
    }

    public function test_missing_malformed_or_closed_control_is_fail_closed_without_cached_open_state(): void
    {
        $fence = app(NiumSgdAdmissionFence::class);
        $lease = $fence->acquire();
        unset($lease);
        foreach (["CLOSED v1\n", 'OPEN', '', "OPEN v1\nextra"] as $state) {
            file_put_contents($this->sgdFenceDirectory.'/state', $state);
            try {
                $fence->acquire();
                $this->fail('Only the exact OPEN state may grant admission.');
            } catch (RuntimeException $exception) {
                $this->assertSame(NiumSgdAdmissionFence::CLOSED, $exception->getMessage());
            }
        }
        config()->set('sgd_admission.directory', null);
        $this->expectException(RuntimeException::class);
        $fence->acquire();
    }

    public function test_closed_fence_blocks_customer_http_review_and_submit_without_new_quote_or_consent(): void
    {
        [, $transfer] = $this->sgdExecutionFixture();
        $input = $transfer->only(['provider_id', 'beneficiary_id', 'source_amount', 'purpose_code', 'client_reference']);
        $input['reference_text'] = 'SYNTHETIC';
        $this->closeFence();
        $this->clearHttp();
        $this->withToken($this->sgdToken($transfer->user));
        $this->postJson("/api/user/users/{$transfer->user_id}/transfers/review", $input)->assertStatus(422);
        $this->postJson("/api/user/users/{$transfer->user_id}/transfers/{$transfer->id}/sgd-local-submit",
            $this->reviewToken($transfer))->assertStatus(422);
        $this->assertDatabaseCount('transfers', 1);
        $this->assertDatabaseCount('fx_quotes', 1);
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertArrayNotHasKey('customer_authorization', $transfer->fresh()->raw_data['sgd_local_workflow']);
        Http::assertNothingSent();
    }

    public function test_direct_workflow_and_approved_service_submission_are_fenced(): void
    {
        [$provider, $transfer, $checker] = $this->sgdExecutionFixture();
        $requested = $this->submitSgd($transfer);
        $approved = app(TransferApprovalService::class)->approve($requested, $checker);
        $this->closeFence();
        $this->clearHttp();
        foreach ([
            fn () => app(NiumSgdLocalPayoutWorkflow::class)->requestApproval($approved),
            fn () => app(ProviderTransferManager::class)->submitTransfer($provider, $approved),
            fn () => app(NiumTransferService::class)->submitTransfer($provider, $approved),
        ] as $call) {
            try {
                $call();
                $this->fail('A direct or worker call must not bypass admission.');
            } catch (RuntimeException $exception) {
                $this->assertSame(NiumSgdAdmissionFence::CLOSED, $exception->getMessage());
            }
        }
        $this->assertNull($approved->fresh()->provider_operation_key);
        $this->assertDatabaseCount('ledger_entries', 0);
        Http::assertNothingSent();
    }

    public function test_admin_approve_does_not_record_approval_or_reservation_while_fenced(): void
    {
        [, $transfer, $checker] = $this->sgdExecutionFixture();
        $requested = $this->submitSgd($transfer);
        $this->closeFence();
        $this->clearHttp();
        $this->withToken($this->sgdToken($checker))
            ->postJson("/api/admin/transfers/{$transfer->id}/approve")->assertStatus(422);
        $this->assertSame('approval_required', $requested->fresh()->status);
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        Http::assertNothingSent();
    }

    public static function beforeWireChanges(): array
    {
        return [['fence'], ['fx_flag']];
    }

    #[DataProvider('beforeWireChanges')]
    public function test_change_after_claim_before_transport_fails_unsent_and_cannot_replay(string $change): void
    {
        [$provider, $transfer, $checker] = $this->sgdExecutionFixture();
        $requested = $this->submitSgd($transfer);
        $this->fakeSgdProvider();
        $reached = false;
        Event::listen(RequestSending::class, function (RequestSending $event) use ($change, &$reached): void {
            if (str_ends_with($event->request->url(), '/remittance')) {
                $reached = true;
                if ($change === 'fence') {
                    $this->closeFence();
                } else {
                    config()->set('services.nium.payout_fx_enabled', false);
                }
            }
        });
        $failed = app(TransferApprovalService::class)->approve($requested, $checker, manager: app(ProviderTransferManager::class));
        $this->assertTrue($reached);
        $this->assertSame('failed', $failed->status);
        $this->assertNotNull($failed->provider_operation_key);
        $this->assertArrayNotHasKey('dispatch_started_at', $failed->raw_data['nium_execution']);
        $this->assertNotNull($failed->fxQuote->raw_data['consumed_at']);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'release')->count());
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/remittance'));
        file_put_contents($this->sgdFenceDirectory.'/state', "OPEN v1\n");
        config()->set('services.nium.payout_fx_enabled', true);
        $this->expectException(RuntimeException::class);
        app(ProviderTransferManager::class)->submitTransfer($provider, $failed);
    }

    public function test_raw_transport_and_alternate_post_helpers_cannot_bypass_guard_even_when_open(): void
    {
        [$provider, $transfer] = $this->sgdExecutionFixture();
        $this->clearHttp();
        $path = '/api/v1/client/synthetic-client/customer/synthetic-customer/wallet/synthetic-wallet/remittance';
        $payload = ['payout' => ['sourceCurrency' => 'USD', 'sourceAmount' => 5, 'quoteId' => 'synthetic-quote']];
        foreach ([
            fn () => app(NiumService::class)->post($path, $payload, $transfer->user, $transfer->id),
            fn () => app(NiumService::class)->postWithRequestId($path, $payload, $transfer->user, Str::uuid()->toString()),
            fn () => (new ProviderHttpClient($provider, 'nium'))->post($path, $payload, $transfer->user, $transfer->id),
        ] as $call) {
            try {
                $call();
                $this->fail('An unguarded remittance helper must never send SGD.');
            } catch (NiumPayoutPreDispatchException) {
                $this->assertTrue(true);
            }
        }
        Http::assertNothingSent();
    }

    public function test_code_rollback_with_same_fence_keeps_unknown_identity_hold_and_reconciliation(): void
    {
        [$provider, $transfer, $checker] = $this->sgdExecutionFixture();
        $requested = $this->submitSgd($transfer);
        $this->fakeSgdProvider('timeout');
        $unknown = app(TransferApprovalService::class)->approve($requested, $checker, manager: app(ProviderTransferManager::class));
        $this->assertSame('submission_unknown', $unknown->status);
        $operation = $unknown->provider_operation_key;
        $entries = LedgerEntry::query()->orderBy('id')->get()->toArray();
        $consumed = $unknown->fxQuote->raw_data;
        $this->closeFence();
        // Close admission before switching to a separately materialized compatible recovery runtime.
        $this->app->forgetInstance(NiumSgdAdmissionFence::class);
        $this->clearHttp();
        $this->assertSame($operation, $unknown->fresh()->provider_operation_key);
        $this->assertSame($entries, LedgerEntry::query()->orderBy('id')->get()->toArray());
        $this->assertSame($consumed, $unknown->fresh()->fxQuote->raw_data);
        $this->assertSame('5.00000000', Balance::query()->sole()->reserved_balance);

        $recovery = sys_get_temp_dir().'/sgd-rollback-runtime-'.bin2hex(random_bytes(8));
        mkdir($recovery, 0700);
        $files = new Filesystem;
        try {
            foreach (['app', 'bootstrap', 'config', 'routes', 'vendor'] as $directory) {
                $files->copyDirectory(base_path($directory), $recovery.'/'.$directory);
            }
            copy(base_path('composer.json'), $recovery.'/composer.json');
            copy(base_path('artisan'), $recovery.'/artisan');
            foreach (['framework/views', 'framework/cache/data', 'framework/sessions', 'logs'] as $directory) {
                $files->ensureDirectoryExists($recovery.'/storage/'.$directory);
            }
            $database = $recovery.'/synthetic.sqlite';
            DB::connection()->getPdo()->exec("VACUUM INTO '".str_replace("'", "''", $database)."'");
            $program = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Http::preventStrayRequests();
$t = App\Models\Transfer::query()->findOrFail((int) $argv[2]);
$before = [
    $t->provider_operation_key, $t->status, $t->fxQuote->raw_data,
    App\Models\LedgerEntry::query()->orderBy('id')->get()->toArray(),
    App\Models\Balance::query()->sole()->reserved_balance,
];
try {
    app(App\Services\Nium\NiumTransferService::class)->submitTransfer($t->provider, $t);
    exit(81);
} catch (RuntimeException $e) {
    if ($e->getMessage() !== App\Services\Nium\NiumSgdAdmissionFence::CLOSED) exit(82);
}
$t->refresh();
$after = [$t->provider_operation_key, $t->status, $t->fxQuote->raw_data,
    App\Models\LedgerEntry::query()->orderBy('id')->get()->toArray(),
    App\Models\Balance::query()->sole()->reserved_balance];
if ($before !== $after || $t->status !== 'submission_unknown'
    || Illuminate\Support\Facades\Http::recorded()->count() !== 0
    || realpath((new ReflectionClass(App\Models\Transfer::class))->getFileName()) !== $argv[1].'/app/Models/Transfer.php') exit(83);
echo 'PASS separate compatible recovery runtime; financial state unchanged';
PHP;
            $process = new Process([PHP_BINARY, '-r', $program, $recovery, (string) $unknown->id],
                $recovery, ['APP_ENV' => 'testing', 'APP_BASE_PATH' => $recovery,
                    'APP_CONFIG_CACHE' => $recovery.'/bootstrap/cache/no-config.php', 'DB_CONNECTION' => 'sqlite',
                    'DB_DATABASE' => $database, 'DB_URL' => '', 'DATABASE_URL' => false,
                    'NIUM_SGD_ADMISSION_DIRECTORY' => $this->sgdFenceDirectory,
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'stderr']);
            $process->setTimeout(30)->run();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertStringContainsString('PASS separate compatible recovery runtime', $process->getOutput());
        } finally {
            $files->deleteDirectory($recovery); // Only the random directory created by this test.
        }

        Http::fake(['*/synthetic-reference' => Http::response([])]);
        // The transport classifier permits status, wallet reads, webhooks and other providers.
        $fence = app(NiumSgdAdmissionFence::class);
        $this->assertNull($fence->wireKind('GET', '/api/v1/client/c/customer/u/wallet/w/remittance/ref', [], $unknown->id));
        $this->assertNull($fence->wireKind('GET', '/api/v1/client/c/customer/u/wallet/w/transactions', [], null));
        $this->artisan('nium:reconcile-transfers', ['--limit' => 1])->assertExitCode(0);
        $this->assertSame($operation, $unknown->fresh()->provider_operation_key);
        $this->assertSame('submission_unknown', $unknown->fresh()->status);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_reconciliation_scheduler_can_complete_existing_sgd_while_admission_is_closed(): void
    {
        [, $transfer, $checker] = $this->sgdExecutionFixture();
        $pending = app(TransferApprovalService::class)->approve($this->submitSgd($transfer), $checker, manager: app(ProviderTransferManager::class));
        $this->closeFence();
        $this->fakeSgdProvider('completed');
        config()->set('services.nium.transfer_reconciliation_enabled', true);
        $this->artisan('nium:reconcile-transfers', ['--limit' => 1])->assertExitCode(0);
        $this->assertSame('completed', $pending->fresh()->status);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame('80.00000000', Balance::query()->sole()->ledger_balance);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_authenticated_webhook_is_received_while_closed_without_releasing_unproven_funds(): void
    {
        [, $transfer, $checker] = $this->sgdExecutionFixture();
        $pending = app(TransferApprovalService::class)->approve($this->submitSgd($transfer), $checker, manager: app(ProviderTransferManager::class));
        $this->closeFence();
        $this->clearHttp();
        $payload = ['eventId' => 'synthetic-fenced-paid', 'eventType' => 'remittance.completed', 'data' => ['resource' => [
            'systemReferenceNumber' => $pending->external_transfer_id, 'transactionId' => 'synthetic-transaction', 'status' => 'COMPLETED']]];
        $this->withHeader('x-partner-key', 'synthetic-only')->postJson('/api/webhooks/providers/nium', $payload)->assertOk();
        $this->withHeader('x-partner-key', 'synthetic-only')->postJson('/api/webhooks/providers/nium', $payload)->assertOk();
        $this->assertSame('completed', $pending->fresh()->status);
        $this->assertSame('5.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertDatabaseCount('ledger_entries', 1);
        Http::assertNothingSent();
    }

    public function test_hk_swift_still_approves_and_submits_with_closed_sgd_fence_and_fx_disabled(): void
    {
        [$provider, $fixture, $checker] = $this->sgdExecutionFixture();
        $beneficiary = Beneficiary::query()->create(['user_id' => $fixture->user_id, 'provider_id' => $provider->id,
            'external_beneficiary_id' => 'synthetic-hk', 'beneficiary_type' => 'business', 'full_name' => 'Synthetic HK',
            'country_code' => 'HK', 'currency' => 'USD', 'payout_method' => 'SWIFT', 'status' => 'active',
            'raw_data' => ['nium' => ['payoutMethod' => 'SWIFT']]]);
        $this->closeFence();
        config()->set('services.nium.payout_fx_enabled', false);
        config()->set('wallet.transfer_controls.require_admin_approval', true);
        config()->set('wallet.transfer_controls.approval_threshold_amount', 0);
        $created = $this->withToken($this->sgdToken($fixture->user))
            ->postJson("/api/user/users/{$fixture->user_id}/transfers", [
                'provider_id' => $provider->id, 'beneficiary_id' => $beneficiary->id, 'source_amount' => '5',
                'purpose_code' => 'SYNTHETIC_PURPOSE', 'reference_text' => 'HK-FENCE-REGRESSION',
                'client_reference' => 'HK-FENCE-REGRESSION', 'transfer_type' => 'payout',
                'source_currency' => 'USD', 'target_currency' => 'USD',
            ])->assertCreated();
        $hk = Transfer::query()->findOrFail($created->json('id'));
        $result = app(TransferApprovalService::class)->approve($hk, $checker, manager: app(ProviderTransferManager::class));
        $this->assertSame('pending', $result->status);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/remittance')
            && $request['payout']['sourceCurrency'] === 'USD' && $request['payout']['destinationCurrency'] === 'USD'
            && ! isset($request['payout']['quoteId']));
    }
}
