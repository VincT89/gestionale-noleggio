<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('amd_rent_settings', fn (Blueprint $table) => $table->unsignedInteger('commission_bps')->default(2000));
    }

    public function down(): void
    {
        Schema::table('amd_rent_settings', fn (Blueprint $table) => $table->dropColumn('commission_bps'));
    }
};
