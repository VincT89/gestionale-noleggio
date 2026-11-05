<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        /** Update the default while preserving saved rates and existing booking snapshots. */
        Schema::table('amd_rent_settings', fn (Blueprint $table) => $table->unsignedInteger('commission_bps')->default(1500)->change());
    }

    public function down(): void
    {
        Schema::table('amd_rent_settings', fn (Blueprint $table) => $table->unsignedInteger('commission_bps')->default(2000)->change());
    }
};
