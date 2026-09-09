<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('request_key');
            $table->dateTime('previous_return_at');
            $table->dateTime('new_return_at');
            $table->decimal('additional_amount', 12, 2)->nullable();
            $table->decimal('previous_amount', 12, 2)->nullable();
            $table->decimal('new_amount', 12, 2);
            $table->decimal('previous_override', 12, 2)->nullable();
            $table->decimal('new_override', 12, 2)->nullable();
            $table->json('previous_pricing')->nullable();
            $table->json('new_pricing');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['rental_id', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_extensions');
    }
};
