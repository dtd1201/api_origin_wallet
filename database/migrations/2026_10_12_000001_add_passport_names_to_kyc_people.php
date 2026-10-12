<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_profiles', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('legal_name');
            $table->string('last_name')->nullable()->after('first_name');
        });

        Schema::table('kyc_related_persons', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('legal_name');
            $table->string('last_name')->nullable()->after('first_name');
        });

        Schema::table('kyc_company_directors', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('legal_name');
            $table->string('last_name')->nullable()->after('first_name');
        });
    }

    public function down(): void
    {
        Schema::table('kyc_company_directors', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name']);
        });

        Schema::table('kyc_related_persons', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name']);
        });

        Schema::table('kyc_profiles', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name']);
        });
    }
};
