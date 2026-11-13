<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use App\Services\Wallet\Concurrency\OwnerFirst;
use App\Models\IntegrationProvider;
use App\Models\NiumDynamicIntent;
use App\Models\NiumDynamicPayoutOutbox;
use App\Models\User;
use App\Services\Nium\NiumService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class NiumDynamicPayoutTransport implements DynamicPayoutTransport
{
    public function __construct(private readonly NiumService $nium) {}

    public function submit(NiumDynamicIntent $intent, NiumDynamicPayoutOutbox $outbox, DynamicPayoutWireClaim $claim): array
    {
        $this->assertScope($intent);
        DynamicPayoutEnvironment::configured()->assertContract('remittance');
        $response = $this->nium->postDynamicPayout($this->path($intent, 'remittance'), $outbox->request_json,
            User::query()->findOrFail($intent->user_id), $outbox->operation_key, $claim);
        $this->assertScope($intent);
        $body = ExactProviderJson::decode($response->body());
        $reference = $body['systemReferenceNumber'] ?? $body['system_reference_number'] ?? null;
        if (! is_string($reference) || preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $reference) !== 1) {
            $reference = null;
        }

        return ['http_status' => $response->status(), 'provider_reference' => $reference];
    }

    public function transactions(NiumDynamicIntent $intent): array
    {
        $this->assertScope($intent);
        DynamicPayoutEnvironment::configured()->assertTransactionsReadContract();
        $rows = [];
        $hashes = [];
        $pages = null;
        $total = null;
        $max = max(1, min(100, (int) config('nium_dynamic_payouts.max_transaction_pages', 20)));
        // Read all wallet rows in the window: filtering solely on the RT number can omit fees.
        $endDate = now('UTC')->toDateString();
        for ($page = 0; $page < $max; $page++) {
            $this->assertScope($intent);
            DynamicPayoutEnvironment::configured()->assertTransactionsReadContract();
            $response = $this->nium->getDynamicPayoutTransactions($this->path($intent, 'transactions'), [
                'startDate' => $intent->created_at->copy()->utc()->subDay()->toDateString(), 'endDate' => $endDate,
                'page' => $page, 'size' => 100,
            ], User::query()->findOrFail($intent->user_id), $intent->id);
            $this->assertScope($intent);
            if (! $response->successful()) {
                throw new RuntimeException('dynamic_transaction_lookup_unavailable');
            }
            $body = ExactProviderJson::decode($response->body());
            foreach (['totalPages', 'totalElements'] as $field) {
                if (! is_string($body[$field] ?? null) || preg_match('/^[0-9]{1,8}$/D', $body[$field]) !== 1) {
                    throw new RuntimeException('dynamic_transaction_pagination_unverified');
                }
            }
            $p = (int) $body['totalPages'];
            $n = (int) $body['totalElements'];
            if (($pages !== null && ($pages !== $p || $total !== $n)) || $p > $max
                || ($p === 0 && $n !== 0) || ($p > 0 && $n === 0)
                || $n < $p || $n > $p * 100
                || ! is_array($body['content'] ?? null) || ! array_is_list($body['content'])
                || count($body['content']) > 100 || ($p > 0 && $body['content'] === [])) {
                throw new RuntimeException('dynamic_transaction_snapshot_incomplete');
            }
            $pages = $p;
            $total = $n;
            foreach ($body['content'] as $row) {
                if (! is_array($row) || ! is_string($row['authCode'] ?? null)
                    || preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $row['authCode']) !== 1 || isset($rows[$row['authCode']])) {
                    throw new RuntimeException('dynamic_transaction_identity_unverified');
                }
                $rows[$row['authCode']] = $row;
            }
            if (count($rows) > $total) {
                throw new RuntimeException('dynamic_transaction_snapshot_incomplete');
            }
            $hashes[] = hash('sha256', $response->body());
            if ($page + 1 >= $pages) {
                if (count($rows) !== $total) {
                    throw new RuntimeException('dynamic_transaction_snapshot_incomplete');
                }

                return ['records' => array_values($rows), 'response_sha256' => $hashes,
                    'observed_at' => now('UTC')->toISOString()];
            }
        }
        throw new RuntimeException('dynamic_transaction_snapshot_incomplete');
    }

    private function assertScope(NiumDynamicIntent $intent): void
    {
        OwnerFirst::outside();
        $c = $intent->confirmed_terms['context']['corridor'];
        $environment = DynamicPayoutEnvironment::configured();
        $provider = IntegrationProvider::query()->findOrFail($intent->provider_id);
        // Historical snapshots were issued only on the gateway origins. A shared
        // Production identity never permits replay against another origin.
        $origin = $intent->confirmed_terms['context']['provider_origin'] ?? match ($c['environment']) {
            'production' => 'https://gateway.nium.com',
            'sandbox' => 'https://gatewaysandbox.nium.com',
            default => throw new RuntimeException('dynamic_provider_scope_unverified'),
        };
        $environment->assertBinding($origin, $c['environment']);
        if (DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()
            || $provider->code !== 'nium' || $provider->status !== 'active'
            || $c['client'] !== $this->nium->clientId() || $c['provider'] !== 'nium'
            || $intent->source_wallet !== $c['fundingWallet'] || $intent->customer !== $c['customer']) {
            throw new RuntimeException('dynamic_provider_scope_unverified');
        }
    }

    private function path(NiumDynamicIntent $intent, string $suffix): string
    {
        return $this->nium->path('/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/'.$suffix,
            ['client' => $this->nium->clientId(), 'customer' => $intent->customer, 'wallet' => $intent->source_wallet]);
    }
}
