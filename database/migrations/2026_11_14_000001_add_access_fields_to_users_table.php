<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 60)->nullable()->unique()->after('name');
            $table->string('employee_id', 40)->nullable()->unique()->after('username');
            $table->string('phone', 30)->nullable()->after('email');
            $table->string('department', 120)->nullable();
            $table->string('designation', 120)->nullable();
            $table->string('status', 20)->default('active')->index(); // active | inactive | disabled
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->string('profile_photo_path')->nullable();
            $table->json('preferences')->nullable();
            $table->boolean('two_factor_enabled')->default(false);
            $table->timestamp('password_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'username', 'employee_id', 'phone', 'department', 'designation', 'status',
                'failed_login_attempts', 'locked_until', 'last_login_at', 'last_login_ip',
                'profile_photo_path', 'preferences', 'two_factor_enabled', 'password_changed_at',
            ]);
        });
    }
};
