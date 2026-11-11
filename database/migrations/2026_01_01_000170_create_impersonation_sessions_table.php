<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonation_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('super_admin_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->text('reason'); // mandatory justification
            $table->unsignedBigInteger('token_id')->nullable(); // sanctum token backing the session
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('expires_at'); // short-lived, enforced twice (token + record)
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 60)->nullable(); // manual | expired
            $table->timestamps();

            $table->index(['tenant_id', 'started_at']);
            $table->index(['super_admin_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_sessions');
    }
};
