<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\Balance;
use App\Models\Beneficiary;
use App\Models\FxQuote;
use App\Models\IntegrationProvider;
use App\Models\KycProviderSubmission;
use App\Models\Transfer;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use App\Services\Nium\NiumCustomerOnboardingService;
use App\Services\Nium\NiumTransferService;
use App\Services\Nium\NiumWebhookService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NiumPostgresConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected array $connectionsToTransact = [];

    public function test_concurrent_onboarding_and_webhooks_are_serialized_on_postgres(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires PostgreSQL and pcntl.');
        }

        $this->configureNium();
        $barrier = tempnam(sys_get_temp_dir(), 'nium-create-race-');
        $transactionLevel = DB::transactionLevel();
        $provider = IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);
        $user = User::factory()->create([
            'full_name' => 'Concurrency Tester',
            'email' => 'concurrency@example.test',
            'phone' => '+6591234567',
            'kyc_status' => 'verified',
        ]);
        $user->profile()->create(['user_type' => 'individual', 'country_code' => 'SG']);
        $user->kycProfile()->create([
            'status' => 'approved',
            'applicant_type' => 'individual',
            'legal_name' => 'Concurrency Tester',
            'date_of_birth' => '1990-01-01',
            'nationality_country_code' => 'SG',
            'residence_country_code' => 'SG',
            'address_line1' => '1 Test Road',
            'city' => 'Singapore',
            'postal_code' => '018989',
            'country_code' => 'SG',
            'metadata' => [
                'nium_region' => 'SG',
                'nium_kyc_type' => 'minimum',
                'mobile_country_code' => '65',
            ],
        ]);

        $this->runConcurrent(2, function () use ($provider, $user, $barrier, $transactionLevel): void {
            Http::fake(function (HttpRequest $request) use ($barrier, $transactionLevel) {
                $externalId = UserProviderAccount::query()->value('external_reference');
                $customer = [
                    'customerHashId' => 'concurrent-customer-id',
                    'externalId' => $externalId,
                    'status' => 'clear',
                    'subStatus' => '',
                    'wallets' => [['walletHashId' => 'concurrent-wallet-id']],
                ];

                if ($request->method() === 'POST') {
                    $this->assertSame($transactionLevel, DB::transactionLevel());

                    return Http::response([
                        ...$customer,
                        'externalId' => $request->data()['externalId'],
                    ]);
                }

                if (str_contains($request->url(), '/customers')) {
                    $this->waitAtBarrier($barrier, 2);

                    return Http::response(['customers' => []]);
                }

                return Http::response($customer);
            });

            app(NiumCustomerOnboardingService::class)->syncUser(
                IntegrationProvider::query()->findOrFail($provider->id),
                User::query()->findOrFail($user->id),
            );
        });

        $this->assertSame(1, UserProviderAccount::query()->count());
        $this->assertLessThanOrEqual(1, KycProviderSubmission::query()
            ->where('user_id', $user->id)
            ->where('provider_id', $provider->id)
            ->count());
        $this->assertSame(1, ApiRequestLog::query()->where('request_method', 'POST')->count());
        $account = UserProviderAccount::query()->firstOrFail();
        $this->assertSame('active', $account->status);
        $this->assertNotNull($account->external_reference);
        $this->assertNull($account->reconciliation_error);
        @unlink($barrier);

        $payload = [
            'clientHashId' => 'concurrency-client-id',
            'customerHashId' => 'concurrent-customer-id',
            'externalId' => $account->external_reference,
            'status' => 'clear',
            'subStatus' => 'rfi_requested',
            'template' => 'CUSTOMER_STATUS_WEBHOOK',
        ];

        $this->runConcurrent(2, function () use ($provider, $payload): void {
            Http::fake(['*' => Http::response([
                'customerHashId' => 'concurrent-customer-id',
                'externalId' => $payload['externalId'],
                'status' => 'clear',
                'subStatus' => 'rfi_requested',
                'wallets' => [['walletHashId' => 'concurrent-wallet-id']],
            ])]);
            $request = Request::create(
                '/api/webhooks/providers/nium',
                'POST',
                server: [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_X_PARTNER_KEY' => 'concurrency-partner-key',
                    'HTTP_X_REQUEST_ID' => 'concurrent-webhook-request-id',
                ],
                content: json_encode($payload, JSON_THROW_ON_ERROR),
            );

            app(NiumWebhookService::class)->handleWebhook(
                IntegrationProvider::query()->findOrFail($provider->id),
                $request,
            );
        });

        $this->assertSame(1, WebhookEvent::query()
            ->where('event_id', 'concurrent-webhook-request-id')
            ->count());
        $this->assertSame('under_review', $account->fresh()->status);
    }

    public function test_concurrent_transfer_submit_sends_exactly_one_provider_post(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires PostgreSQL and pcntl.');
        }

        $this->configureNium();
        config()->set('wallet.transfer_controls.require_admin_approval', false);
        $provider = IntegrationProvider::query()->create(['code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        $user = User::factory()->create(['kyc_status' => 'verified']);
        $user->providerAccounts()->create([
            'provider_id' => $provider->id, 'external_customer_id' => 'transfer-customer',
            'external_account_id' => 'transfer-wallet', 'status' => 'active', 'provider_status' => 'clear',
            'customer_id_verified_at' => now(), 'wallet_id_verified_at' => now(), 'provider_ids_verified_at' => now(),
        ]);
        Balance::query()->create([
            'user_id' => $user->id, 'provider_id' => $provider->id, 'currency' => 'USD',
            'available_balance' => 100, 'ledger_balance' => 100, 'as_of' => now(),
        ]);
        $beneficiary = Beneficiary::query()->create([
            'user_id' => $user->id, 'provider_id' => $provider->id, 'external_beneficiary_id' => 'transfer-beneficiary',
            'beneficiary_type' => 'personal', 'full_name' => 'Concurrent Payee', 'country_code' => 'IN',
            'currency' => 'INR', 'status' => 'active',
        ]);
        $quote = FxQuote::query()->create([
            'user_id' => $user->id, 'provider_id' => $provider->id, 'quote_ref' => '114',
            'source_currency' => 'USD', 'target_currency' => 'INR', 'source_amount' => 10,
            'target_amount' => 830, 'net_rate' => 83, 'fee_amount' => 1, 'expires_at' => now()->addMinutes(5),
            'raw_data' => [
                'provider_fx_type' => 'lock_and_hold',
                'audit_id' => '114',
            ],
        ]);
        $transfer = Transfer::query()->create([
            'transfer_no' => 'TRF-CONCURRENT', 'user_id' => $user->id, 'provider_id' => $provider->id,
            'beneficiary_id' => $beneficiary->id, 'fx_quote_id' => $quote->id, 'transfer_type' => 'bank',
            'source_currency' => 'USD', 'target_currency' => 'INR', 'source_amount' => 10,
            'target_amount' => 830, 'fx_rate' => 83, 'fee_amount' => 1, 'status' => 'draft',
        ]);

        $this->runConcurrent(2, function () use ($provider, $transfer): void {
            Http::fake(['*' => Http::response(['systemReferenceNumber' => 'RT-CONCURRENT'])]);

            try {
                app(NiumTransferService::class)->submitTransfer(
                    IntegrationProvider::query()->findOrFail($provider->id),
                    Transfer::query()->findOrFail($transfer->id),
                );
            } catch (\RuntimeException) {
                // The losing request must fail before provider HTTP.
            }
        });

        $updated = $transfer->fresh();
        $this->assertSame('pending', $updated->status);
        $this->assertNotEmpty($updated->provider_operation_key);
        $this->assertSame('RT-CONCURRENT', $updated->external_transfer_id);
        $this->assertSame(1, ApiRequestLog::query()->where('related_transfer_id', $transfer->id)->where('request_method', 'POST')->count());

        $operationKey = $updated->provider_operation_key;

        try {
            app(NiumTransferService::class)->submitTransfer($provider, $updated);
            $this->fail('A completed concurrent submission must not be submitted again.');
        } catch (\RuntimeException) {
            // Expected: state validation rejects before provider HTTP.
        }

        $this->assertSame($operationKey, $updated->fresh()->provider_operation_key);
        $this->assertSame(1, ApiRequestLog::query()->where('related_transfer_id', $transfer->id)->where('request_method', 'POST')->count());
    }

    public function test_submission_unknown_cannot_be_resubmitted_on_postgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL.');
        }

        $this->configureNium();
        $provider = IntegrationProvider::query()->firstOrCreate(
            ['code' => 'nium'],
            ['name' => 'Nium', 'status' => 'active'],
        );
        $user = User::factory()->create(['kyc_status' => 'verified']);
        $transfer = Transfer::query()->create([
            'transfer_no' => 'TRF-UNKNOWN',
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'transfer_type' => 'bank',
            'source_currency' => 'USD',
            'target_currency' => 'USD',
            'source_amount' => 10,
            'target_amount' => 10,
            'status' => 'submission_unknown',
            'provider_operation_key' => 'nium-stable-unknown-key',
        ]);

        Http::fake();

        try {
            app(NiumTransferService::class)->submitTransfer($provider, $transfer);
            $this->fail('A submission_unknown transfer must not be submitted again.');
        } catch (\RuntimeException) {
            // Expected: recovery requires authoritative lookup or human review.
        }

        $this->assertSame('nium-stable-unknown-key', $transfer->fresh()->provider_operation_key);
        Http::assertNothingSent();
    }

    public function test_provider_operation_key_unique_constraint_is_provider_scoped_on_postgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL.');
        }

        $user = User::factory()->create();
        $firstProvider = IntegrationProvider::query()->create(['code' => 'nium-one', 'name' => 'Nium One', 'status' => 'active']);
        $secondProvider = IntegrationProvider::query()->create(['code' => 'nium-two', 'name' => 'Nium Two', 'status' => 'active']);
        $attributes = [
            'transfer_type' => 'bank',
            'source_currency' => 'USD',
            'target_currency' => 'USD',
            'source_amount' => 10,
            'target_amount' => 10,
            'status' => 'draft',
            'provider_operation_key' => 'provider-scoped-operation-key',
        ];

        Transfer::query()->create([
            ...$attributes,
            'transfer_no' => 'TRF-UNIQUE-ONE',
            'user_id' => $user->id,
            'provider_id' => $firstProvider->id,
        ]);
        Transfer::query()->create([
            ...$attributes,
            'transfer_no' => 'TRF-UNIQUE-TWO',
            'user_id' => $user->id,
            'provider_id' => $secondProvider->id,
        ]);

        $this->expectException(QueryException::class);
        Transfer::query()->create([
            ...$attributes,
            'transfer_no' => 'TRF-UNIQUE-COLLISION',
            'user_id' => $user->id,
            'provider_id' => $firstProvider->id,
        ]);
    }

    private function runConcurrent(int $workers, callable $callback): void
    {
        DB::disconnect();
        $children = [];

        for ($worker = 0; $worker < $workers; $worker++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                try {
                    DB::purge();
                    DB::reconnect();
                    $callback();
                    exit(0);
                } catch (\Throwable) {
                    exit(1);
                }
            }

            $this->assertGreaterThan(0, $pid);
            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        DB::purge();
        DB::reconnect();
    }

    private function waitAtBarrier(string $path, int $workers): void
    {
        $handle = fopen($path, 'c+');

        if ($handle === false) {
            throw new \RuntimeException('Unable to open concurrency barrier.');
        }

        flock($handle, LOCK_EX);
        $count = (int) stream_get_contents($handle);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) ($count + 1));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        $deadline = microtime(true) + 5;

        while ((int) file_get_contents($path) < $workers) {
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException('Concurrency barrier timed out.');
            }

            usleep(10_000);
        }
    }

    private function configureNium(): void
    {
        config()->set('services.nium.base_url', 'https://gateway.nium.test');
        config()->set('services.nium.client_id', 'concurrency-client-id');
        config()->set('services.nium.auth.mode', 'header');
        config()->set('services.nium.auth.header_name', 'x-api-key');
        config()->set('services.nium.auth.header_value', 'concurrency-api-key');
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'concurrency-partner-key');
    }
}
