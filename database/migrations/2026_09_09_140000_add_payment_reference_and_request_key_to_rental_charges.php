<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_charges', function (Blueprint $table) {
            $table->string('payment_reference', 255)->nullable();
            $table->uuid('request_key')->nullable();
            $table->unique(['rental_id', 'request_key'], 'rental_charges_request_unique');
        });
    }

    public function down(): void
    {
        Schema::table('rental_charges', function (Blueprint $table) {
            $table->dropUnique('rental_charges_request_unique');
            $table->dropColumn(['payment_reference', 'request_key']);
        });
    }
};
