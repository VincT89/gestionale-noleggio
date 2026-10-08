<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_delivery_locations', function (Blueprint $table) {
            // Null means not configured; zero is an explicitly free package.
            $table->unsignedInteger('custom_delivery_fee_cents')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('public_delivery_locations', fn (Blueprint $table) => $table->dropColumn('custom_delivery_fee_cents'));
    }
};
