<?php

namespace App\Console\Commands;

use App\Jobs\DispatchKycNotificationEventJob;
use App\Models\KycNotificationEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class DispatchPendingKycNotifications extends Command
{
    protected $signature = 'kyc:dispatch-notifications {--limit=100}';

    protected $description = 'Dispatch durable KYC notification outbox events that have not reached the database queue';

    public function handle(): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $eventIds = KycNotificationEvent::query()
            ->whereNull('dispatched_at')
            ->whereNull('completed_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $dispatched = 0;
        foreach ($eventIds as $eventId) {
            $didDispatch = DB::transaction(function () use ($eventId): bool {
                $event = KycNotificationEvent::query()->lockForUpdate()->find($eventId);
                if ($event === null || $event->dispatched_at !== null || $event->completed_at !== null) {
                    return false;
                }

                DispatchKycNotificationEventJob::dispatch($event->id);
                $event->update(['dispatched_at' => now()]);

                return true;
            }, 3);
            $dispatched += $didDispatch ? 1 : 0;
        }

        $this->info("Dispatched {$dispatched} KYC notification outbox events.");

        return self::SUCCESS;
    }
}
