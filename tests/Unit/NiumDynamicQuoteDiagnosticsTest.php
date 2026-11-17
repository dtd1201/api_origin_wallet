<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nium\NiumDynamicQuoteDiagnostics;
use App\Services\Nium\NiumEvidencePersistenceException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

final class NiumDynamicQuoteDiagnosticsTest extends TestCase
{
    private const SECRET = 'synthetic-secret-key customer-private wallet-private amount=1234.56 body=private';

    public static function failures(): array
    {
        return [
            'known code with sensitive cause' => ['wallet_response_validation',
                new \RuntimeException('dynamic_wallet_currency_unverified', previous: new \RuntimeException(self::SECRET)),
                \RuntimeException::class, 'dynamic_wallet_currency_unverified'],
            'JSON message' => ['quote_response_validation', new \JsonException(self::SECRET), \JsonException::class, 'invalid_json'],
            'SQL and bindings' => ['wallet_snapshot_persistence',
                new QueryException('private-connection', 'update private_table set body = ?', [self::SECRET], new \PDOException(self::SECRET)),
                QueryException::class, 'database_query_failed'],
            'transport message' => ['wallet_transport', new ConnectionException(self::SECRET), ConnectionException::class, 'provider_transport_failed'],
            'evidence object' => ['quote_transport', new NiumEvidencePersistenceException(['body' => self::SECRET]),
                NiumEvidencePersistenceException::class, 'provider_evidence_persistence_failed'],
            'unknown class and message' => ['eligibility', new class(self::SECRET) extends \Exception {}, Throwable::class, 'unexpected_exception'],
            'untrusted subclass name' => ['quote_transport', new class(self::SECRET) extends \RuntimeException {}, \RuntimeException::class, 'unexpected_exception'],
            'code prefix is not allowed' => ['quote_response_validation', new \RuntimeException('payout_quote_response_invalid '.self::SECRET),
                \RuntimeException::class, 'unexpected_exception'],
            'untrusted stage' => [self::SECRET, new \TypeError(self::SECRET), \TypeError::class, 'unexpected_exception'],
        ];
    }

    #[DataProvider('failures')]
    public function test_only_allowlisted_scalars_reach_the_logger(string $stage, Throwable $exception, string $class, string $code): void
    {
        Log::shouldReceive('warning')->once()->with('nium.dynamic_quote.failed', [
            'stage' => $stage === self::SECRET ? 'unknown' : $stage,
            'exception_class' => $class, 'internal_code' => $code,
        ]);
        $diagnostics = new NiumDynamicQuoteDiagnostics;
        $diagnostics->stage($stage);
        $diagnostics->report($exception);
    }

    public function test_one_invocation_reports_once_even_when_called_again(): void
    {
        Log::shouldReceive('warning')->once()->with('nium.dynamic_quote.failed', [
            'stage' => 'eligibility', 'exception_class' => \RuntimeException::class,
            'internal_code' => 'dynamic_quote_local_eligibility_changed',
        ]);
        $diagnostics = new NiumDynamicQuoteDiagnostics;
        $diagnostics->report(new \RuntimeException('dynamic_quote_local_eligibility_changed'));
        $diagnostics->stage('quote_transport');
        $diagnostics->report(new \RuntimeException(self::SECRET));
        $diagnostics->refusal(self::SECRET);
    }

    public function test_expected_refusals_have_no_exception_and_cannot_log_arbitrary_codes(): void
    {
        Log::shouldReceive('warning')->once()->with('nium.dynamic_quote.failed', [
            'stage' => 'eligibility', 'exception_class' => null, 'internal_code' => 'unexpected_exception',
        ]);
        (new NiumDynamicQuoteDiagnostics)->refusal(self::SECRET);
    }

    public function test_failed_logger_is_best_effort_without_a_second_attempt(): void
    {
        Log::shouldReceive('warning')->once()->andThrow(new \RuntimeException(self::SECRET));
        $diagnostics = new NiumDynamicQuoteDiagnostics;
        $diagnostics->report(new \RuntimeException(self::SECRET));
        $diagnostics->report(new \RuntimeException(self::SECRET));
        $this->addToAssertionCount(1); // Reaching here means neither logger failure escaped.
    }

    public function test_standard_laravel_monolog_record_contains_only_the_safe_diagnostic_context(): void
    {
        $handler = new \Monolog\Handler\TestHandler;
        Log::swap(new \Illuminate\Log\Logger(new \Monolog\Logger('diagnostic-test', [$handler])));
        $diagnostics = new NiumDynamicQuoteDiagnostics;
        $diagnostics->stage('wallet_response_validation');
        $diagnostics->report(new \JsonException(self::SECRET));
        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('nium.dynamic_quote.failed', $records[0]->message);
        $this->assertSame(['stage' => 'wallet_response_validation',
            'exception_class' => \JsonException::class, 'internal_code' => 'invalid_json'], $records[0]->context);
        $this->assertSame([], $records[0]->extra);
        $this->assertIsString($records[0]->formatted);
        $this->assertStringNotContainsString(self::SECRET, $records[0]->formatted);
    }
}
