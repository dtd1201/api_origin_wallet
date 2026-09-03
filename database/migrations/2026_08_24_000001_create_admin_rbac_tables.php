<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('name', 150);
            $table->timestamps();
        });
        Schema::create('permission_role', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['role_id', 'permission_id']);
        });
        Schema::table('user_roles', function (Blueprint $table): void {
            $table->foreignId('role_id')->nullable()->after('user_id')->constrained('roles')->restrictOnDelete();
        });

        $now = now();
        $permissions = (array) config('rbac.permissions', []);
        foreach (array_keys((array) config('rbac.roles', [])) as $code) {
            DB::table('roles')->insert(['code' => $code, 'name' => Str::headline($code), 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ($permissions as $code) {
            DB::table('permissions')->insert(['code' => $code, 'name' => Str::headline(str_replace('.', ' ', $code)), 'created_at' => $now, 'updated_at' => $now]);
        }

        $roleIds = DB::table('roles')->pluck('id', 'code');
        $permissionIds = DB::table('permissions')->pluck('id', 'code');
        foreach ((array) config('rbac.roles', []) as $roleCode => $rolePermissions) {
            foreach ($rolePermissions === ['*'] ? $permissions : $rolePermissions as $permissionCode) {
                DB::table('permission_role')->insert(['role_id' => $roleIds[$roleCode], 'permission_id' => $permissionIds[$permissionCode], 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        $duplicateLegacyAdminIds = DB::table('user_roles as legacy')
            ->join('user_roles as current', 'current.user_id', '=', 'legacy.user_id')
            ->whereRaw('LOWER(legacy.role_code) = ?', ['admin'])
            ->whereRaw('LOWER(current.role_code) = ?', ['super_admin'])
            ->pluck('legacy.id');
        DB::table('user_roles')->whereIn('id', $duplicateLegacyAdminIds)->delete();

        DB::table('user_roles')->whereRaw('LOWER(role_code) IN (?, ?)', ['admin', 'super_admin'])
            ->update(['role_code' => 'super_admin', 'role_id' => $roleIds['super_admin']]);
        foreach ($roleIds as $code => $id) {
            DB::table('user_roles')->where('role_code', $code)->update(['role_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('user_roles', fn (Blueprint $table) => $table->dropConstrainedForeignId('role_id'));
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
