<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amd_rent_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->text('exclusions')->nullable();
            $table->json('coverage')->nullable();
            $table->json('franchise_multipliers')->nullable();
            $table->string('protection_type', 20)->nullable();
            $table->string('provider', 150)->nullable();
            $table->string('terms_url', 500)->nullable();
            $table->unsignedInteger('price_cents')->nullable();
            $table->unsignedInteger('price_multiplier_bps')->nullable();
            $table->string('price_unit', 10)->nullable();
            $table->boolean('depositable')->default(false);
            $table->boolean('commissionable')->default(false);
            $table->boolean('covers_second_driver')->default(false);
            $table->boolean('published')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['organization_id', 'published', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amd_rent_options');
    }
};
