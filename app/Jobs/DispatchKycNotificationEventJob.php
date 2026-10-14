<?php

namespace App\Jobs;

use App\Mail\KycStatusNotificationMail;
use App\Models\KycNotificationDelivery;
use App\Models\KycNotificationEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class DispatchKycNotificationEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [60, 300, 900, 1800];

    public function __construct(public readonly int $eventId)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $event = KycNotificationEvent::query()->find($this->eventId);
        if ($event === null || $event->completed_at !== null) {
            return;
        }

        $hadFailure = false;
        $maxAttempts = max(1, (int) config('services.kyc_notifications.max_attempts', 5));
        $ids = $event->deliveries()->whereIn('status', ['pending', 'failed'])->where('attempts', '<', $maxAttempts)->pluck('id');

        foreach ($ids as $deliveryId) {
            $delivery = $this->claim((int) $deliveryId, $maxAttempts);
            if ($delivery === null) {
                continue;
            }

            try {
                Mail::to($delivery->email)->send(new KycStatusNotificationMail(
                    $delivery->recipient_type,
                    $event->event_type,
                    (array) $event->payload,
                ));
                $delivery->update(['status' => 'delivered', 'delivered_at' => now(), 'last_error' => null]);
            } catch (Throwable $exception) {
                $hadFailure = true;
                $delivery->update([
                    'status' => 'failed',
                    'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                ]);
                Log::warning('KYC notification delivery failed.', [
                    'event_id' => $event->id,
                    'delivery_id' => $delivery->id,
                    'attempts' => $delivery->attempts,
                    'exception' => $exception::class,
                ]);
            }
        }

        $remaining = $event->deliveries()->where(function ($query) use ($maxAttempts): void {
            $query->whereIn('status', ['pending', 'sending'])
                ->orWhere(fn ($failed) => $failed->where('status', 'failed')->where('attempts', '<', $maxAttempts));
        })->exists();

        if (! $remaining) {
            $event->update(['completed_at' => now()]);
        }

        if ($hadFailure && $remaining) {
            throw new \RuntimeException("KYC notification event {$event->id} has retryable recipient failures.");
        }
    }

    private function claim(int $deliveryId, int $maxAttempts): ?KycNotificationDelivery
    {
        return DB::transaction(function () use ($deliveryId, $maxAttempts): ?KycNotificationDelivery {
            $delivery = KycNotificationDelivery::query()->lockForUpdate()->find($deliveryId);
            if ($delivery === null || ! in_array($delivery->status, ['pending', 'failed'], true) || $delivery->attempts >= $maxAttempts) {
                return null;
            }
            $delivery->update([
                'status' => 'sending',
                'attempts' => $delivery->attempts + 1,
                'last_attempted_at' => now(),
            ]);

            return $delivery->fresh();
        }, 3);
    }
}
