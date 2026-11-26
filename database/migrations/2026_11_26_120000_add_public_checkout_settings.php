<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_pricelists', fn (Blueprint $table) => $table->json('driver_age_supplements')->nullable());
        Schema::table('amd_rent_options', fn (Blueprint $table) => $table->boolean('is_available')->default(true));
    }

    public function down(): void
    {
        Schema::table('amd_rent_options', fn (Blueprint $table) => $table->dropColumn('is_available'));
        Schema::table('vehicle_pricelists', fn (Blueprint $table) => $table->dropColumn('driver_age_supplements'));
    }
};
