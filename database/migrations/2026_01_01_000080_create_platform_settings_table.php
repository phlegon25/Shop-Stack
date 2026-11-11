<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-level payment collection details (MoMo / bank).
        // Single row. Tenant-controllable endpoints never touch this table.
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('momo_number', 30)->nullable();
            $table->string('momo_name')->nullable();
            $table->string('momo_provider', 40)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number', 60)->nullable();
            $table->string('bank_account_name')->nullable();
            $table->text('payment_instructions')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
