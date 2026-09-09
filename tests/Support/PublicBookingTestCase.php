<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

abstract class PublicBookingTestCase extends PublicCarsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::table('rentals', function (Blueprint $t) {
            foreach (['number_id', 'assignment_id', 'customer_id', 'pickup_location_id', 'return_location_id'] as $field) $t->unsignedBigInteger($field)->nullable();
            $t->decimal('amount', 12, 2)->nullable(); $t->decimal('final_amount_override', 12, 2)->nullable();
            $t->text('notes')->nullable();
            $t->unique(['organization_id', 'number_id']);
        });
        Schema::create('customers', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('organization_id'); $t->string('name');
            foreach (['first_name', 'last_name', 'email', 'phone'] as $field) $t->string($field)->nullable();
            $t->softDeletes(); $t->timestamps();
        });
        Schema::create('renter_contract_number_ledger', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('organization_id'); $t->unsignedBigInteger('rental_id');
            $t->unsignedBigInteger('number_id'); $t->unsignedBigInteger('created_by')->nullable(); $t->timestamps();
            $t->unique(['organization_id', 'number_id']); $t->unique(['organization_id', 'rental_id']);
        });
        Schema::create('rental_contract_snapshots', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('rental_id')->unique(); $t->json('pricing_snapshot');
            $t->unsignedBigInteger('created_by_user_id')->nullable(); $t->timestamps();
        });
        Schema::create('rental_coverages', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('rental_id')->unique();
            foreach (['rca', 'kasko', 'furto_incendio', 'cristalli', 'assistenza'] as $f) { $t->boolean($f)->default(false); $t->decimal('franchise_'.$f, 12, 2)->nullable(); }
            $t->timestamps();
        });
        (require database_path('migrations/2026_09_08_160000_create_public_bookings_table.php'))->up();
        foreach ([2 => 'rentals.viewAny', 3 => 'rentals.view', 4 => 'rentals.create'] as $id => $name) {
            DB::table('permissions')->insert(['id' => $id, 'name' => $name]);
            DB::table('role_has_permissions')->insert(['role_id' => 2, 'permission_id' => $id]);
        }
    }
}
