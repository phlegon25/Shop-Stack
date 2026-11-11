<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // opened by
            $table->string('subject', 160);
            // open | in_progress | waiting | resolved | closed
            $table->string('status', 20)->default('open');
            $table->string('priority', 10)->default('normal'); // low | normal | high
            $table->foreignId('assigned_to')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('support_ticket_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->string('author_type', 20); // user | super_admin
            $table->unsignedBigInteger('author_id');
            $table->string('author_name');
            $table->text('body');
            $table->boolean('is_internal')->default(false); // admin-only notes, hidden from tenant
            $table->timestamp('created_at')->useCurrent();

            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_replies');
        Schema::dropIfExists('support_tickets');
    }
};
