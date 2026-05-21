<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_documents', function (Blueprint $table): void {
            $table->string('storage_disk', 50)->nullable()->after('file_url');
            $table->string('file_path', 2048)->nullable()->after('storage_disk');
            $table->string('original_name')->nullable()->after('file_path');
            $table->string('mime_type', 100)->nullable()->after('original_name');
            $table->unsignedBigInteger('file_size')->nullable()->after('mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('kyc_documents', function (Blueprint $table): void {
            $table->dropColumn([
                'storage_disk',
                'file_path',
                'original_name',
                'mime_type',
                'file_size',
            ]);
        });
    }
};
