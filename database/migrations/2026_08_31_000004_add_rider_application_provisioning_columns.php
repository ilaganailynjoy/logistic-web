<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rider Account Provisioning — additive columns that let the Logistics
     * admin review a rider_application and, upon approval, atomically create a
     * working rider login (User role=rider + linked Rider profile).
     *
     * All changes are additive (no data loss on the shared invoizdb).
     */
    public function up(): void
    {
        Schema::table('rider_applications', function (Blueprint $table) {
            $table->foreignId('center_id')->nullable()->after('vehicle_registration')->constrained('logistics_centers')->nullOnDelete();
            $table->foreignId('service_area_id')->nullable()->after('center_id')->constrained('service_areas')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->after('service_area_id')->constrained('users')->nullOnDelete();
            $table->timestamp('provisioned_at')->nullable()->after('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('rider_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('provisioned_at');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('service_area_id');
            $table->dropConstrainedForeignId('center_id');
        });
    }
};
