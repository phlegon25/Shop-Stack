<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            // trialing | active | expired | cancelled
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->index();
            // Set when this subscription was granted by an approved payment.
            $table->unsignedBigInteger('source_payment_id')->nullable();
            // 'system' (trial) | super admin id (manual grant/adjustment)
            $table->string('granted_by', 40)->default('system');
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
