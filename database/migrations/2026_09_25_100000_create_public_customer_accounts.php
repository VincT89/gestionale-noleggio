<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('public_customers', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 90);
            $table->string('last_name', 90);
            $table->string('email', 191)->unique();
            $table->string('phone', 32)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('public_customer_password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 191)->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        foreach (['public_bookings', 'amd_rent_enquiries'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('public_customer_id')->nullable()->constrained('public_customers')->nullOnDelete();
            });
        }
        Schema::table('amd_rent_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('uploaded_by')->nullable()->change();
            $table->foreignId('public_customer_id')->nullable()->constrained('public_customers')->nullOnDelete();
            $table->boolean('customer_visible')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('amd_rent_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('public_customer_id');
            $table->dropColumn('customer_visible');
            // Keep uploaded_by nullable so customer-uploaded documents survive a rollback.
        });
        foreach (['public_bookings', 'amd_rent_enquiries'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('public_customer_id'));
        }
        Schema::dropIfExists('public_customer_password_reset_tokens');
        Schema::dropIfExists('public_customers');
    }
};
