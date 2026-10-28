<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';
        if (! $pgsql && DB::getDriverName() !== 'sqlite') {
            throw new RuntimeException('Nium legacy VA evidence requires PostgreSQL or SQLite.');
        }
        Schema::table('nium_virtual_account_operations', function (Blueprint $table): void {
            $table->text('legacy_target_snapshot')->nullable();
            $table->uuid('legacy_provider_request_id')->nullable()->unique('nium_legacy_va_request_id_unique');
        });
        // No historical backfill: today's mapping is not evidence of the original target.
        $different = fn (string $column): string => $pgsql
            ? "NEW.{$column} IS DISTINCT FROM OLD.{$column}" : "NEW.{$column} IS NOT OLD.{$column}";
        $immutable = implode(' OR ', array_map($different, [
            'legacy_target_snapshot', 'legacy_provider_request_id', 'id', 'user_provider_account_id',
            'operation_id', 'currency', 'account_category', 'account_type', 'bank_name', 'claimed_at',
        ]));
        $this->guard('nium_legacy_va_target_pair', 'nium_virtual_account_operations', 'INSERT',
            "((NEW.legacy_target_snapshot IS NULL) <> (NEW.legacy_provider_request_id IS NULL))
            OR (NEW.legacy_target_snapshot IS NOT NULL AND (NEW.submitted_at IS NOT NULL OR NEW.status <> 'claimed'))", $pgsql);
        $this->guard('nium_legacy_va_target_update', 'nium_virtual_account_operations', 'UPDATE',
            "((NEW.legacy_target_snapshot IS NULL) <> (NEW.legacy_provider_request_id IS NULL))
            OR (OLD.legacy_target_snapshot IS NOT NULL AND ({$immutable}))
            OR (OLD.legacy_target_snapshot IS NULL AND NEW.legacy_target_snapshot IS NOT NULL
                AND (OLD.submitted_at IS NOT NULL OR OLD.status NOT IN ('claimed', 'preflight_failed')))
            OR (OLD.legacy_target_snapshot IS NOT NULL AND OLD.submitted_at IS NOT NULL AND (".$different('submitted_at').'))
            OR (OLD.api_request_log_id IS NOT NULL AND ('.$different('api_request_log_id').')
                AND NOT EXISTS (SELECT 1 FROM nium_va_requests r WHERE r.operation_id = OLD.id))', $pgsql);
        $this->guard('nium_legacy_va_target_delete', 'nium_virtual_account_operations', 'DELETE',
            'OLD.legacy_target_snapshot IS NOT NULL', $pgsql);

        // Seal exchanges belonging to legacy operations. Enrolled evidence retains its
        // existing ledger lifecycle; unrelated NIUM exchanges are unaffected.
        $requestId = $pgsql ? "l.request_headers->>'x-request-id'" : "json_extract(l.request_headers, '$.\"x-request-id\"')";
        $storedRequestId = $pgsql ? 'o.legacy_provider_request_id::text' : 'o.legacy_provider_request_id';
        $legacyLog = "l.operation = 'assign_payment_id' AND EXISTS (
            SELECT 1 FROM nium_virtual_account_operations o
            WHERE NOT EXISTS (SELECT 1 FROM nium_va_requests r WHERE r.operation_id = o.id)
            AND (o.api_request_log_id = l.id OR {$storedRequestId} = {$requestId}))";
        foreach (['UPDATE', 'DELETE'] as $event) {
            $this->guard('nium_va_post_log_'.strtolower($event), 'api_request_logs', $event,
                "EXISTS (SELECT 1 FROM api_request_logs l WHERE l.id = OLD.id AND ({$legacyLog}))", $pgsql);
            $this->guard('nium_va_post_exchange_'.strtolower($event), 'nium_api_exchange_evidence', $event,
                "EXISTS (SELECT 1 FROM api_request_logs l WHERE l.id = OLD.api_request_log_id AND ({$legacyLog}))", $pgsql);
        }
    }

    private function guard(string $name, string $table, string $event, string $condition, bool $pgsql): void
    {
        if ($pgsql) {
            $record = $event === 'DELETE' ? 'OLD' : 'NEW';
            DB::unprepared("CREATE FUNCTION {$name}() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN IF {$condition} THEN RAISE EXCEPTION 'Nium VA immutable evidence: {$name}' USING ERRCODE = '23514';
                END IF; RETURN {$record}; END; $$;
                CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW EXECUTE FUNCTION {$name}();");
        } else {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} WHEN {$condition}
                BEGIN SELECT RAISE(ABORT, 'Nium VA immutable evidence: {$name}'); END;");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Nium legacy VA evidence requires forward-only recovery.');
    }
};
