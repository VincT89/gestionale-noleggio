<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_rental_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('pricelist_id')->constrained('vehicle_pricelists')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->boolean('prices_include_vat')->default(false);
            $table->boolean('is_published')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['vehicle_id', 'organization_id']);
            $table->index(['is_published', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_rental_offers');
    }
};
