<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->string('asset_tag', 60)->unique();
            $table->string('name');
            $table->string('category', 120)->nullable();
            $table->string('manufacturer', 120)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('serial_number', 120)->nullable();
            $table->string('status', 30)->default('active'); // active | under_maintenance | retired
            $table->date('installed_at')->nullable();
            $table->date('next_pm_due_at')->nullable();
            $table->date('next_calibration_due_at')->nullable();
            $table->timestamps();
            $table->index(['facility_id', 'status']);
            $table->index('next_pm_due_at');
            $table->index('next_calibration_due_at');
        });

        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 40)->unique();
            $table->string('title');
            $table->string('type', 30);     // preventive | breakdown | calibration | installation
            $table->string('priority', 20); // critical | high | medium | low
            $table->string('status', 30)->default('open'); // open | in_progress | on_hold | completed | cancelled
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['facility_id', 'status']);
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_orders');
        Schema::dropIfExists('assets');
    }
};
