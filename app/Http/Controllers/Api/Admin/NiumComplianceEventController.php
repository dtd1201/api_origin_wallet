<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\NiumComplianceEvent;
use App\Support\NiumOperationalData;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class NiumComplianceEventController extends Controller
{
    public function __construct(
        private readonly SensitiveDataSanitizer $sensitiveDataSanitizer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'review_status' => ['nullable', 'string', Rule::in(['pending', 'resolved', 'ignored', 'not_required'])],
            'match_status' => ['nullable', 'string', Rule::in(['unmatched', 'matched_customer', 'matched_transfer', 'matched_transaction'])],
            'requires_action' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $events = NiumComplianceEvent::query()
            ->with(['provider', 'user', 'transfer', 'transaction', 'reviewer'])
            ->when(isset($validated['review_status']), fn ($query) => $query->where('review_status', $validated['review_status']))
            ->when(isset($validated['match_status']), fn ($query) => $query->where('match_status', $validated['match_status']))
            ->when(isset($validated['requires_action']), fn ($query) => $query->where('requires_action', $validated['requires_action']))
            ->when(filled($validated['search'] ?? null), function ($query) use ($validated): void {
                $search = (string) $validated['search'];

                $query->where(function ($query) use ($search): void {
                    $query->where('event_id', 'like', "%{$search}%")
                        ->orWhere('request_id', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%")
                        ->orWhere('customer_reference', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate((int) $request->integer('per_page', 15));

        return response()->json($events->through(fn (NiumComplianceEvent $event) => $this->payload($event)));
    }

    public function show(NiumComplianceEvent $niumComplianceEvent): JsonResponse
    {
        return response()->json($this->payload($niumComplianceEvent->load([
            'provider',
            'user',
            'transfer',
            'transaction',
            'reviewer',
        ])));
    }

    public function review(Request $request, NiumComplianceEvent $niumComplianceEvent): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['resolved', 'ignored'])],
            'resolution_note' => ['required', 'string', 'max:4000'],
        ]);

        $event = DB::transaction(function () use ($niumComplianceEvent, $request, $validated): NiumComplianceEvent {
            $event = NiumComplianceEvent::query()->lockForUpdate()->findOrFail($niumComplianceEvent->id);
            $oldData = $this->sensitiveDataSanitizer->sanitize($event->toArray());

            $event->update([
                'review_status' => $validated['status'],
                'reviewed_by' => $request->user()?->id,
                'reviewed_at' => now(),
                'resolution_note' => $validated['resolution_note'],
            ]);

            $this->clearRelatedReviewFlagIfComplete($event);

            AuditLog::query()->create([
                'user_id' => $request->user()?->id,
                'action' => 'nium_compliance_event.reviewed',
                'entity_type' => 'nium_compliance_event',
                'entity_id' => (string) $event->id,
                'old_data' => $oldData,
                'new_data' => $this->sensitiveDataSanitizer->sanitize($event->fresh()->toArray()),
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            ]);

            return $event->fresh(['provider', 'user', 'transfer', 'transaction', 'reviewer']);
        });

        return response()->json([
            'message' => 'Nium compliance event review completed.',
            'event' => $this->payload($event),
        ]);
    }

    private function clearRelatedReviewFlagIfComplete(NiumComplianceEvent $event): void
    {
        if ($event->transaction_id !== null) {
            $hasPending = NiumComplianceEvent::query()
                ->where('transaction_id', $event->transaction_id)
                ->where('id', '!=', $event->id)
                ->where('review_status', 'pending')
                ->exists();

            if (! $hasPending) {
                $event->transaction?->update([
                    'compliance_review_required' => false,
                    'compliance_reviewed_at' => now(),
                ]);
            }
        }

        if ($event->transfer_id !== null) {
            $hasPending = NiumComplianceEvent::query()
                ->where('transfer_id', $event->transfer_id)
                ->where('id', '!=', $event->id)
                ->where('review_status', 'pending')
                ->exists();

            if (! $hasPending) {
                $event->transfer?->update([
                    'compliance_review_required' => false,
                    'compliance_reviewed_at' => now(),
                ]);
            }
        }
    }

    private function payload(NiumComplianceEvent $event): array
    {
        return [
            'id' => $event->id,
            'event_id' => $event->event_id,
            'request_id' => $event->request_id,
            'reference' => $event->reference,
            'customer_reference' => $event->customer_reference,
            'event_type' => $event->event_type,
            'compliance_status' => $event->compliance_status,
            'match_status' => $event->match_status,
            'review_status' => $event->review_status,
            'requires_action' => $event->requires_action,
            'processing_status' => $event->processing_status,
            'duplicate_count' => $event->duplicate_count,
            'provider' => $event->provider?->summaryPayload(),
            'user_id' => $event->user_id,
            'transfer_id' => $event->transfer_id,
            'transaction_id' => $event->transaction_id,
            'received_at' => $event->received_at,
            'last_received_at' => $event->last_received_at,
            'processed_at' => $event->processed_at,
            'reviewed_at' => $event->reviewed_at,
            'reviewed_by' => $event->reviewed_by,
            'resolution_note' => $event->resolution_note,
            'error_message' => $event->error_message,
            'payload' => NiumOperationalData::project($event->payload),
        ];
    }
}
