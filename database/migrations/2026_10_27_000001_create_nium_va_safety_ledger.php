<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Nium VA safety ledger requires PostgreSQL or SQLite.');
        }

        Schema::create('nium_va_assignment_scopes', function (Blueprint $table): void {
            // Hash of environment, client, actual wallet and currency; no account-category uniqueness.
            $table->char('scope_key', 64)->primary();
            $table->foreignId('active_operation_id')->nullable()->unique('nium_va_scope_active_unique')
                ->constrained('nium_virtual_account_operations')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('nium_va_requests', function (Blueprint $table): void {
            $table->foreignId('operation_id')->primary()->constrained('nium_virtual_account_operations')->restrictOnDelete();
            $table->char('scope_key', 64)->index('nium_va_request_scope_idx');
            $table->foreign('scope_key')->references('scope_key')->on('nium_va_assignment_scopes')->restrictOnDelete();
            $table->text('snapshot'); // Encrypted target and canonical effective payload, without authentication headers.
            $table->char('payload_sha256', 64);
            $table->uuid('provider_request_id')->unique('nium_va_request_uuid_unique');
            $table->unsignedBigInteger('state_version')->default(0);
            // Authoritative transition projection; the database mirrors these fields onto enrolled operations.
            $table->string('operation_state', 30)->default('claimed');
            $table->json('outcome')->nullable();
            $table->char('resolution_evidence_sha256', 64)->nullable();
            // Existing operations.api_request_log_id remains the dispatch/response evidence reference.
            $table->foreignId('resolution_api_request_log_id')->nullable()->index('nium_va_request_log_idx')->constrained('api_request_logs')->restrictOnDelete();
            $table->foreignId('resolution_webhook_event_id')->nullable()->index('nium_va_request_webhook_idx')->constrained('webhook_events')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('nium_va_dispatch_attempts', function (Blueprint $table): void {
            $table->foreignId('operation_id')->primary()->constrained('nium_va_requests', 'operation_id')->restrictOnDelete();
            $table->timestampTz('consumed_at');
        });

        $different = fn (string $column): string => DB::getDriverName() === 'pgsql'
            ? ($column === 'baseline_payment_ids' ? "NEW.{$column}::text IS DISTINCT FROM OLD.{$column}::text" : "NEW.{$column} IS DISTINCT FROM OLD.{$column}")
            : "NEW.{$column} IS NOT OLD.{$column}";
        $immutableRequest = implode(' OR ', array_map($different, [
            'operation_id', 'scope_key', 'snapshot', 'payload_sha256', 'provider_request_id', 'created_at',
        ]));
        $immutableOperation = implode(' OR ', array_map($different, [
            'id', 'user_provider_account_id', 'operation_id', 'currency', 'account_category', 'account_type', 'bank_name',
            'nium_virtual_account_id', 'baseline_payment_ids', 'baseline_complete', 'claimed_at', 'created_at',
        ]));

        $this->guard('nium_va_request_insert_guard', 'nium_va_requests', 'INSERT', "
            NEW.state_version <> 0 OR NEW.operation_state <> 'claimed' OR NEW.outcome IS NOT NULL
            OR NEW.resolution_api_request_log_id IS NOT NULL OR NEW.resolution_webhook_event_id IS NOT NULL
            OR NEW.resolution_evidence_sha256 IS NOT NULL OR NOT EXISTS (
                SELECT 1 FROM nium_virtual_account_operations o WHERE o.id = NEW.operation_id
                AND o.status = 'claimed' AND o.submitted_at IS NULL AND o.resolved_at IS NULL
                AND o.provider_payment_id IS NULL AND o.nium_virtual_account_id IS NULL
                AND o.api_request_log_id IS NULL AND o.resolution_code IS NULL
                AND o.baseline_payment_ids IS NULL AND NOT o.baseline_complete
            )");
        $graph = "(OLD.operation_state = 'claimed' AND NEW.operation_state = 'submitting')
            OR (OLD.operation_state = 'submitting' AND NEW.operation_state IN ('pending','unknown','manual_review','assigned','failed'))
            OR (OLD.operation_state = 'pending' AND NEW.operation_state IN ('unknown','manual_review','assigned','failed'))
            OR (OLD.operation_state = 'unknown' AND NEW.operation_state IN ('manual_review','assigned','failed'))
            OR (OLD.operation_state = 'manual_review' AND NEW.operation_state IN ('assigned','failed'))";
        $this->guard('nium_va_request_update_guard', 'nium_va_requests', 'UPDATE', "
            ({$immutableRequest}) OR NEW.state_version <> OLD.state_version + 1 OR NOT ({$graph})
            OR NOT EXISTS (SELECT 1 FROM nium_va_assignment_scopes s
                WHERE s.scope_key = NEW.scope_key AND s.active_operation_id = NEW.operation_id)
            OR NOT EXISTS (SELECT 1 FROM nium_va_dispatch_attempts a WHERE a.operation_id = NEW.operation_id)
            OR (NEW.operation_state NOT IN ('assigned','failed') AND (
                NEW.outcome IS NOT NULL OR NEW.resolution_api_request_log_id IS NOT NULL
                OR NEW.resolution_webhook_event_id IS NOT NULL OR NEW.resolution_evidence_sha256 IS NOT NULL))
            OR (NEW.operation_state IN ('assigned','failed') AND (
                NEW.outcome IS NULL OR NEW.resolution_evidence_sha256 IS NULL
                OR (NEW.resolution_api_request_log_id IS NULL AND NEW.resolution_webhook_event_id IS NULL)
                OR ".$this->outcomeInvalid().'))');
        $this->guard('nium_va_request_delete_guard', 'nium_va_requests', 'DELETE', 'TRUE');
        $this->guard('nium_va_attempt_update_guard', 'nium_va_dispatch_attempts', 'UPDATE', 'TRUE');
        $this->guard('nium_va_attempt_delete_guard', 'nium_va_dispatch_attempts', 'DELETE', 'TRUE');
        $this->guard('nium_va_attempt_insert_guard', 'nium_va_dispatch_attempts', 'INSERT', "NOT EXISTS (
            SELECT 1 FROM nium_va_requests r
            JOIN nium_virtual_account_operations o ON o.id = r.operation_id
            JOIN nium_va_assignment_scopes s ON s.scope_key = r.scope_key
            WHERE r.operation_id = NEW.operation_id AND o.status = 'claimed' AND r.operation_state = 'claimed'
            AND s.active_operation_id = o.id AND r.state_version = 0
            AND r.resolution_api_request_log_id IS NULL AND r.resolution_webhook_event_id IS NULL
        )");
        $projection = $this->projectionMismatch();
        $this->guard('nium_va_operation_identity_guard', 'nium_virtual_account_operations', 'UPDATE', "
            EXISTS (SELECT 1 FROM nium_va_requests WHERE operation_id = OLD.id)
            AND (({$immutableOperation}) OR ({$projection}))");
        $this->installProjectionMirror();
        foreach (['api_request_logs', 'nium_api_exchange_evidence', 'webhook_events'] as $table) {
            $condition = match ($table) {
                'api_request_logs' => 'EXISTS (SELECT 1 FROM nium_va_requests WHERE resolution_api_request_log_id = OLD.id)',
                'nium_api_exchange_evidence' => 'EXISTS (SELECT 1 FROM nium_va_requests WHERE resolution_api_request_log_id = OLD.api_request_log_id)',
                default => 'EXISTS (SELECT 1 FROM nium_va_requests WHERE resolution_webhook_event_id = OLD.id)',
            };
            foreach (['UPDATE', 'DELETE'] as $event) {
                $this->guard('nium_va_evidence_'.$table.'_'.strtolower($event), $table, $event, $condition);
            }
        }
        $this->guard('nium_va_scope_delete_guard', 'nium_va_assignment_scopes', 'DELETE', 'TRUE');
        $this->guard('nium_va_scope_insert_guard', 'nium_va_assignment_scopes', 'INSERT', 'NEW.active_operation_id IS NOT NULL');
        $this->guard('nium_va_scope_update_guard', 'nium_va_assignment_scopes', 'UPDATE', '('.$different('scope_key').') OR (('.$different('active_operation_id').") AND (
            (OLD.active_operation_id IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM nium_va_requests r
                JOIN nium_virtual_account_operations o ON o.id = r.operation_id
                WHERE o.id = OLD.active_operation_id AND o.status IN ('assigned', 'failed')
                AND o.resolved_at IS NOT NULL
                AND (r.resolution_api_request_log_id IS NOT NULL OR r.resolution_webhook_event_id IS NOT NULL)
            )) OR (NEW.active_operation_id IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM nium_va_requests r
                JOIN nium_virtual_account_operations o ON o.id = r.operation_id
                WHERE o.id = NEW.active_operation_id AND r.scope_key = NEW.scope_key
                AND o.status IN ('claimed', 'submitting', 'pending', 'unknown', 'manual_review')
            ))
        ))");
    }

    public function down(): void
    {
        // This safety migration is intentionally forward-only, even when currently empty.
        // An emptiness check alone races a concurrent first authorization and is not sufficient.
        throw new RuntimeException('Nium VA ledger rollback is forbidden; preserve safety history and use forward-only recovery.');
    }

    private function jsonValue(string $row, string $key, string $type = 'text'): string
    {
        return DB::getDriverName() === 'pgsql'
            ? "CAST({$row}.outcome->>'{$key}' AS {$type})"
            : "json_extract({$row}.outcome, '$.{$key}')";
    }

    private function outcomeInvalid(): string
    {
        $payment = $this->jsonValue('NEW', 'provider_payment_id');
        $resolved = $this->jsonValue('NEW', 'resolved_at', 'timestamp');
        $code = $this->jsonValue('NEW', 'resolution_code');

        return "({$resolved} IS NULL OR {$code} IS NULL
            OR (NEW.operation_state = 'assigned' AND ({$payment} IS NULL OR {$payment} = '' OR upper({$payment}) = 'INITIALIZED'))
            OR (NEW.operation_state = 'failed' AND {$payment} IS NOT NULL))";
    }

    private function projectionMismatch(): string
    {
        $different = DB::getDriverName() === 'pgsql' ? 'IS DISTINCT FROM' : 'IS NOT';
        $submitted = DB::getDriverName() === 'pgsql' ? 'CAST(a.consumed_at AS timestamp)' : 'a.consumed_at';
        $comparisons = [
            "NEW.status {$different} r.operation_state",
            "NEW.submitted_at {$different} (SELECT {$submitted} FROM nium_va_dispatch_attempts a WHERE a.operation_id = r.operation_id)",
            "NEW.api_request_log_id {$different} r.resolution_api_request_log_id",
        ];
        foreach (['provider_payment_id' => 'text', 'resolved_at' => 'timestamp', 'resolution_code' => 'text'] as $key => $type) {
            $comparisons[] = "NEW.{$key} {$different} ".$this->jsonValue('r', $key, $type);
        }

        return 'EXISTS (SELECT 1 FROM nium_va_requests r WHERE r.operation_id = OLD.id AND ('.implode(' OR ', $comparisons).'))';
    }

    private function installProjectionMirror(): void
    {
        $submitted = DB::getDriverName() === 'pgsql' ? 'CAST(consumed_at AS timestamp)' : 'consumed_at';
        $assignments = [
            'status = NEW.operation_state',
            "submitted_at = (SELECT {$submitted} FROM nium_va_dispatch_attempts WHERE operation_id = NEW.operation_id)",
            'api_request_log_id = NEW.resolution_api_request_log_id',
            'updated_at = NEW.updated_at',
        ];
        foreach (['provider_payment_id' => 'text', 'resolved_at' => 'timestamp', 'resolution_code' => 'text'] as $key => $type) {
            $assignments[] = $key.' = '.$this->jsonValue('NEW', $key, $type);
        }
        $sql = 'UPDATE nium_virtual_account_operations SET '.implode(', ', $assignments).' WHERE id = NEW.operation_id;';
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION nium_va_projection_mirror() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN {$sql} RETURN NEW; END; $$;
                CREATE TRIGGER nium_va_projection_mirror AFTER UPDATE ON nium_va_requests
                FOR EACH ROW EXECUTE FUNCTION nium_va_projection_mirror();");
        } else {
            DB::unprepared("CREATE TRIGGER nium_va_projection_mirror AFTER UPDATE ON nium_va_requests BEGIN {$sql} END;");
        }
    }

    private function guard(string $name, string $table, string $event, string $condition): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $returned = $event === 'DELETE' ? 'OLD' : 'NEW';
            DB::unprepared("CREATE FUNCTION {$name}() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                    IF {$condition} THEN
                        RAISE EXCEPTION 'Nium VA safety ledger invariant: {$name}' USING ERRCODE = '23514';
                    END IF;
                    RETURN {$returned};
                END;
                \$\$;
                CREATE TRIGGER {$name} BEFORE {$event} ON {$table}
                FOR EACH ROW EXECUTE FUNCTION {$name}();");
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table}
                WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Nium VA safety ledger invariant: {$name}'); END;");
        } else {
            throw new RuntimeException('Nium VA safety ledger requires PostgreSQL or SQLite.');
        }
    }
};
