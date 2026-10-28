<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\NiumVirtualAccount;
use App\Models\NiumVirtualAccountAssignmentScope;
use App\Models\NiumVirtualAccountDetail;
use App\Models\NiumVirtualAccountOperation as Operation;
use App\Models\NiumVirtualAccountRequest;
use App\Models\UserProviderAccount;
use App\Services\Integrations\ProviderHttpClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class NiumVirtualAccountDetailService
{
    private const ENDPOINT = '/api/v2/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/paymentIds';

    private const FIELDS = [
        'accountName' => 'account_name',
        'fullBankName' => 'bank_name',
        'bankName' => 'provider_bank_name',
        'bankAddress' => 'bank_address',
        'accountType' => 'provider_account_type',
        'accountCategory' => 'provider_account_category',
    ];

    public function __construct(private readonly NiumService $niumService) {}

    /** GET enriches existing ownership only. Failures leave assignment and stored details intact. */
    public function sync(NiumVirtualAccount $virtualAccount): ?NiumVirtualAccountDetail
    {
        return $this->retrieve($virtualAccount);
    }

    /** Automatic jobs must never fall back to a legacy/current-account identity. */
    public function syncEnrolled(NiumVirtualAccount $virtualAccount, int $operationId, callable $guard, callable $beforeGet): ?NiumVirtualAccountDetail
    {
        return $this->retrieve($virtualAccount, $operationId, $guard, $beforeGet);
    }

    private function retrieve(NiumVirtualAccount $virtualAccount, ?int $operationId = null, ?callable $guard = null, ?callable $beforeGet = null): ?NiumVirtualAccountDetail
    {
        if (DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
            throw new RuntimeException('VA details must be retrieved after assignment commits.');
        }
        $before = DB::transaction(function () use ($virtualAccount, $operationId, $guard): array {
            $context = $this->lockedContext((int) $virtualAccount->id);
            if ($operationId !== null && $context['operation_id'] !== $operationId) {
                throw new RuntimeException('VA enrichment task does not match terminal enrolled ownership.');
            }
            if ($guard !== null) {
                $guard();
            }

            return $context;
        });
        $target = $before['target'];
        $account = $before['account'];
        $this->niumService->assertConfigured();
        $client = new ProviderHttpClient(
            provider: $account->provider, serviceConfigKey: 'nium',
            headers: ['x-request-id' => (string) Str::uuid()],
            operationalContext: ['operation' => 'fetch_virtual_account_details',
                'external_reference' => 'virtual-account-'.$virtualAccount->id, 'client_hash_id' => $target['client_id']],
            // Existing read-only transport forbids redirects, retries and token-acquisition POSTs.
            readOnlyVirtualAccountReconciliation: true,
        );
        $path = '/api/v2/client/'.rawurlencode($target['client_id']).'/customer/'
            .rawurlencode($target['customer_id']).'/wallet/'.rawurlencode($target['wallet_id']).'/paymentIds';
        $match = null;
        $pages = null;
        $totalElements = null;
        $seenIds = [];
        for ($page = 0; $page < ($pages ?? 1); $page++) {
            if ($beforeGet !== null) {
                $beforeGet($target);
            }
            $response = $client->get($target['provider_origin'].$path, [
                'uniquePaymentId' => $before['va']->provider_payment_id, 'currencyCode' => $before['va']->currency,
                'status' => 'Active', 'page' => $page, 'size' => 20, 'order' => 'DESC', 'property' => 'createdAt',
            ], $account->user);
            if ($response->status() !== 200) {
                $retryAfter = $response->header('Retry-After');
                $delay = ctype_digit((string) $retryAfter) ? min(2147483647, (float) $retryAfter)
                    : max(60, (int) (strtotime((string) $retryAfter) ?: time()) - time());
                throw new NiumVirtualAccountDetailRetry((int) max(60, $delay), $response->status() === 429
                    ? hash('sha256', $target['provider_origin'].'|'.$target['client_id']) : null);
            }
            $data = $response->json();
            if (! is_array($data) || array_is_list($data) || array_key_exists('errors', $data)) {
                throw new RuntimeException('Malformed Nium VA details response.');
            }
            $this->assertReportedTarget($data, $target, $before['va']);
            // Legacy compatibility only; enrolled reads use the documented content envelope.
            $items = $data['content'] ?? ($before['enrolled'] ? null
                : ($data['paymentIds'] ?? data_get($data, 'data.content') ?? $data['data'] ?? null));
            if (! is_array($items) || ! array_is_list($items)) {
                throw new RuntimeException('Malformed Nium VA details content.');
            }
            $reportedPages = $data['totalPages'] ?? 1;
            if (! is_int($reportedPages) || $reportedPages < 0 || $reportedPages > 20
                || ($pages !== null && $pages !== max(1, $reportedPages))
                || ($reportedPages === 0 && $items !== [])) {
                throw new RuntimeException('Invalid or changing VA details pagination.');
            }
            $pages = max(1, $reportedPages);
            if (array_key_exists('totalElements', $data)) {
                if (! is_int($data['totalElements']) || $data['totalElements'] < 0
                    || ($totalElements !== null && $totalElements !== $data['totalElements'])) {
                    throw new RuntimeException('Invalid or changing VA details count.');
                }
                $totalElements = $data['totalElements'];
            } elseif ($totalElements !== null) {
                throw new RuntimeException('Missing VA details count on a subsequent page.');
            }
            foreach ($items as $item) {
                if (! is_array($item) || array_is_list($item)) {
                    throw new RuntimeException('Malformed Nium VA details item.');
                }
                $id = $item['uniquePaymentId'] ?? ($before['enrolled'] ? null
                    : ($item['paymentId'] ?? $item['virtualAccountNumber'] ?? null));
                if (! NiumPaymentIdValidator::isValid($id)) {
                    throw new RuntimeException('Invalid VA details Payment ID.');
                }
                if (isset($seenIds[$id])) {
                    throw new RuntimeException('Duplicate VA details identity requires review.');
                }
                $seenIds[$id] = true;
                foreach (['uniquePaymentId', 'paymentId', 'virtualAccountNumber'] as $alias) {
                    if (array_key_exists($alias, $item) && $item[$alias] !== $id) {
                        throw new RuntimeException('Contradictory VA details Payment IDs.');
                    }
                }
                if ($id !== $before['va']->provider_payment_id) {
                    continue;
                }
                $this->assertReportedTarget($item, $target, $before['va']);
                if (array_key_exists('errors', $item) || (isset($item['status']) && $item['status'] !== 'Active')) {
                    throw new RuntimeException('VA details are not an active account response.');
                }
                if ($before['bank'] !== null && isset($item['bankName'])
                    && (! is_string($item['bankName']) || (trim($item['bankName']) !== '' && $item['bankName'] !== $before['bank']))) {
                    throw new RuntimeException('Contradictory VA details bank identifier.');
                }
                $match = $item;
            }
        }
        if ($totalElements !== null && count($seenIds) !== $totalElements) {
            throw new RuntimeException('Incomplete VA details listing.');
        }
        if ($match === null) {
            return null;
        }
        $facts = $this->facts($match);

        return DB::transaction(function () use ($virtualAccount, $before, $facts, $guard): ?NiumVirtualAccountDetail {
            $after = $this->lockedContext((int) $virtualAccount->id);
            if ($guard !== null) {
                $guard();
            }
            if ($before['binding'] !== $after['binding']) {
                throw new RuntimeException('VA ownership or trusted target changed during detail retrieval.');
            }
            $detail = $after['detail'];
            if ($before['version'] !== $after['version']) {
                throw new RuntimeException('Stale VA detail retrieval; repeat the read-only GET.');
            }
            if ($facts === []) {
                return $detail;
            }
            $detail ??= new NiumVirtualAccountDetail(['nium_virtual_account_id' => $virtualAccount->id]);
            foreach ($facts as $column => $value) {
                // No provider update sequence is documented. A later local GET cannot prove
                // freshness; nonempty conflicting facts require review, even sequentially.
                $existing = $detail->getRawOriginal($column);
                if ($existing !== null && trim((string) $existing) !== '' && $existing !== $value) {
                    throw new RuntimeException('Conflicting stored VA banking details require review.');
                }
                $detail->setAttribute($column, $value);
            }
            if ($detail->isDirty()) {
                $detail->enrichment_version = $after['version'] + 1;
                $detail->save();
                // Hydrate database defaults and nullable columns before returning the
                // same detail shape as a later list/read, including bank_name's fallback.
                $detail->refresh();
            }

            return $detail;
        });
    }

    /** Caller holds a short transaction. Lock order matches the assignment ledger. */
    private function lockedContext(int $id): array
    {
        $ownerId = NiumVirtualAccount::query()->whereKey($id)->value('user_provider_account_id');
        $account = UserProviderAccount::query()->lockForUpdate()->findOrFail($ownerId);
        $operations = Operation::query()->where('nium_virtual_account_id', $id)
            ->whereIn('id', NiumVirtualAccountRequest::query()->select('operation_id'))->get();
        if ($operations->count() > 1) {
            throw new RuntimeException('Ambiguous ledger ownership for VA details.');
        }
        $request = null;
        $operation = $operations->first();
        $bank = null;
        if ($operation !== null) {
            $unlocked = NiumVirtualAccountRequest::query()->findOrFail($operation->id);
            $scope = NiumVirtualAccountAssignmentScope::query()->lockForUpdate()->findOrFail($unlocked->scope_key);
            $request = NiumVirtualAccountRequest::query()->lockForUpdate()->findOrFail($operation->id);
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            try {
                $identity = $request->identity();
                $identity->assertCurrentTarget($account);
            } catch (InvalidArgumentException $exception) {
                throw new RuntimeException('Frozen VA details target no longer matches trusted configuration.', previous: $exception);
            }
            $target = $identity->snapshot();
            if ($request->operation_state !== Operation::ASSIGNED || $operation->status !== Operation::ASSIGNED
                || $request->scope_key !== $scope->scope_key || $identity->scopeKey() !== $scope->scope_key
                || ! hash_equals($request->payload_sha256, $identity->payloadHash())
                || $operation->user_provider_account_id !== $account->id
                || $target['provider_account_id'] !== (int) $account->id
                || $target['provider_id'] !== (int) $account->provider_id || $target['user_id'] !== (int) $account->user_id
                || $operation->api_request_log_id !== $request->resolution_api_request_log_id) {
                throw new RuntimeException('VA details require verified terminal ledger ownership.');
            }
            $log = ApiRequestLog::query()->lockForUpdate()->findOrFail($request->resolution_api_request_log_id);
            $log->niumExchangeEvidence()->lockForUpdate()->firstOrFail();
            $proof = app(NiumVirtualAccountSafetyLedger::class)->verifiedAssignmentResponse($request);
            if ($proof['uniquePaymentId'] !== $operation->provider_payment_id) {
                throw new RuntimeException('VA assignment evidence has a different Payment ID.');
            }
            $bank = $target['payload']['bankName'] ?? null;
        } else {
            $target = [
                'provider_origin' => NiumVirtualAccountRequestIdentity::origin((string) config('services.nium.base_url')),
                'client_id' => $this->niumService->clientId(),
                'customer_id' => $account->external_customer_id, 'wallet_id' => $account->external_account_id,
            ];
        }
        $va = NiumVirtualAccount::query()->lockForUpdate()->findOrFail($id);
        if ($va->user_provider_account_id !== $account->id || $va->status !== 'assigned'
            || ! NiumPaymentIdValidator::isValid($va->provider_payment_id)
            || strtolower((string) $account->provider()->value('code')) !== 'nium'
            || config('services.nium.payment_ids_endpoint') !== self::ENDPOINT) {
            throw new RuntimeException('VA details require an already assigned Nium account.');
        }
        if ($operation !== null && ($operation->nium_virtual_account_id !== $va->id
            || $operation->provider_payment_id !== $va->provider_payment_id || $operation->currency !== $va->currency
            || $operation->account_category !== $va->account_category || $operation->account_type !== $va->account_type)) {
            throw new RuntimeException('VA identity contradicts its assigned operation.');
        }
        foreach (['client_id', 'customer_id', 'wallet_id'] as $key) {
            if (! is_string($target[$key]) || preg_match('/^[A-Za-z0-9_-]{1,255}$/D', $target[$key]) !== 1) {
                throw new RuntimeException('Invalid VA details target identifier.');
            }
        }
        // Detected collisions are quarantined; this does not solve all-writer H2.
        if (NiumVirtualAccount::query()->where('provider_payment_id', $va->provider_payment_id)->whereKeyNot($id)->exists()
            || Operation::query()->where('provider_payment_id', $va->provider_payment_id)
                ->where(fn ($q) => $q->whereNull('nium_virtual_account_id')->orWhere('nium_virtual_account_id', '<>', $id))->exists()) {
            throw new RuntimeException('VA Payment ID ownership collision requires review.');
        }
        $detail = NiumVirtualAccountDetail::query()->where('nium_virtual_account_id', $id)->lockForUpdate()->first();

        return [
            'account' => $account, 'va' => $va, 'target' => $target, 'bank' => $bank,
            'operation_id' => $operation === null ? null : (int) $operation->id,
            'enrolled' => $request !== null, 'detail' => $detail, 'version' => $detail?->enrichment_version ?? 0,
            'binding' => hash('sha256', json_encode([
                $target, $account->id, $account->user_id, $account->provider_id,
                $va->only(['id', 'user_provider_account_id', 'provider_payment_id', 'currency', 'account_category', 'account_type', 'status']),
                $request?->operation_id, $request?->state_version, $request?->resolution_evidence_sha256,
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    private function assertReportedTarget(array $data, array $target, NiumVirtualAccount $va): void
    {
        // Echoes never replace the authenticated path or establish assignment ownership.
        foreach (['clientHashId' => $target['client_id'], 'customerHashId' => $target['customer_id'],
            'walletHashId' => $target['wallet_id'], 'currencyCode' => $va->currency, 'currency' => $va->currency,
            'accountCategory' => $va->account_category, 'uniquePaymentId' => $va->provider_payment_id] as $key => $expected) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== ''
                && (! is_string($data[$key]) || ($expected !== null && $data[$key] !== $expected))) {
                throw new RuntimeException('Contradictory Nium VA details target.');
            }
            if (in_array($key, ['clientHashId', 'customerHashId', 'walletHashId'], true)
                && array_key_exists($key, $data) && $data[$key] !== $expected) {
                throw new RuntimeException('Malformed Nium VA details target.');
            }
        }
    }

    private function facts(array $item): array
    {
        $facts = [];
        foreach (self::FIELDS as $field => $column) {
            $value = $this->stringFact($item[$field] ?? null, $field === 'bankAddress' ? 16000 : 255, $field === 'bankAddress');
            if ($value !== null) {
                $facts[$column] = $value;
            }
        }
        foreach ([1 => ['routing_code_type', 'routing_code_value'], 2 => ['routing_code_type_2', 'routing_code_value_2']] as $number => [$typeColumn, $valueColumn]) {
            $type = $this->stringFact($item['routingCodeType'.$number] ?? null);
            $value = $this->stringFact($item['routingCodeValue'.$number] ?? null);
            if (($type === null) !== ($value === null)) {
                throw new RuntimeException('Incomplete VA routing-code pair; existing details were preserved.');
            }
            if ($type !== null) {
                $facts[$typeColumn] = $type;
                $facts[$valueColumn] = $value;
            }
        }

        return $facts;
    }

    private function stringFact(mixed $value, int $limit = 255, bool $multiline = false): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $controls = $multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';
        if (! is_string($value) || strlen($value) > $limit || preg_match($controls, $value)) {
            throw new RuntimeException('Malformed VA banking detail field.');
        }

        return $value;
    }
}
