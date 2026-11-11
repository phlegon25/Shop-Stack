<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Custom, hardened reset tokens: random 64-char tokens, stored only as
        // SHA-256 hashes, single-use (used_at), and expiring (expires_at).
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 20); // user | super_admin
            $table->string('email')->index();
            $table->string('token_hash', 64)->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
