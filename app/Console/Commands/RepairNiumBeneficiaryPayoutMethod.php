<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Services\Nium\NiumBeneficiaryPayoutMethodResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class RepairNiumBeneficiaryPayoutMethod extends Command
{
    public const AUDIT_ACTION = 'beneficiary.nium_payout_method_repaired';

    private const APPROVAL = 'REPAIR_NIUM_BENEFICIARY_PAYOUT_METHOD';

    protected $signature = 'nium:repair-beneficiary-payout-method
        {beneficiary : Exact beneficiary database ID}
        {--user= : Expected beneficiary user ID}
        {--provider= : Expected integration provider ID}
        {--external-beneficiary-id= : Expected Nium beneficiary ID}
        {--payout-method=SWIFT : Expected payout method from successful request evidence}
        {--apply : Persist the verified payout method}
        {--approve= : Exact human approval marker required with --apply}
        {--operator= : Required operator and ticket context with --apply}';

    protected $description = 'Verify Nium beneficiary request evidence and explicitly repair its authoritative payout method';

    public function handle(NiumBeneficiaryPayoutMethodResolver $resolver): int
    {
        try {
            $result = DB::transaction(function () use ($resolver): array {
                $beneficiary = Beneficiary::query()->lockForUpdate()->findOrFail((int) $this->argument('beneficiary'));
                $this->assertExpectedIdentity($beneficiary);
                if (strtolower((string) $beneficiary->provider()->value('code')) !== 'nium') {
                    throw new RuntimeException('The expected provider is not Nium.');
                }
                $evidence = $resolver->successfulRequestEvidence($beneficiary);

                if ($evidence === null) {
                    throw new RuntimeException('No matching successful Nium beneficiary_create request evidence was found.');
                }

                $expectedPayoutMethod = strtoupper(trim((string) $this->option('payout-method')));
                if ($evidence->payoutMethod !== $expectedPayoutMethod) {
                    throw new RuntimeException("Request evidence payout method [{$evidence->payoutMethod}] does not match [{$expectedPayoutMethod}].");
                }

                if ((bool) $this->option('apply')) {
                    $this->assertApproved();
                    $beneficiary->update(['payout_method' => $evidence->payoutMethod]);
                    AuditLog::query()->create([
                        'user_id' => $beneficiary->user_id,
                        'action' => self::AUDIT_ACTION,
                        'entity_type' => 'beneficiary',
                        'entity_id' => (string) $beneficiary->id,
                        'old_data' => ['payout_method' => null],
                        'new_data' => [
                            'beneficiary_id' => (int) $beneficiary->id,
                            'provider_id' => (int) $beneficiary->provider_id,
                            'user_id' => (int) $beneficiary->user_id,
                            'api_request_log_id' => $evidence->apiRequestLogId,
                            'external_beneficiary_id' => $evidence->externalBeneficiaryId,
                            'payout_method' => $evidence->payoutMethod,
                            'operator_context' => trim((string) $this->option('operator')),
                        ],
                    ]);
                }

                return [$beneficiary, $evidence];
            });
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        [$beneficiary, $evidence] = $result;
        $action = (bool) $this->option('apply') ? 'repaired' : 'verified (dry run)';
        $this->info("Beneficiary {$beneficiary->id} {$action}: {$evidence->payoutMethod} from ApiRequestLog {$evidence->apiRequestLogId}.");

        return self::SUCCESS;
    }

    private function assertExpectedIdentity(Beneficiary $beneficiary): void
    {
        $expected = [
            'user_id' => $this->requiredIntegerOption('user'),
            'provider_id' => $this->requiredIntegerOption('provider'),
            'external_beneficiary_id' => trim((string) $this->option('external-beneficiary-id')),
        ];

        foreach ($expected as $field => $value) {
            if ($value === '' || (string) $beneficiary->{$field} !== (string) $value) {
                throw new RuntimeException("Beneficiary {$field} does not match the explicit expected value.");
            }
        }
    }

    private function requiredIntegerOption(string $name): int
    {
        $value = filter_var($this->option($name), FILTER_VALIDATE_INT);

        if (! is_int($value) || $value < 1) {
            throw new RuntimeException("Option --{$name} must be a positive integer.");
        }

        return $value;
    }

    private function assertApproved(): void
    {
        if ((string) $this->option('approve') !== self::APPROVAL) {
            throw new RuntimeException('The exact repair approval marker is required with --apply.');
        }

        if (trim((string) $this->option('operator')) === '') {
            throw new RuntimeException('Operator and ticket context are required with --apply.');
        }
    }
}
