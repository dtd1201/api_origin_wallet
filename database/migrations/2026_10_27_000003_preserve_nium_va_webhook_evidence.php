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
            throw new RuntimeException('Nium VA evidence requires PostgreSQL or SQLite.');
        }
        Schema::table('webhook_events', function (Blueprint $table): void {
            $table->text('nium_va_evidence')->nullable(); // Encrypted, hidden, authenticated raw receipt; no secrets.
        });
        $immutable = [];
        foreach (['id', 'provider_id', 'event_id', 'event_type', 'payload', 'received_at', 'nium_va_evidence'] as $column) {
            $immutable[] = $pgsql
                ? "NEW.{$column} IS DISTINCT FROM OLD.{$column}"
                : "NEW.{$column} IS NOT OLD.{$column}";
        }
        $update = 'OLD.nium_va_evidence IS NOT NULL AND ('.implode(' OR ', $immutable).')';
        $delete = 'OLD.nium_va_evidence IS NOT NULL';
        foreach (['update' => $update, 'delete' => $delete] as $action => $condition) {
            $name = 'nium_va_webhook_evidence_'.$action.'_guard';
            $event = strtoupper($action);
            if ($pgsql) {
                DB::unprepared("CREATE FUNCTION {$name}() RETURNS trigger LANGUAGE plpgsql AS $$
                    BEGIN IF {$condition} THEN
                        RAISE EXCEPTION 'Nium VA webhook evidence is immutable' USING ERRCODE = '23514';
                    END IF; RETURN ".($action === 'delete' ? 'OLD' : 'NEW')."; END; $$;
                    CREATE TRIGGER {$name} BEFORE {$event} ON webhook_events FOR EACH ROW EXECUTE FUNCTION {$name}();");
            } else {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON webhook_events
                    WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Nium VA webhook evidence is immutable'); END;");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Authenticated VA webhook evidence requires forward-only recovery.');
    }
};
