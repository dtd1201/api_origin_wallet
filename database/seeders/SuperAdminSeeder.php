<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = trim((string) env('SUPER_ADMIN_EMAIL', ''));
        $password = (string) env('SUPER_ADMIN_PASSWORD', '');
        $fullName = trim((string) env('SUPER_ADMIN_FULL_NAME', 'Super Admin'));

        if ($email === '' || $password === '') {
            $this->command?->warn('Skipping SuperAdminSeeder because SUPER_ADMIN_EMAIL or SUPER_ADMIN_PASSWORD is not set.');

            return;
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'full_name' => $fullName !== '' ? $fullName : null,
                'password_hash' => Hash::make($password),
                'status' => 'active',
                'kyc_status' => 'approved',
            ]
        );

        $user->roles()->updateOrCreate(
            ['role_code' => 'super_admin'],
            []
        );

        $this->command?->info("Super admin seeded for {$email}.");
    }
}
