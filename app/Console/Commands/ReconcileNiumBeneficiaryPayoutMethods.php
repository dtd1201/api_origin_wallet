<?php

namespace App\Console\Commands;

use App\Models\ApiRequestLog;
use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Nium\NiumService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class ReconcileNiumBeneficiaryPayoutMethods extends Command
{
    public const AUDIT_ACTION = 'beneficiary.nium_payout_method_reconciled';

    private const APPROVAL = 'REPAIR_NIUM_BENEFICIARY_PAYOUT_METHOD';

    protected $signature = 'nium:reconcile-beneficiary-payout-methods
        {user : Exact local user ID}
        {--provider= : Exact Nium integration provider ID}
        {--apply : Persist every verified repair atomically}
        {--approve= : Exact human approval marker required with --apply}
        {--operator= : Required operator and ticket context with --apply}';

    protected $description = 'Reconcile legacy beneficiary payout methods from the read-only Nium beneficiary list';

    public function handle(NiumService $nium): int
    {
        try {
            $userId = $this->positiveInteger($this->argument('user'), 'user');
            $providerId = $this->positiveInteger($this->option('provider'), '--provider');
            $apply = (bool) $this->option('apply');
            $operator = trim((string) $this->option('operator'));

            if ($apply) {
                $this->assertApproved($operator);
            }

            $user = User::query()->findOrFail($userId);
            $provider = IntegrationProvider::query()->findOrFail($providerId);
            if (strtolower((string) $provider->code) !== 'nium') {
                throw new RuntimeException('The expected provider is not Nium.');
            }

            $snapshots = Beneficiary::query()
                ->where('provider_id', $providerId)
                ->where('user_id', $userId)
                ->whereNotNull('external_beneficiary_id')
                ->orderBy('id')
                ->get()
                ->mapWithKeys(fn (Beneficiary $beneficiary): array => [
                    $beneficiary->id => $this->snapshot($beneficiary),
                ])->all();

            $response = $nium->get(
                path: $nium->path((string) config('services.nium.beneficiary_endpoint'), [
                    'client' => $nium->clientId(),
                    'customer' => $nium->customerId($user),
                ]),
                user: $user,
                operation: 'beneficiary_list_reconciliation',
                externalReference: (string) $userId,
            );

            if (! $response->successful()) {
                throw new RuntimeException("Nium beneficiary list request failed with HTTP {$response->status()}.");
            }

            $providerEvidence = $this->providerEvidence((array) $response->json());
            $requestLogId = ApiRequestLog::query()
                ->where('provider_id', $providerId)
                ->where('user_id', $userId)
                ->where('operation', 'beneficiary_list_reconciliation')
                ->where('request_method', 'GET')
                ->latest('id')
                ->value('id');
            $results = $this->evaluate($snapshots, $providerEvidence);

            foreach ($results as $result) {
                $prefix = $result['accepted'] ? 'MATCHED' : 'REJECTED';
                $this->line("{$prefix} beneficiary {$result['id']} [{$result['external_id']}]: {$result['reason']}");
            }

            if ($apply) {
                $accepted = array_values(array_filter($results, fn (array $result): bool => $result['accepted']));
                DB::transaction(function () use ($accepted, $snapshots, $providerId, $userId, $operator, $requestLogId): void {
                    foreach ($accepted as $result) {
                        $beneficiary = Beneficiary::query()->lockForUpdate()->findOrFail($result['id']);
                        if ($this->snapshot($beneficiary) !== $snapshots[$beneficiary->id]) {
                            throw new RuntimeException("Beneficiary {$beneficiary->id} changed after reconciliation and was not modified.");
                        }

                        $beneficiary->update(['payout_method' => 'SWIFT']);
                        AuditLog::query()->create([
                            'user_id' => $userId,
                            'action' => self::AUDIT_ACTION,
                            'entity_type' => 'beneficiary',
                            'entity_id' => (string) $beneficiary->id,
                            'old_data' => ['payout_method' => null],
                            'new_data' => [
                                'user_id' => $userId,
                                'provider_id' => $providerId,
                                'beneficiary_id' => (int) $beneficiary->id,
                                'external_beneficiary_id' => (string) $beneficiary->external_beneficiary_id,
                                'destination_country' => 'HK',
                                'destination_currency' => 'USD',
                                'payout_method' => 'SWIFT',
                                'operator_context' => $operator,
                                'reconciliation_api_request_log_id' => $requestLogId !== null ? (int) $requestLogId : null,
                            ],
                        ]);
                    }
                });
            }

            $matched = count(array_filter($results, fn (array $result): bool => $result['accepted']));
            $action = $apply ? 'applied' : 'dry run';
            $this->info("Reconciliation {$action}: {$matched} matched, ".(count($results) - $matched).' rejected.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function providerEvidence(array $payload): array
    {
        $items = array_is_list($payload) ? $payload : ($payload['data'] ?? $payload['content'] ?? $payload['beneficiaries'] ?? []);
        $grouped = [];

        foreach (is_array($items) ? $items : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $externalId = $item['beneficiaryHashId'] ?? $item['id'] ?? null;
            if (! is_string($externalId) || trim($externalId) === '') {
                continue;
            }
            $grouped[trim($externalId)][] = [
                'country' => $item['destinationCountry'] ?? null,
                'currency' => $item['destinationCurrency'] ?? null,
                'payout_method' => $item['payoutMethod'] ?? null,
            ];
        }

        return $grouped;
    }

    private function evaluate(array $snapshots, array $providerEvidence): array
    {
        $localCounts = array_count_values(array_column($snapshots, 'external_id'));
        $results = [];

        foreach ($snapshots as $snapshot) {
            $reason = match (true) {
                $snapshot['status'] !== 'active' => 'local status is not active',
                $snapshot['country'] !== 'HK' => 'local destination country is not HK',
                $snapshot['currency'] !== 'USD' => 'local destination currency is not USD',
                $snapshot['payout_method'] !== null => 'local payout method is already set',
                ($localCounts[$snapshot['external_id']] ?? 0) !== 1 => 'duplicate local external beneficiary ID',
                count($providerEvidence[$snapshot['external_id']] ?? []) === 0 => 'external beneficiary ID is absent from provider response',
                count($providerEvidence[$snapshot['external_id']] ?? []) !== 1 => 'duplicate external beneficiary ID in provider response',
                ($providerEvidence[$snapshot['external_id']][0]['country'] ?? null) !== 'HK' => 'provider destination country is not HK',
                ($providerEvidence[$snapshot['external_id']][0]['currency'] ?? null) !== 'USD' => 'provider destination currency is not USD',
                ($providerEvidence[$snapshot['external_id']][0]['payout_method'] ?? null) !== 'SWIFT' => 'provider payout method is not SWIFT',
                default => 'exact HK/USD/SWIFT provider evidence verified',
            };

            $results[] = [
                'id' => $snapshot['id'],
                'external_id' => $snapshot['external_id'],
                'accepted' => $reason === 'exact HK/USD/SWIFT provider evidence verified',
                'reason' => $reason,
            ];
        }

        return $results;
    }

    private function snapshot(Beneficiary $beneficiary): array
    {
        return [
            'id' => (int) $beneficiary->id,
            'user_id' => (int) $beneficiary->user_id,
            'provider_id' => (int) $beneficiary->provider_id,
            'external_id' => (string) $beneficiary->external_beneficiary_id,
            'status' => (string) $beneficiary->status,
            'country' => (string) $beneficiary->country_code,
            'currency' => (string) $beneficiary->currency,
            'payout_method' => $beneficiary->payout_method,
        ];
    }

    private function positiveInteger(mixed $value, string $name): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        if (! is_int($value) || $value < 1) {
            throw new RuntimeException("{$name} must be a positive integer.");
        }

        return $value;
    }

    private function assertApproved(string $operator): void
    {
        if ((string) $this->option('approve') !== self::APPROVAL) {
            throw new RuntimeException('The exact repair approval marker is required with --apply.');
        }
        if ($operator === '') {
            throw new RuntimeException('Operator and ticket context are required with --apply.');
        }
    }
}
