<?php

namespace Tests\Feature;

use App\Models\{Rental, RentalCharge};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\Support\PublicBookingTestCase;

class RentalPaymentIsolationTest extends PublicBookingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('rental_charges', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('rental_id'); $table->string('kind');
            $table->boolean('payment_recorded')->default(false); $table->softDeletes();
        });
    }

    public function test_paid_extra_kilometers_on_another_rental_do_not_settle_this_rental(): void
    {
        DB::table('rental_charges')->insert([
            'rental_id' => 2, 'kind' => RentalCharge::KIND_BASE_PLUS_DISTANCE_OVERAGE, 'payment_recorded' => true,
        ]);
        $rental = new Rental();
        $rental->id = 1;
        $this->assertFalse($rental->has_distance_overage_payment);
    }

    public function test_extra_kilometers_require_a_recorded_payment_on_this_rental(): void
    {
        $rental = new Rental();
        $rental->id = 1;
        foreach ([RentalCharge::KIND_DISTANCE_OVERAGE, RentalCharge::KIND_BASE_PLUS_DISTANCE_OVERAGE] as $kind) {
            $id = DB::table('rental_charges')->insertGetId(['rental_id' => 1, 'kind' => $kind]);
            $this->assertFalse($rental->has_distance_overage_payment, $kind.' is not paid');
            DB::table('rental_charges')->where('id', $id)->update(['payment_recorded' => true]);
            $this->assertTrue($rental->has_distance_overage_payment, $kind.' is paid');
            DB::table('rental_charges')->where('id', $id)->update(['deleted_at' => now()]);
            $this->assertFalse($rental->has_distance_overage_payment, $kind.' is deleted');
        }
    }
}
