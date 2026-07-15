<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use App\Services\Nium\NiumCustomerOnboardingService;
use App\Services\Nium\NiumWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NiumPostgresConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [];

    public function test_concurrent_onboarding_and_webhooks_are_serialized_on_postgres(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires PostgreSQL and pcntl.');
        }

        $this->configureNium();
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

        $this->runConcurrent(2, function () use ($provider, $user): void {
            Http::fake(function (HttpRequest $request) {
                $externalId = UserProviderAccount::query()->value('external_reference');
                $customer = [
                    'customerHashId' => 'concurrent-customer-id',
                    'externalId' => $externalId,
                    'status' => 'clear',
                    'subStatus' => '',
                    'wallets' => [['walletHashId' => 'concurrent-wallet-id']],
                ];

                if ($request->method() === 'POST') {
                    return Http::response([
                        ...$customer,
                        'externalId' => $request->data()['externalId'],
                    ]);
                }

                return str_contains($request->url(), '/customers')
                    ? Http::response(['customers' => []])
                    : Http::response($customer);
            });

            app(NiumCustomerOnboardingService::class)->syncUser(
                IntegrationProvider::query()->findOrFail($provider->id),
                User::query()->findOrFail($user->id),
            );
        });

        $this->assertSame(1, UserProviderAccount::query()->count());
        $this->assertSame(1, ApiRequestLog::query()->where('request_method', 'POST')->count());
        $account = UserProviderAccount::query()->firstOrFail();
        $this->assertSame('active', $account->status);

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
