<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manufacturers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('country', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('manufacturer_id')->nullable()->after('manufacturer')->constrained('manufacturers')->nullOnDelete();
        });

        // Staff are users; the directory just links them to a department master.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('type', 20)->default('regular'); // regular | on_call
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['facility_id', 'name']);
        });

        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('shift_date');
            $table->timestamps();
            $table->unique(['shift_id', 'user_id', 'shift_date']);
            $table->index(['facility_id', 'shift_date']);
        });

        Schema::create('priority_levels', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();   // matches work_orders.priority
            $table->string('name', 60);
            $table->string('color', 7)->default('#2d7be5');
            $table->unsignedInteger('response_minutes');
            $table->unsignedInteger('resolution_minutes');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // The four priorities work orders already use, with the SLA targets from the setup design.
        $now = now();
        foreach ([
            ['critical', 'Critical', '#d64045', 30, 240, 1],
            ['high', 'High', '#e08a1e', 120, 480, 2],
            ['medium', 'Medium', '#c9a400', 480, 4320, 3],
            ['low', 'Low', '#1f9d55', 1440, 10080, 4],
        ] as [$code, $name, $color, $response, $resolution, $order]) {
            DB::table('priority_levels')->insert([
                'code' => $code, 'name' => $name, 'color' => $color, 'response_minutes' => $response,
                'resolution_minutes' => $resolution, 'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        Schema::table('work_orders', function (Blueprint $table) {
            $table->timestamp('response_due_at')->nullable()->after('due_at');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', fn (Blueprint $t) => $t->dropColumn('response_due_at'));
        Schema::dropIfExists('priority_levels');
        Schema::dropIfExists('shift_assignments');
        Schema::dropIfExists('shifts');
        Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('department_id'));
        Schema::table('assets', fn (Blueprint $t) => $t->dropConstrainedForeignId('manufacturer_id'));
        Schema::dropIfExists('manufacturers');
    }
};
