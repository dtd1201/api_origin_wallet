<?php

namespace Tests\Unit;

use App\Models\Transfer;
use App\Services\Transfers\TransferClientReferenceIdempotency;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TransferClientReferenceIdempotencyTest extends TestCase
{
    #[DataProvider('equivalentAmounts')]
    public function test_numeric_representations_have_the_same_normalized_semantics(mixed $amount): void
    {
        $transfer = new Transfer($this->semantics(['source_amount' => '100.00000000']));

        $this->assertTrue(app(TransferClientReferenceIdempotency::class)->matches(
            $transfer,
            $this->semantics(['source_amount' => $amount]),
        ));
    }

    public function test_security_relevant_semantic_changes_do_not_match(): void
    {
        $service = app(TransferClientReferenceIdempotency::class);
        $stored = new Transfer($this->semantics());

        foreach ([
            ['beneficiary_id' => 99],
            ['source_amount' => 101],
            ['purpose_code' => 'OTHER'],
            ['source_currency' => 'EUR'],
            ['target_currency' => 'EUR'],
            ['transfer_type' => 'bank'],
            ['reference_text' => 'different'],
        ] as $change) {
            $this->assertFalse($service->matches($stored, $this->semantics($change)));
        }
    }

    public function test_only_exact_postgres_and_sqlite_constraint_violations_are_recognized(): void
    {
        $service = app(TransferClientReferenceIdempotency::class);

        $this->assertTrue($service->isExpectedUniqueViolation($this->queryException(
            '23505',
            'duplicate key violates unique constraint "transfers_user_provider_client_reference_unique"',
        )));
        $this->assertTrue($service->isExpectedUniqueViolation($this->queryException(
            '23000',
            'UNIQUE constraint failed: transfers.user_id, transfers.provider_id, transfers.client_reference',
        )));
        $this->assertFalse($service->isExpectedUniqueViolation($this->queryException(
            '23505',
            'duplicate key violates unique constraint "transfers_transfer_no_unique"',
        )));
        $this->assertFalse($service->isExpectedUniqueViolation($this->queryException(
            '08006',
            'connection failure',
        )));
    }

    public static function equivalentAmounts(): array
    {
        return [[100], ['100'], ['100.00000000']];
    }

    private function semantics(array $overrides = []): array
    {
        return array_replace([
            'beneficiary_id' => 7,
            'source_bank_account_id' => null,
            'fx_quote_id' => null,
            'transfer_type' => 'payout',
            'source_currency' => 'USD',
            'target_currency' => 'USD',
            'source_amount' => 100,
            'target_amount' => null,
            'fx_rate' => null,
            'fee_amount' => 0,
            'fee_currency' => 'USD',
            'purpose_code' => 'IR01811',
            'reference_text' => 'Invoice 42',
        ], $overrides);
    }

    private function queryException(string $sqlState, string $message): QueryException
    {
        $previous = new PDOException($message);
        $previous->errorInfo = [$sqlState, null, $message];

        return new QueryException('test', 'insert into transfers', [], $previous);
    }
}
