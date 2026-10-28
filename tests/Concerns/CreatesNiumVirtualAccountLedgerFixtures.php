<?php

namespace Tests\Concerns;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\NiumApiExchangeEvidence;
use App\Models\NiumVirtualAccountOperation;
use App\Models\NiumVirtualAccountRequest;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumVirtualAccountRequestIdentity;
use Illuminate\Support\Str;

trait CreatesNiumVirtualAccountLedgerFixtures
{
    private function ledgerAccount(?string $walletId = null): UserProviderAccount
    {
        $provider = IntegrationProvider::query()->firstOrCreate(['code' => 'nium'], ['name' => 'Nium', 'status' => 'active']);

        return User::factory()->create(['kyc_status' => 'verified'])->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => (string) Str::uuid(),
            'external_account_id' => $walletId ?? (string) Str::uuid(),
            'status' => 'active', 'provider_status' => 'clear',
            'customer_id_verified_at' => now(), 'wallet_id_verified_at' => now(),
        ]);
    }

    private function ledgerIdentity(UserProviderAccount $account, array $overrides = []): NiumVirtualAccountRequestIdentity
    {
        $identity = NiumVirtualAccountRequestIdentity::forAccount($account, $overrides['payload'] ?? [
            'currency' => 'USD', 'accountCategory' => 'COLLECTION_ACCOUNT', 'accountType' => 'LOCAL', 'bankName' => 'DBS_HK',
        ]);
        $snapshot = $identity->snapshot();
        foreach (['environment' => 'environment', 'clientId' => 'client_id', 'customerId' => 'customer_id', 'walletId' => 'wallet_id', 'endpoint' => 'endpoint'] as $key => $field) {
            if (array_key_exists($key, $overrides)) {
                $snapshot[$field] = $overrides[$key];
            }
        }
        if (! isset($overrides['endpoint'])) {
            $snapshot['endpoint'] = '/api/v2/client/'.$snapshot['client_id'].'/customer/'.$snapshot['customer_id'].'/wallet/'.$snapshot['wallet_id'].'/paymentId';
        }

        return NiumVirtualAccountRequestIdentity::fromSnapshot($snapshot);
    }

    private function configureLedgerTarget(): void
    {
        config([
            'services.nium.base_url' => 'https://nium.test',
            'services.nium.client_id' => '11111111-1111-4111-8111-111111111111',
            'services.nium.assign_payment_id_endpoint' => '/api/v2/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/paymentId',
        ]);
    }

    private function legacyLedgerOperation(UserProviderAccount $account, string $state, array $attributes = []): NiumVirtualAccountOperation
    {
        return NiumVirtualAccountOperation::query()->create(array_replace([
            'user_provider_account_id' => $account->id, 'operation_id' => 'legacy-'.Str::uuid(),
            'currency' => 'USD', 'account_category' => 'COLLECTION_ACCOUNT', 'account_type' => 'LOCAL',
            'status' => $state, 'claimed_at' => now(),
        ], $attributes));
    }

    private function ledgerResponseEvidence(NiumVirtualAccountRequest $request, array $response = ['uniquePaymentId' => 'VA-LEDGER-ONE'], int $status = 200): ApiRequestLog
    {
        $operation = $request->operation()->firstOrFail();
        $account = $operation->providerAccount()->firstOrFail();
        $snapshot = $request->identity()->snapshot();
        $endpoint = '/api/'.$snapshot['api_version'].'/client/[REDACTED]/customer/[REDACTED]/wallet/[REDACTED]/paymentId';
        $log = ApiRequestLog::query()->create([
            'provider_id' => $account->provider_id, 'user_id' => $account->user_id,
            'operation' => 'assign_payment_id', 'client_hash_id' => $snapshot['client_id'],
            'external_reference' => $operation->operation_id, 'request_method' => 'POST',
            'request_url' => 'https://nium.test'.$endpoint, 'endpoint_path' => $endpoint,
            'request_headers' => ['x-request-id' => $request->provider_request_id],
            'response_status' => $status, 'response_body' => $response,
        ]);
        NiumApiExchangeEvidence::query()->create([
            'api_request_log_id' => $log->id, 'request_method' => 'POST',
            // Synthetic exact-target evidence. Current live capture redacts this URL and is rejected.
            'request_url' => 'https://nium.test'.$snapshot['endpoint'],
            'raw_request_body' => json_encode($snapshot['payload'], JSON_THROW_ON_ERROR),
            'raw_response_body' => json_encode($response, JSON_THROW_ON_ERROR),
            'request_headers' => ['x-request-id' => [$request->provider_request_id]],
            'response_received' => true,
            'request_body_sha256' => hash('sha256', json_encode($snapshot['payload'], JSON_THROW_ON_ERROR)),
            'response_body_sha256' => hash('sha256', json_encode($response, JSON_THROW_ON_ERROR)),
        ]);

        return $log;
    }
}
