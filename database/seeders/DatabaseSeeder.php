<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PlanSeeder::class,
            SuperAdminSeeder::class,
            PlatformSettingSeeder::class,
            DemoTenantSeeder::class, // local env only (self-guarded)
            JerryProvisionStoreSeeder::class, // flagship demo tenant (local only)
        ]);
    }
}
