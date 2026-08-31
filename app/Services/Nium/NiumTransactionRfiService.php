<?php

namespace App\Services\Nium;

use App\Models\IntegrationProvider;
use App\Models\NiumRfiCase;
use App\Models\Transaction;
use App\Models\UserProviderAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class NiumTransactionRfiService
{
    public function __construct(private readonly NiumService $niumService) {}

    public function fetchAndReconcile(IntegrationProvider $provider, string $notifiedTransactionId): Transaction
    {
        $notifiedTransactionId = trim($notifiedTransactionId);
        if ($notifiedTransactionId === '') {
            throw new RuntimeException('Nium transaction compliance callback value is required.');
        }

        $transactions = Transaction::query()
            ->where('provider_id', $provider->id)
            ->where('external_transaction_id', $notifiedTransactionId)
            ->limit(2)
            ->get();
        if ($transactions->count() !== 1) {
            throw new RuntimeException('Nium targeted transaction fetch requires one exact local transaction match.');
        }
        $transaction = $transactions->firstOrFail();
        $accounts = UserProviderAccount::query()
            ->where('provider_id', $provider->id)
            ->where('user_id', $transaction->user_id)
            ->limit(2)
            ->get();
        if ($accounts->count() !== 1) {
            throw new RuntimeException('Nium targeted transaction fetch requires one exact provider account match.');
        }
        $account = $accounts->firstOrFail();
        if ($account->id === 4) {
            throw new RuntimeException('Account 4 is protected and cannot enter the Transaction RFI workflow.');
        }
        if (blank($account->external_customer_id) || blank($account->external_account_id)) {
            throw new RuntimeException('Nium targeted transaction fetch requires verified customer and wallet identifiers.');
        }

        $endpoint = (string) config('services.nium.transaction_rfi_fetch_endpoint', '');
        if ($endpoint === '' || $endpoint !== (string) config('services.nium.wallet_transactions_endpoint')) {
            throw new RuntimeException('Nium targeted Transaction RFI fetch must use the configured wallet Transactions endpoint.');
        }
        $response = $this->niumService->get(
            path: $this->niumService->path($endpoint, [
                'clientHashId' => $this->niumService->clientId(),
                'customerHashId' => $account->external_customer_id,
                'walletHashId' => $account->external_account_id,
            ]),
            query: ['transactionId' => $notifiedTransactionId, 'page' => 0, 'size' => 20],
            user: $account->user,
            operation: 'transaction_rfi_authoritative_fetch',
            externalReference: $notifiedTransactionId,
        );

        $projection = $this->projectExactTransaction($this->successfulJson($response), $notifiedTransactionId);
        $this->reconcile($provider, $account, $transaction, $projection);

        return $transaction->fresh();
    }

    private function projectExactTransaction(array $data, string $transactionId): array
    {
        $items = $this->transactionItems($data);
        $matches = array_values(array_filter($items, static function (array $item) use ($transactionId): bool {
            foreach ([
                'transactionId',
                'authCode',
                'systemTraceAuditNumber',
                'retrievalReferenceNumber',
            ] as $field) {
                if (hash_equals($transactionId, trim((string) ($item[$field] ?? '')))) {
                return true;
                }
            }

            return false;
        }));
        if (count($matches) !== 1) {
            throw new RuntimeException('Nium authoritative transaction response is missing or ambiguous.');
        }

        $item = $matches[0];
        $status = strtoupper(trim((string) ($item['complianceStatus'] ?? '')));
        if (! in_array($status, ['CLEAR', 'PENDING', 'RFI_REQUESTED', 'REJECT'], true)) {
            throw new RuntimeException('Nium authoritative transaction response has an invalid complianceStatus.');
        }

        $rfis = [];
        foreach (array_slice((array) ($item['rfiDetails'] ?? []), 0, 20) as $rfi) {
            if (! is_array($rfi)) {
                continue;
            }
            $rfiStatus = strtoupper(trim((string) ($rfi['rfiStatus'] ?? '')));
            if (! in_array($rfiStatus, ['RFI_REQUESTED', 'RFI_RESPONDED'], true)) {
                throw new RuntimeException('Nium authoritative transaction response has an invalid rfiStatus.');
            }
            $rfiHashId = $this->identifier($rfi['rfiHashId'] ?? null);
            if ($rfiHashId === null) {
                throw new RuntimeException('Nium Transaction RFI has no factual rfiHashId.');
            }
            $requiredData = [];
            foreach (array_slice((array) ($rfi['requiredData'] ?? []), 0, 50) as $required) {
                if (! is_array($required)) {
                    continue;
                }
                $projectedRequired = array_filter([
                    'label' => $this->text($required['label'] ?? null, 255),
                    'value' => $this->text($required['value'] ?? null, 100),
                    'type' => $this->text($required['type'] ?? null, 100),
                ], static fn (mixed $value): bool => $value !== null && $value !== '');
                if ($projectedRequired !== []) {
                    $requiredData[] = $projectedRequired;
                }
            }
            $rfis[] = array_filter([
                'rfiHashId' => $rfiHashId,
                'rfiId' => $this->identifier($rfi['rfiId'] ?? null),
                'rfiStatus' => $rfiStatus,
                'description' => $this->text($rfi['description'] ?? null, 500),
                'mandatory' => isset($rfi['mandatory']) ? (bool) $rfi['mandatory'] : null,
                'type' => $this->text($rfi['type'] ?? null, 100),
                'documentType' => $this->text($rfi['documentType'] ?? null, 100),
                'remarks' => $this->text($rfi['remarks'] ?? null, 500),
                'transactionEntityType' => $this->text($rfi['transactionEntityType'] ?? null, 100),
                'requiredData' => $requiredData,
            ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
        }
        if ($status === 'RFI_REQUESTED' && $rfis === []) {
            throw new RuntimeException('Nium RFI_REQUESTED transaction has no actionable rfiDetails.');
        }

        return array_filter([
            'transactionId' => $transactionId,
            'authCode' => $this->identifier($item['authCode'] ?? null),
            'systemReferenceNumber' => $this->identifier($item['systemReferenceNumber'] ?? null),
            'complianceStatus' => $status,
            'rfiDetails' => $rfis,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function reconcile(IntegrationProvider $provider, UserProviderAccount $account, Transaction $transaction, array $projection): void
    {
        DB::transaction(function () use ($provider, $account, $transaction, $projection): void {
            $locked = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);
            $status = $projection['complianceStatus'];
            $rawData = (array) $locked->raw_data;
            $rawData['transaction_rfi'] = $projection;
            $locked->update([
                'compliance_status' => $status,
                'compliance_review_required' => in_array($status, ['RFI_REQUESTED', 'REJECT'], true),
                'compliance_reviewed_at' => $status === 'CLEAR' ? now() : null,
                'raw_data' => $rawData,
            ]);

            $seenFingerprints = [];
            foreach ((array) ($projection['rfiDetails'] ?? []) as $rfi) {
                $reference = $rfi['rfiHashId'] ?? null;
                if (! is_string($reference) || $reference === '') {
                    throw new RuntimeException('Nium Transaction RFI has no factual rfiHashId.');
                }
                $fingerprint = hash('sha256', $reference);
                if (isset($seenFingerprints[$fingerprint])) {
                    throw new RuntimeException('Nium authoritative transaction response contains duplicate RFI identifiers.');
                }
                $seenFingerprints[$fingerprint] = true;
                $case = NiumRfiCase::query()->firstOrCreate(
                    [
                        'provider_id' => $provider->id,
                        'user_provider_account_id' => $account->id,
                        'scope' => 'transaction',
                        'provider_reference_fingerprint' => $fingerprint,
                    ],
                    [
                        'transaction_id' => $locked->id,
                        'status' => $rfi['rfiStatus'] === 'RFI_RESPONDED' ? 'responded_under_review' : 'requested',
                        'evidence' => [...$rfi, 'transactionId' => $projection['transactionId'], 'authCode' => $projection['authCode'] ?? null],
                        'contract_gate' => 'official_transaction_rfi_v1',
                        'submission_state' => 'not_claimed',
                    ],
                );
                if ($case->transaction_id !== $locked->id) {
                    throw new RuntimeException('Nium Transaction RFI identifier is already attached to a different transaction.');
                }
                if (! in_array($case->status, ['resolved_authoritative_clear', 'rejected_authoritative'], true)) {
                    $nextStatus = $rfi['rfiStatus'] === 'RFI_RESPONDED'
                        ? 'responded_under_review'
                        : ($case->status === 'responded_under_review' ? 'responded_under_review' : 'requested');
                    $case->update([
                        'status' => $nextStatus,
                        'evidence' => [...$rfi, 'transactionId' => $projection['transactionId'], 'authCode' => $projection['authCode'] ?? null],
                        'reconciled_at' => now(),
                    ]);
                }
            }

            $cases = NiumRfiCase::query()->where('provider_id', $provider->id)
                ->where('transaction_id', $locked->id)->where('scope', 'transaction');
            if ($status === 'CLEAR') {
                $cases->whereIn('status', ['requested', 'responded_under_review'])
                    ->update(['status' => 'resolved_authoritative_clear', 'reconciled_at' => now()]);
            } elseif ($status === 'REJECT') {
                $cases->whereNotIn('status', ['resolved_authoritative_clear', 'rejected_authoritative'])
                    ->update(['status' => 'rejected_authoritative', 'reconciled_at' => now()]);
            }
        });
    }

    private function transactionItems(array $data): array
    {
        if (array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }
        foreach (['transactions', 'data.transactions', 'content', 'data'] as $path) {
            $value = Arr::get($data, $path);
            if (is_array($value) && array_is_list($value)) {
                return array_values(array_filter($value, 'is_array'));
            }
        }

        return isset($data['transactionId']) ? [$data] : [];
    }

    private function successfulJson(Response $response): array
    {
        $data = $response->json();
        if (! $response->successful() || ! is_array($data)) {
            throw new RuntimeException('Nium targeted transaction fetch failed.');
        }

        return $data;
    }

    private function identifier(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, 255, '');
    }

    private function text(mixed $value, int $limit): ?string
    {
        $value = trim(strip_tags((string) $value));

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
