<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_request_logs', function (Blueprint $table): void {
            $table->string('operation', 100)->nullable()->after('related_transfer_id');
            $table->string('client_hash_id', 255)->nullable()->after('operation');
            $table->string('external_reference', 255)->nullable()->after('client_hash_id');
            $table->text('endpoint_path')->nullable()->after('request_url');
            $table->timestamp('request_started_at')->nullable()->after('response_body');
            $table->timestamp('request_finished_at')->nullable()->after('request_started_at');
            $table->string('content_type', 120)->nullable()->after('request_finished_at');
            $table->string('transport_outcome', 40)->nullable()->after('content_type');
        });
    }

    public function down(): void
    {
        Schema::table('api_request_logs', function (Blueprint $table): void {
            $table->dropColumn([
                'operation',
                'client_hash_id',
                'external_reference',
                'endpoint_path',
                'request_started_at',
                'request_finished_at',
                'content_type',
                'transport_outcome',
            ]);
        });
    }
};
