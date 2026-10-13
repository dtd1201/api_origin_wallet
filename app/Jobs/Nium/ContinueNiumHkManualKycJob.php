<?php

namespace App\Jobs\Nium;

use App\Services\Nium\NiumHkManualSubmitKycService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class ContinueNiumHkManualKycJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const MAX_RUNTIME_SECONDS = 60;

    public int $tries = 1;

    public int $timeout = self::MAX_RUNTIME_SECONDS;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $continuationId) {}

    public function handle(NiumHkManualSubmitKycService $service): void
    {
        $delay = $service->continueManualKyc($this->continuationId);

        if ($delay !== null) {
            self::dispatch($this->continuationId)->delay(now()->addSeconds($delay))->afterCommit();
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(NiumHkManualSubmitKycService::class)->holdFailedContinuation(
            $this->continuationId,
            'job_failed',
            $exception?->getMessage() ?? 'The continuation job failed without an exception message.',
        );
    }
}
