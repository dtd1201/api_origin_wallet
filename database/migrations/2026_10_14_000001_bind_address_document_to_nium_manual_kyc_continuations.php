<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nium_manual_kyc_continuations', function (Blueprint $table): void {
            $table->foreignId('proof_of_address_document_id')->nullable()->after('kyc_document_id')
                ->constrained('kyc_documents')->restrictOnDelete();
            $table->char('proof_of_address_file_id_fingerprint', 64)->nullable()->after('file_id_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('nium_manual_kyc_continuations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('proof_of_address_document_id');
            $table->dropColumn('proof_of_address_file_id_fingerprint');
        });
    }
};
