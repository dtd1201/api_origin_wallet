<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table): void {
            if (! Schema::hasColumn('transfers', 'provider_status')) {
                $table->string('provider_status', 60)->nullable();
            }

            if (! Schema::hasColumn('transfers', 'provider_status_detail')) {
                $table->text('provider_status_detail')->nullable();
            }

            if (! Schema::hasColumn('transfers', 'provider_status_at')) {
                $table->timestamp('provider_status_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table): void {
            $columns = [];

            foreach ([
                'provider_status',
                'provider_status_detail',
                'provider_status_at',
            ] as $column) {
                if (Schema::hasColumn('transfers', $column)) {
                    $columns[] = $column;
                }
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
