<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('public_delivery_locations', function (Blueprint $table) {
            $table->foreignId('delivery_origin_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->decimal('delivery_radius_km', 8, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('public_delivery_locations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_origin_location_id');
            $table->dropColumn('delivery_radius_km');
        });
    }
};
