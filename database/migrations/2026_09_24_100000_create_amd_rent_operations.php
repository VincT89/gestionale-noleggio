<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('amd_rent_settings', function (Blueprint $t) {
            $t->id(); $t->unsignedInteger('delivery_commission_bps')->nullable(); $t->timestamps();
        });
        Schema::table('public_delivery_locations', function (Blueprint $t) {
            $t->boolean('custom_delivery_enabled')->default(false);
            $t->string('delivery_area', 500)->nullable();
        });
        Schema::create('amd_rent_enquiries', function (Blueprint $t) {
            $t->id(); $t->string('reference', 32)->unique(); $t->char('request_hash', 64)->nullable()->unique();
            $t->string('type', 20); $t->string('status', 24)->default('new');
            $t->foreignId('organization_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('customer_name', 191); $t->string('email', 191); $t->string('phone', 32);
            $t->string('customer_type', 20)->default('individual'); $t->string('company_name', 191)->nullable();
            $t->string('vehicle_request', 191)->nullable(); $t->unsignedInteger('duration_months')->nullable();
            $t->unsignedInteger('annual_km')->nullable(); $t->text('notes')->nullable();
            $t->json('booking_context')->nullable(); $t->string('delivery_address', 500)->nullable();
            $t->unsignedBigInteger('delivery_fee_cents')->nullable(); $t->timestamp('quote_expires_at')->nullable();
            $t->foreignId('public_booking_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('selected_quote_id')->nullable();
            $t->unsignedBigInteger('platform_commission_cents')->nullable();
            $t->unsignedBigInteger('renter_commission_cents')->nullable();
            $t->string('commission_status', 20)->default('expected');
            $t->string('contract_reference', 191)->nullable(); $t->date('signed_at')->nullable();
            $t->unsignedInteger('revision')->default(1); $t->timestamps();
            $t->index(['organization_id', 'type', 'status']);
        });
        Schema::create('amd_rent_quotes', function (Blueprint $t) {
            $t->id(); $t->foreignId('enquiry_id')->constrained('amd_rent_enquiries')->cascadeOnDelete();
            $t->string('supplier', 191); $t->string('vehicle', 191); $t->unsignedInteger('months');
            $t->unsignedInteger('annual_km'); $t->unsignedBigInteger('monthly_cents');
            $t->unsignedBigInteger('upfront_cents')->default(0); $t->string('vat', 16);
            $t->date('valid_until'); $t->text('conditions')->nullable(); $t->timestamps();
        });
        Schema::create('amd_rent_documents', function (Blueprint $t) {
            $t->id(); $t->foreignId('enquiry_id')->constrained('amd_rent_enquiries')->cascadeOnDelete();
            $t->string('kind', 24); $t->string('name'); $t->string('path');
            $t->unsignedBigInteger('size'); $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
        });
        Schema::create('amd_rent_events', function (Blueprint $t) {
            $t->id(); $t->foreignId('enquiry_id')->constrained('amd_rent_enquiries')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('description', 500); $t->timestamps();
        });
        Schema::table('public_bookings', function (Blueprint $t) {
            $t->string('payment_status', 24)->default('pickup');
            $t->unsignedBigInteger('online_due_cents')->default(0); $t->unsignedBigInteger('online_paid_cents')->default(0);
            $t->unsignedBigInteger('refunded_cents')->default(0); $t->unsignedBigInteger('delivery_fee_cents')->default(0);
            $t->unsignedInteger('delivery_commission_bps')->nullable();
            $t->string('stripe_session_id')->nullable()->unique(); $t->string('stripe_payment_intent')->nullable()->unique();
            $t->text('checkout_url')->nullable(); $t->json('checkout_payload')->nullable();
            $t->json('payment_review_history')->nullable();
            $t->timestamp('payment_expires_at')->nullable(); $t->timestamp('paid_at')->nullable();
            $t->index(['payment_status', 'payment_expires_at']);
        });
        Schema::table('rentals', function (Blueprint $t) {
            $t->string('booking_channel', 24)->nullable()->index();
            $t->decimal('admin_fee_collected_amount', 12, 2)->default(0);
            $t->decimal('amd_extra_fee_percent', 7, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rentals', fn (Blueprint $t) => $t->dropColumn(['booking_channel', 'admin_fee_collected_amount', 'amd_extra_fee_percent']));
        Schema::table('public_bookings', fn (Blueprint $t) => $t->dropColumn(['payment_status', 'online_due_cents', 'online_paid_cents', 'refunded_cents', 'delivery_fee_cents', 'delivery_commission_bps', 'stripe_session_id', 'stripe_payment_intent', 'checkout_url', 'checkout_payload', 'payment_review_history', 'payment_expires_at', 'paid_at']));
        foreach (['amd_rent_events', 'amd_rent_documents', 'amd_rent_quotes', 'amd_rent_enquiries', 'amd_rent_settings'] as $table) Schema::dropIfExists($table);
        Schema::table('public_delivery_locations', fn (Blueprint $t) => $t->dropColumn(['custom_delivery_enabled', 'delivery_area']));
    }
};
