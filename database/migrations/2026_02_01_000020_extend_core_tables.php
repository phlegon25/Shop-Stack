<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // --- Business identity (collected at registration) ---
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('business_type', 60)->nullable()->after('name');
            $table->string('address', 300)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('region', 80)->nullable();
            $table->string('country', 80)->default('Ghana');
            $table->string('currency', 3)->default('GHS');
        });

        // --- Subcategories (self-referencing) ---
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('tenant_id')->constrained('categories')->nullOnDelete();
        });

        // --- Extended product profile ---
        Schema::table('products', function (Blueprint $table) {
            $table->string('subcategory', 100)->nullable()->after('category_id');
            $table->string('brand', 100)->nullable()->after('subcategory');
            $table->string('unit', 40)->nullable()->after('brand');
            $table->text('description')->nullable()->after('name');
            $table->decimal('wholesale_price', 12, 2)->nullable()->after('selling_price');
            $table->unsignedInteger('max_stock')->nullable()->after('low_stock_threshold');
            $table->unsignedBigInteger('supplier_id')->nullable()->after('brand');
            $table->date('expiry_date')->nullable()->after('max_stock');
            $table->string('location', 80)->nullable()->after('expiry_date');
            $table->index(['tenant_id', 'expiry_date']);
        });

        // --- COGS snapshot for accurate profit reporting ---
        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 2)->nullable()->after('unit_price');
        });

        // --- Credit sales & customers on sales ---
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->date('due_date')->nullable()->after('status');
            $table->index(['tenant_id', 'customer_id']);
        });

        // --- Payables on purchases ---
        Schema::table('purchases', function (Blueprint $table) {
            $table->decimal('amount_paid', 12, 2)->default(0)->after('total');
            $table->date('due_date')->nullable()->after('status');
        });

        // --- Plan limits (managed by Super Admin) ---
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('product_limit')->nullable()->after('duration_days');
            $table->unsignedInteger('user_limit')->nullable()->after('product_limit');
            $table->unsignedInteger('storage_mb')->nullable()->after('user_limit');
            $table->json('features')->nullable()->after('storage_mb');
            $table->unsignedSmallInteger('trial_days')->default(0)->after('features');
        });

        // --- Platform configuration incl. grace period ---
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->string('momo_provider_2', 40)->nullable()->after('momo_provider');
            $table->string('momo_number_2', 30)->nullable()->after('momo_provider_2');
            $table->string('momo_name_2')->nullable()->after('momo_number_2');
            $table->unsignedSmallInteger('grace_period_days')->default(7)->after('payment_instructions');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('grace_ends_at')->nullable()->after('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn(['business_type', 'address', 'city', 'region', 'country', 'currency']));
        Schema::table('categories', fn (Blueprint $t) => $t->dropConstrainedForeignId('parent_id'));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['subcategory', 'brand', 'unit', 'description', 'wholesale_price', 'max_stock', 'supplier_id', 'expiry_date', 'location']));
        Schema::table('sale_items', fn (Blueprint $t) => $t->dropColumn('unit_cost'));
        Schema::table('sales', fn (Blueprint $t) => $t->dropConstrainedForeignId('customer_id')->dropColumn('due_date'));
        Schema::table('purchases', fn (Blueprint $t) => $t->dropColumn(['amount_paid', 'due_date']));
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn(['product_limit', 'user_limit', 'storage_mb', 'features', 'trial_days']));
        Schema::table('platform_settings', fn (Blueprint $t) => $t->dropColumn(['momo_provider_2', 'momo_number_2', 'momo_name_2', 'grace_period_days']));
        Schema::table('subscriptions', fn (Blueprint $t) => $t->dropColumn('grace_ends_at'));
    }
};
