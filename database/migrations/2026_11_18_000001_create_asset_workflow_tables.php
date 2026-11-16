<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            // where the asset was when the request was made
            $table->foreignId('from_facility_id')->constrained('facilities')->restrictOnDelete();
            $table->foreignId('from_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('from_location_id')->nullable()->constrained('locations')->nullOnDelete();
            // where it should go
            $table->foreignId('to_facility_id')->constrained('facilities')->restrictOnDelete();
            $table->foreignId('to_department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('to_location_id')->constrained('locations')->restrictOnDelete();
            $table->date('requested_date');
            $table->string('reason', 255);
            $table->text('remarks')->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected | cancelled
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->timestamps();
            $table->index(['status', 'from_facility_id']);
            $table->index(['asset_id', 'status']);
        });

        Schema::create('asset_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 30);
            $table->string('to_status', 30);
            $table->string('reason', 255);
            $table->date('effective_date');
            $table->text('remarks')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['asset_id', 'created_at']);
        });

        Schema::create('asset_disposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);        // retirement | disposal
            $table->string('reason', 40);
            $table->string('condition', 30);
            $table->date('disposal_date');
            $table->text('remarks')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['asset_disposals', 'asset_status_changes', 'asset_transfers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
