<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('location_id')->constrained('departments')->nullOnDelete();
            $table->string('asset_type', 20)->default('movable')->after('model'); // movable | immovable
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_cost', 14, 2)->nullable();
            $table->string('funding_source', 120)->nullable();
            $table->string('vendor_name', 160)->nullable();
            $table->string('invoice_number', 80)->nullable();
            $table->text('specifications')->nullable();
            $table->date('warranty_expiry_at')->nullable();
            $table->date('amc_expiry_at')->nullable();
            $table->string('amc_vendor', 160)->nullable();
            $table->date('last_pm_at')->nullable();
            $table->date('last_calibration_at')->nullable();
            $table->text('remarks')->nullable();
            $table->string('image_path')->nullable();
            $table->index('warranty_expiry_at');
            $table->index('amc_expiry_at');
        });

        // Status vocabulary: operational | under_maintenance | out_of_service | retired | disposed
        DB::table('assets')->where('status', 'active')->update(['status' => 'operational']);
        Schema::table('assets', function (Blueprint $table) {
            $table->string('status', 30)->default('operational')->change();
        });

        Schema::create('asset_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_documents');
        DB::table('assets')->where('status', 'operational')->update(['status' => 'active']);
        Schema::table('assets', function (Blueprint $table) {
            $table->string('status', 30)->default('active')->change();
            $table->dropIndex(['warranty_expiry_at']);
            $table->dropIndex(['amc_expiry_at']);
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn(['asset_type', 'purchase_date', 'purchase_cost', 'funding_source', 'vendor_name', 'invoice_number',
                'specifications', 'warranty_expiry_at', 'amc_expiry_at', 'amc_vendor', 'last_pm_at', 'last_calibration_at', 'remarks', 'image_path']);
        });
    }
};
