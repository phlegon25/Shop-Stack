<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Demo data (local/dev only). Two tenants so cross-tenant isolation can be
 * exercised manually. Credentials are documented in README and are intended
 * ONLY for local development.
 */
class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $this->seedTenant(
            business: 'Accra Retail',
            ownerEmail: 'adjoa@demo.test',
            ownerName: 'Adjoa Mensah',
            staff: [['name' => 'Kofi Boateng', 'email' => 'kofi@demo.test', 'role' => 'cashier']],
            products: [
                ['sku' => 'RICE-5KG', 'name' => 'Rice 5kg', 'cost' => 60, 'price' => 85, 'stock' => 40],
                ['sku' => 'OIL-1L',   'name' => 'Cooking Oil 1L', 'cost' => 18, 'price' => 30, 'stock' => 60],
                ['sku' => 'SOAP-XL',  'name' => 'Bath Soap XL', 'cost' => 5, 'price' => 9, 'stock' => 3],
            ],
        );

        $this->seedTenant(
            business: 'Kumasi Mart',
            ownerEmail: 'yaw@demo.test',
            ownerName: 'Yaw Darko',
            staff: [],
            products: [
                ['sku' => 'SUGAR-1KG', 'name' => 'Sugar 1kg', 'cost' => 10, 'price' => 16, 'stock' => 25],
                ['sku' => 'MILK-TIN',  'name' => 'Tin Milk', 'cost' => 7, 'price' => 12, 'stock' => 80],
            ],
        );
    }

    private function seedTenant(string $business, string $ownerEmail, string $ownerName, array $staff, array $products): void
    {
        $context = app(TenantContext::class);

        $tenant = Tenant::firstOrCreate(
            ['slug' => \Illuminate\Support\Str::slug($business)],
            ['name' => $business, 'email' => $ownerEmail],
        );

        $owner = User::firstOrNew(['email' => $ownerEmail]);
        $owner->fill(['name' => $ownerName, 'password' => 'Tenant#Pass2026']);
        $owner->tenant_id = $tenant->id;
        $owner->role      = User::ROLE_OWNER;
        $owner->status    = User::STATUS_ACTIVE;
        $owner->password_changed_at ??= now();
        $owner->save();

        foreach ($staff as $member) {
            $user = User::firstOrNew(['email' => $member['email']]);
            $user->fill(['name' => $member['name'], 'password' => 'Tenant#Pass2026']);
            $user->tenant_id = $tenant->id;
            $user->role      = $member['role'];
            $user->status    = User::STATUS_ACTIVE;
            $user->password_changed_at ??= now();
            $user->save();
        }

        if (! $tenant->currentSubscription()->exists()) {
            app(SubscriptionService::class)->startTrial($tenant);
        }

        // Scoped seeding: establish the tenant context so the global scope
        // assigns tenant_id exactly as the HTTP layer would.
        $ownerUser = $owner; // context uses the tenant relation
        $context->setUser($ownerUser);

        $category = Category::firstOrCreate(['name' => 'Groceries']);

        foreach ($products as $item) {
            $product = Product::firstOrNew(['sku' => $item['sku']]);
            $product->fill([
                'name'           => $item['name'],
                'category_id'    => $category->id,
                'cost_price'     => $item['cost'],
                'selling_price'  => $item['price'],
                'is_active'      => true,
            ]);
            $isNewProduct = ! $product->exists;
            $product->save();

            if (($isNewProduct || (int) $product->stock_quantity === 0) && $item['stock'] > 0) {
                app(\App\Services\StockService::class)->apply($product, $item['stock'], 'adjustment', null, 'Opening stock');
            }
        }

        $context->clear();
    }
}
