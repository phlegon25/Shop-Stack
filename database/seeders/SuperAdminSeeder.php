<?php

namespace Database\Seeders;

use App\Models\SuperAdmin;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = trim((string) env('SUPER_ADMIN_EMAIL')) ?: 'admin@platform.local';

        // The initial password comes from the environment — never hardcoded
        // for real deployments (see .env.example).
        $password = (string) env('SUPER_ADMIN_PASSWORD') ?: 'ChangeMe-Platform!2026';

        SuperAdmin::updateOrCreate(
            ['email' => $email],
            [
                'name'        => 'Platform Admin',
                'password'    => $password,
                'permissions' => config('pos.super_admin_permissions'),
                'is_active'   => true,
            ],
        );

        $this->command?->warn("Super admin seeded: {$email} — 2FA enrollment is mandatory on first login.");
    }
}
