<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutConfirmation;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutEvidence;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutEvidenceSource;
use App\Services\Nium\DynamicConfirmation\LockedPayoutRecords;
use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Fixture assertions only: never an application binding or authenticated provider adapter. */
final class DynamicConfirmationEvidenceStub implements DynamicPayoutEvidenceSource
{
    public int $calls = 0;

    public string $rule = 'SINGLE_USE_PROVIDER_CLIENT_CUSTOMER';

    public array $versions = ['fees' => 'fixture-v1', 'wallet_debit' => 'fixture-v1',
        'quote_consumption' => 'fixture-v1', 'corridor' => 'fixture-v1', 'account_eligibility' => 'fixture-v1',
        'compliance' => 'fixture-v1', 'registered_receiving_account' => 'fixture-v1', 'wallet_availability' => 'fixture-v1'];

    public ?Closure $duringValidation = null;

    public function __construct(public PayoutConfirmation $review, public string $registeredBinding) {}

    public function refreshForExecution(\App\Models\NiumDynamicIntent $intent): void
    {
        if (DB::transactionLevel() !== 0) throw new LogicException('Fixture refresh must be outside transactions.');
    }

    public function revalidate(LockedPayoutRecords $records, DateTimeImmutable $at): DynamicPayoutEvidence
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Evidence must be acquired in the confirmation transaction.');
        }
        $this->calls++;
        if ($this->duringValidation !== null) {
            ($this->duringValidation)($records, $at);
        }

        return new DynamicPayoutEvidence($this->review, $this->registeredBinding, $this->rule,
            $this->versions, $at, new DateTimeImmutable('2030-01-01T01:00:00Z'));
    }
}
