<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nium_dynamic_intents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('provider_id')->constrained('integration_providers')->restrictOnDelete();
            $table->foreignId('provider_account_id')->constrained('user_provider_accounts')->restrictOnDelete();
            $table->foreignId('beneficiary_id')->constrained('beneficiaries')->restrictOnDelete();
            $table->foreignId('balance_id')->constrained('balances')->restrictOnDelete();
            $table->string('client_reference', 100);
            $table->char('reference_sha256', 64);
            $table->char('request_fingerprint', 64);
            $table->char('terms_fingerprint', 64);
            $table->char('quote_consumption_key', 64)->unique();
            $table->string('quote_id');
            $table->timestampTz('quote_expires_at', 6);
            $table->string('customer');
            $table->string('source_wallet');
            $table->string('amount_mode', 30);
            $table->char('source_currency', 3);
            $table->char('destination_currency', 3);
            $table->decimal('total_debit', 20, 8);
            $table->foreignId('consent_actor_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('consented_at', 6);
            // The existing AUTO-02A snapshot includes ALL fees, amounts and versions.
            // PostgreSQL json preserves AUTO-02A's fingerprint serialization order.
            $table->json('confirmed_terms');
            $table->jsonb('consent_binding');
            $table->jsonb('admission_evidence');
            $table->timestampTz('created_at', 6);
            $table->unique(['user_id', 'reference_sha256'], 'nium_dynamic_intents_owner_reference_unique');
        });

        Schema::create('nium_dynamic_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('intent_id')->unique()->constrained('nium_dynamic_intents')->restrictOnDelete();
            $table->foreignId('balance_id')->constrained('balances')->restrictOnDelete();
            $table->char('currency', 3);
            $table->decimal('amount', 20, 8);
            $table->string('state', 30)->default('held');
            $table->foreignId('hold_entry_id')->unique()->constrained('ledger_entries')->restrictOnDelete();
            $table->foreignId('release_entry_id')->nullable()->unique()->constrained('ledger_entries')->restrictOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('released_at', 6)->nullable();
            $table->timestampTz('created_at', 6);
        });

        Schema::create('nium_dynamic_payout_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('intent_id')->unique()->constrained('nium_dynamic_intents')->restrictOnDelete();
            $table->foreignUuid('reservation_id')->unique()->constrained('nium_dynamic_reservations')->restrictOnDelete();
            $table->char('terms_fingerprint', 64);
            $table->string('state', 30)->default('held');
            $table->boolean('dispatchable')->default(false);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('claimed_at', 6)->nullable();
            $table->timestampTz('submission_started_at', 6)->nullable();
            $table->string('provider_reference')->nullable();
            $table->timestampTz('cancelled_at', 6)->nullable();
            $table->timestampTz('created_at', 6);
        });

        // This lane supports PostgreSQL only. Other existing test databases can
        // still migrate the additive tables without activating durable confirmation.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE nium_dynamic_intents ADD CONSTRAINT nium_dynamic_intent_debit_positive CHECK (total_debit > 0)');
            DB::statement("ALTER TABLE nium_dynamic_reservations ADD CONSTRAINT nium_dynamic_reservation_state CHECK (amount > 0 AND state IN ('held', 'released'))");
            DB::statement("ALTER TABLE nium_dynamic_payout_outbox ADD CONSTRAINT nium_dynamic_outbox_never_dispatchable CHECK (dispatchable = false AND state IN ('held', 'cancelled', 'ambiguous'))");
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION nium_dynamic_intent_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Confirmed dynamic intents are immutable';
END;
$$;
CREATE TRIGGER nium_dynamic_intent_immutable
BEFORE UPDATE OR DELETE ON nium_dynamic_intents
FOR EACH ROW EXECUTE FUNCTION nium_dynamic_intent_immutable();
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nium_dynamic_payout_outbox');
        Schema::dropIfExists('nium_dynamic_reservations');
        Schema::dropIfExists('nium_dynamic_intents');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS nium_dynamic_intent_immutable()');
        }
    }
};
