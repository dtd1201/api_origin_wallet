<?php

namespace App\Http\Controllers\Api\Admin\Concerns;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

trait RecordsAdminAudit
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function recordAdminAudit(
        Request $request,
        string $action,
        string $entityType,
        string|int|null $entityId,
        ?array $before = null,
        ?array $after = null,
    ): void {
        AuditLog::query()->create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => (string) $entityId,
            'old_data' => $before,
            'new_data' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ]);
    }
}
