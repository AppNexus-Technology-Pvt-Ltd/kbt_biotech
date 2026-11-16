<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installation_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_number', 30)->unique();           // INST-0001
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('type', 20)->default('new_installation'); // new_installation | relocation | recommissioning
            $table->string('priority', 20)->default('normal');       // low | normal | high | critical
            $table->string('vendor_name', 160)->nullable();
            $table->date('planned_date');
            $table->decimal('estimated_hours', 6, 1)->nullable();
            $table->foreignId('engineer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->string('status', 20)->default('draft');          // draft | in_progress | pending_approval | completed | cancelled
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();           // draft -> in progress
            $table->foreignId('approval_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approval_requested_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();       // rejection / cancellation reason
            $table->timestamp('completed_at')->nullable();
            $table->string('certificate_number', 40)->nullable()->unique();
            $table->timestamps();
            $table->index(['facility_id', 'status']);
            $table->index(['asset_id', 'status']);
            $table->index('planned_date');
        });

        Schema::create('installation_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_job_id')->constrained()->cascadeOnDelete();
            $table->string('section', 20);                           // site_readiness | installation | acceptance
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('title');
            $table->string('detail')->nullable();                    // requirement / details / specification
            $table->string('measured_value', 120)->nullable();       // acceptance tests
            $table->string('status', 20)->default('pending');
            $table->string('remarks', 255)->nullable();
            $table->boolean('is_custom')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['installation_job_id', 'section', 'position'], 'inst_items_job_section_idx');
        });

        Schema::create('installation_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_job_id')->constrained()->cascadeOnDelete();
            $table->string('category', 30)->default('document');     // document | signed_certificate
            $table->string('title');
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('installation_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_job_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('handover_date');
            $table->foreignId('handover_to')->constrained('users')->restrictOnDelete();
            $table->json('staff_trained')->nullable();               // [{"group":"Nurse Team","count":5}]
            $table->text('notes')->nullable();
            $table->string('signature_path');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['installation_handovers', 'installation_documents', 'installation_checklist_items', 'installation_jobs'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
