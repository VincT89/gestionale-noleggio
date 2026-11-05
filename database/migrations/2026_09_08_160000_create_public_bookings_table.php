<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('public_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 24)->unique();
            $table->char('request_hash', 64)->unique();
            $table->foreignId('rental_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('public_rental_offer_id')->constrained()->restrictOnDelete();
            $table->string('first_name', 90);
            $table->string('last_name', 90);
            $table->string('email', 191);
            $table->string('phone', 32);
            $table->dateTime('pickup_at');
            $table->dateTime('return_at');
            $table->unsignedBigInteger('total_cents');
            $table->unsignedBigInteger('deposit_cents');
            $table->string('payment_method', 32)->default('pay_at_pickup');
            $table->json('quote_snapshot');
            $table->timestamp('accepted_at');
            $table->timestamp('confirmation_email_sent_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_bookings');
    }
};
