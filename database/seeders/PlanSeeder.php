<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * The commercial catalogue. NOTHING about pricing is hardcoded in code or
 * the frontend — every surface reads plans from this table.
 * The hidden 'trial' plan powers free trials and never appears publicly.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Starter', 'slug' => 'starter',
                'description' => 'For single shops getting started.',
                'price' => 60, 'duration_days' => 30, 'trial_days' => 14,
                'product_limit' => 300, 'user_limit' => 5, 'storage_mb' => 200,
                'features' => [
                    'Unlimited sales & receipts', 'Up to 300 products', '5 user accounts',
                    'Customers & credit tracking', 'Expenses & profit reports', 'PDF & thermal receipts',
                ],
                'is_active' => true, 'sort_order' => 1,
            ],
            [
                'name' => 'Business', 'slug' => 'business',
                'description' => 'More headroom for growing shops.',
                'price' => 150, 'duration_days' => 30, 'trial_days' => 14,
                'product_limit' => 2000, 'user_limit' => 15, 'storage_mb' => 1000,
                'features' => [
                    'Everything in Starter', 'Up to 2,000 products', '15 user accounts',
                    'Invoices & statements', 'Wholesale pricing', 'Priority support',
                ],
                'is_active' => true, 'sort_order' => 2,
            ],
            [
                'name' => 'Pro', 'slug' => 'pro',
                'description' => 'For busy, multi-staff operations.',
                'price' => 300, 'duration_days' => 30, 'trial_days' => 14,
                'product_limit' => null, 'user_limit' => null, 'storage_mb' => 5000,
                'features' => [
                    'Everything in Business', 'Unlimited products', 'Unlimited user accounts',
                    'All roles & permissions', 'Advanced analytics', 'Dedicated support',
                ],
                'is_active' => true, 'sort_order' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
