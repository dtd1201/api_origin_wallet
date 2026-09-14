<?php

namespace App\Jobs\Nium;

use App\Models\WebhookEvent;
use App\Services\Nium\NiumHkSubmitKycService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SubmitNiumHkEntityKycJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 86400;

    public function __construct(public readonly int $webhookEventId) {}

    public function uniqueId(): string
    {
        return (string) $this->webhookEventId;
    }

    public function handle(NiumHkSubmitKycService $service): void
    {
        $event = WebhookEvent::query()->findOrFail($this->webhookEventId);
        if ($event->event_type === 'CUSTOMER_STATUS_WEBHOOK') {
            $service->submitAwaitingKyc($event);
            return;
        }
        $service->submit($event);
    }
}
