<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenants SUBMIT payment evidence; ONLY a super admin may approve/reject.
        // Status transitions are server-side and audited.
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('method', 10); // momo | bank
            $table->string('sender_name')->nullable();
            $table->string('sender_number', 30)->nullable();
            // Tenant-supplied transaction reference — globally unique (anti-duplicate / anti-tamper).
            $table->string('reference', 120)->unique();
            $table->string('proof_path')->nullable();
            // pending | approved | rejected — default pending, tenants CANNOT change it.
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('submitted_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
