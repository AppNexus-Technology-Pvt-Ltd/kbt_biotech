<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The framework's password_reset_tokens table is reused: `token` now holds a SHA-256 hash
        // and expiry is derived from created_at. We only add a lookup index on the hash.
        Schema::table('password_reset_tokens', function (Blueprint $table) {
            $table->index('token');
        });

        Schema::create('login_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('identifier')->nullable();
            $table->string('status', 30); // success | failed | locked | disabled | inactive | throttled | otp_failed
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
            $table->index(['ip_address', 'created_at']);
        });

        Schema::create('otp_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 30)->default('login');
            $table->string('channel', 10)->default('email');
            $table->string('destination');
            $table->string('code_hash', 64);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'purpose', 'consumed_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80)->index();
            $table->string('subject_type', 80)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('otp_verifications');
        Schema::dropIfExists('login_history');
        Schema::table('password_reset_tokens', function (Blueprint $table) {
            $table->dropIndex(['token']);
        });
    }
};
