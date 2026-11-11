<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Match the existing confirmed-intent protection. Model hooks alone do not
            // stop query-builder updates from rewriting an original provider quote.
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION nium_dynamic_quote_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Dynamic reference quotes are immutable';
END;
$$;
CREATE TRIGGER nium_dynamic_quote_immutable
BEFORE UPDATE OR DELETE ON nium_dynamic_payout_quotes
FOR EACH ROW EXECUTE FUNCTION nium_dynamic_quote_immutable();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS nium_dynamic_quote_immutable ON nium_dynamic_payout_quotes');
            DB::statement('DROP FUNCTION IF EXISTS nium_dynamic_quote_immutable()');
        }
    }
};
