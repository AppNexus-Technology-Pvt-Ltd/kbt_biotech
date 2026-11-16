<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->string('type', 40)->nullable()->after('code');
            $table->string('address')->nullable()->after('city');
            $table->string('state', 120)->nullable()->after('address');
            $table->string('country', 120)->nullable()->after('state');
            $table->string('pin_code', 20)->nullable()->after('country');
            $table->string('image_path')->nullable();
        });

        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->unsignedSmallInteger('floors')->default(1);
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['facility_id', 'code']);
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->string('type', 20)->default('clinical'); // clinical | non_clinical
            $table->foreignId('hod_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['facility_id', 'code']);
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->foreignId('building_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->string('type', 30)->default('room'); // room | ward | operating_theatre | laboratory | icu | other
            $table->smallInteger('floor')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['facility_id', 'code']);
        });

        Schema::create('equipment_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('assets', function (Blueprint $table) {
            // The free-text `category` column stays for now; these link assets to the new masters.
            $table->foreignId('category_id')->nullable()->after('category')->constrained('equipment_categories')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->after('category_id')->constrained('locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
            $table->dropConstrainedForeignId('category_id');
        });
        foreach (['equipment_categories', 'locations', 'departments', 'buildings'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('facilities', function (Blueprint $table) {
            $table->dropColumn(['type', 'address', 'state', 'country', 'pin_code', 'image_path']);
        });
    }
};
