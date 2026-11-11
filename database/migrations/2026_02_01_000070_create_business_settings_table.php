<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('logo_path')->nullable();
            $table->string('currency', 3)->default('GHS');
            $table->string('timezone', 60)->default('Africa/Accra');
            $table->decimal('tax_rate', 5, 2)->default(0);   // % applied at POS
            $table->string('invoice_footer', 500)->nullable();
            $table->unsignedInteger('low_stock_default')->default(5);
            $table->unsignedSmallInteger('expiry_warn_days')->default(30);
            $table->boolean('onboarding_completed')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_settings');
    }
};
