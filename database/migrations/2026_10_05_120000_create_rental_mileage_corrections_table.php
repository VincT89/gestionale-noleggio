<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Business records must survive routine cleanup of the general activity log.
        Schema::create('rental_mileage_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_id')->constrained('rentals')->restrictOnDelete();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('properties');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_mileage_corrections');
    }
};
