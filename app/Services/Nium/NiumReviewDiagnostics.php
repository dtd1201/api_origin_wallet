<?php

namespace App\Services\Nium;

use App\Models\Balance;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/** One review invocation only. Never attach request data, model identities or exception objects. */
final class NiumReviewDiagnostics
{
    private string $stage = 'admission';
    private ?string $causeCategory = null;
    private string $snapshot = 'unchecked';
    private string $refresh = 'unchecked';
    private string $fxOutcome = 'not_attempted';

    public function stage(string $stage): void
    {
        $this->stage = $stage;
    }

    public function snapshot(Balance $balance): void
    {
        $this->snapshot = match (true) {
            $balance->as_of === null => 'missing',
            $balance->as_of->isFuture() => 'future',
            $balance->as_of->lessThanOrEqualTo(now()->subMinutes(5)) => 'stale',
            default => 'current',
        };
    }

    public function refresh(string $status): void
    {
        $this->refresh = $status;
    }

    public function fxOutcome(string $outcome): void
    {
        $this->fxOutcome = $outcome;
    }

    /** Capture before the funding boundary replaces the original exception with validation. */
    public function cause(Throwable $exception): void
    {
        $this->causeCategory ??= self::category($exception);
        if ($this->refresh === 'attempting') {
            $this->refresh = 'failed';
        }
    }

    public function report(Throwable $exception): void
    {
        try {
            Log::warning('nium.sgd_local.review_failed', [
                'stage' => $this->stage,
                'exception_category' => self::category($exception),
                'cause_category' => $this->causeCategory,
                'funding_snapshot' => $this->snapshot,
                'funding_refresh' => $this->refresh,
                'fx_outcome' => $this->fxOutcome,
            ]);
        } catch (Throwable) {
            // Diagnostics must never replace the original exception or change the HTTP contract.
        }
    }

    private static function category(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof ValidationException => 'validation',
            $exception instanceof ConnectionException => 'provider_transport',
            $exception instanceof RequestException, $exception instanceof NiumProviderRequestException => 'provider_http',
            $exception instanceof NiumEvidencePersistenceException => 'evidence_persistence',
            $exception instanceof QueryException => 'database',
            $exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException => 'not_found',
            $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface => 'http',
            $exception instanceof RuntimeException => 'runtime',
            default => 'unexpected',
        };
    }
}
