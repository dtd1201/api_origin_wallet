<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity_type' => ['nullable', 'string', 'max:100'],
            'actor_id' => ['nullable', 'integer', 'exists:users,id'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $logs = AuditLog::query()
            ->with('user')
            ->when(
                filled($validated['entity_type'] ?? null) && $validated['entity_type'] !== 'all',
                fn ($query) => $query->where('entity_type', $validated['entity_type'])
            )
            ->when(
                filled($validated['actor_id'] ?? null),
                fn ($query) => $query->where('user_id', $validated['actor_id'])
            )
            ->when(filled($validated['search'] ?? null), function ($query) use ($validated): void {
                $search = (string) $validated['search'];

                $query->where(function ($query) use ($search): void {
                    $query->where('action', 'like', "%{$search}%")
                        ->orWhere('entity_type', 'like', "%{$search}%")
                        ->orWhere('entity_id', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($query) use ($search): void {
                            $query->where('email', 'like', "%{$search}%")
                                ->orWhere('full_name', 'like', "%{$search}%");
                        });
                });
            })
            ->latest('id')
            ->paginate((int) $request->integer('per_page', 15));

        return response()->json($logs->through(fn (AuditLog $log) => $this->payload($log)));
    }

    public function show(AuditLog $auditLog): JsonResponse
    {
        return response()->json($this->payload($auditLog->load('user')));
    }

    private function payload(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'actor_id' => $log->user_id,
            'actor' => $log->user ? [
                'id' => $log->user->id,
                'email' => $log->user->email,
                'full_name' => $log->user->full_name,
                'status' => $log->user->status,
                'kyc_status' => $log->user->kyc_status,
            ] : null,
            'actor_email' => $log->user?->email,
            'action' => $log->action,
            'entity_type' => $log->entity_type,
            'entity_id' => $log->entity_id,
            'summary' => $this->summary($log),
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
            'before' => $log->old_data,
            'after' => $log->new_data,
            'metadata' => null,
            'created_at' => $log->created_at,
        ];
    }

    private function summary(AuditLog $log): string
    {
        return trim("{$log->action} {$log->entity_type} {$log->entity_id}");
    }
}
