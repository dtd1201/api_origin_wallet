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
            throw new RuntimeException('Nium VA single dispatch requires PostgreSQL or SQLite.');
        }
        Schema::table('nium_api_exchange_evidence', function (Blueprint $table): void {
            // Exact transport target stays encrypted and hidden; public request URLs remain redacted.
            $table->text('assignment_target')->nullable();
        });
        $different = fn (string $column): string => $pgsql
            ? ($column === 'baseline_payment_ids' ? "NEW.{$column}::text IS DISTINCT FROM OLD.{$column}::text" : "NEW.{$column} IS DISTINCT FROM OLD.{$column}")
            : "NEW.{$column} IS NOT OLD.{$column}";
        $immutable = implode(' OR ', array_map($different, [
            'id', 'user_provider_account_id', 'operation_id', 'currency', 'account_category', 'account_type', 'bank_name',
            'baseline_payment_ids', 'baseline_complete', 'claimed_at', 'created_at',
        ]));
        $json = fn (string $key, string $type = 'text'): string => $pgsql
            ? "CAST(r.outcome->>'{$key}' AS {$type})" : "json_extract(r.outcome, '$.{$key}')";
        $distinct = $pgsql ? 'IS DISTINCT FROM' : 'IS NOT';
        $submitted = $pgsql ? 'CAST(a.consumed_at AS timestamp)' : 'a.consumed_at';
        $projection = [
            "NEW.status {$distinct} r.operation_state",
            "NEW.submitted_at {$distinct} (SELECT {$submitted} FROM nium_va_dispatch_attempts a WHERE a.operation_id = r.operation_id)",
            "NEW.api_request_log_id {$distinct} r.resolution_api_request_log_id",
        ];
        foreach (['provider_payment_id' => 'text', 'resolved_at' => 'timestamp', 'resolution_code' => 'text'] as $key => $type) {
            $projection[] = "NEW.{$key} {$distinct} ".$json($key, $type);
        }
        $projectionMismatch = 'EXISTS (SELECT 1 FROM nium_va_requests r WHERE r.operation_id = OLD.id AND ('.implode(' OR ', $projection).'))';
        $attachmentAllowed = "OLD.nium_virtual_account_id IS NULL AND NEW.nium_virtual_account_id IS NOT NULL
            AND EXISTS (SELECT 1 FROM nium_va_requests r
                JOIN nium_va_assignment_scopes s ON s.scope_key = r.scope_key
                JOIN nium_virtual_accounts v ON v.id = NEW.nium_virtual_account_id
                WHERE r.operation_id = OLD.id AND r.operation_state = 'assigned' AND NEW.status = 'assigned'
                AND r.resolution_api_request_log_id IS NOT NULL AND r.resolution_evidence_sha256 IS NOT NULL
                AND s.active_operation_id = OLD.id
                AND v.user_provider_account_id = NEW.user_provider_account_id
                AND v.provider_payment_id = ".$json('provider_payment_id')."
                AND v.currency = NEW.currency AND v.account_category = NEW.account_category
                AND v.account_type = NEW.account_type AND v.status = 'assigned'
                AND NOT EXISTS (SELECT 1 FROM nium_virtual_account_operations other
                    WHERE other.nium_virtual_account_id = v.id AND other.id <> OLD.id))";
        $condition = "EXISTS (SELECT 1 FROM nium_va_requests WHERE operation_id = OLD.id)
            AND (({$immutable}) OR ({$projectionMismatch}) OR ((".$different('nium_virtual_account_id').") AND NOT ({$attachmentAllowed})))";
        if ($pgsql) {
            DB::unprepared("CREATE OR REPLACE FUNCTION nium_va_operation_identity_guard() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN IF {$condition} THEN
                    RAISE EXCEPTION 'Nium VA safety ledger invariant: nium_va_operation_identity_guard' USING ERRCODE = '23514';
                END IF; RETURN NEW; END; $$;");
        } else {
            DB::unprepared('DROP TRIGGER nium_va_operation_identity_guard');
            DB::unprepared("CREATE TRIGGER nium_va_operation_identity_guard BEFORE UPDATE ON nium_virtual_account_operations
                WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Nium VA safety ledger invariant: nium_va_operation_identity_guard'); END;");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Nium VA single-dispatch safety history requires forward-only recovery.');
    }
};
