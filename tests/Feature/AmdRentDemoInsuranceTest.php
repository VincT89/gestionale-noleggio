<?php

namespace Tests\Feature;

use App\Models\AmdRentOption;
use App\Services\AmdRent\DemoFleet;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Http, Schema};
use Tests\Support\PublicBookingTestCase;

class AmdRentDemoInsuranceTest extends PublicBookingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Schema::table('vehicles', fn (Blueprint $table) => $table->text('notes')->nullable());
        DB::table('organizations')->where('id', 2)->update(['name' => 'DEMO AMD Rent']);
        $this->offer(1, 2, vehicle: ['make' => 'Fiat', 'model' => 'Panda dimostrativa', 'notes' => DemoFleet::marker(2, 'vehicle', 10)]);
        $this->offer(2);
        DB::table('vehicle_assignments')->insert(['vehicle_id' => 1, 'renter_org_id' => 2, 'start_at' => '2026-01-01']);
    }

    public function test_inventory_is_read_only_and_apply_is_idempotent_without_changing_originals(): void
    {
        $vehicles = DB::table('vehicles')->orderBy('id')->get()->toJson();
        $prices = DB::table('vehicle_pricelists')->orderBy('id')->get()->toJson();
        $this->artisan('amd-rent:demo-insurance')->expectsOutputToContain('Solo lettura')->assertSuccessful();
        $this->assertDatabaseCount('amd_rent_options', 0);
        $this->seedProtections();
        $this->assertDatabaseCount('amd_rent_options', 2);
        $plus = AmdRentOption::where('kind', 'plus')->sole();
        $plus->update(['description' => 'Modifica manuale da conservare', 'published' => false]);
        $this->seedProtections();
        $this->assertDatabaseCount('amd_rent_options', 2);
        $this->assertSame('Modifica manuale da conservare', $plus->fresh()->description);
        $this->assertFalse($plus->fresh()->published);
        $this->assertSame($vehicles, DB::table('vehicles')->orderBy('id')->get()->toJson());
        $this->assertSame($prices, DB::table('vehicle_pricelists')->orderBy('id')->get()->toJson());
    }

    public function test_command_refuses_real_or_mixed_fleets_and_requires_an_explicit_target(): void
    {
        $this->artisan('amd-rent:demo-insurance', ['--apply' => true])->assertFailed();
        $this->artisan('amd-rent:demo-insurance', ['renter' => '1', '--apply' => true])->assertFailed();
        $this->offer(3, 2);
        $this->artisan('amd-rent:demo-insurance', ['renter' => '2', '--apply' => true])->assertFailed();
        $this->assertDatabaseCount('amd_rent_options', 0);
    }

    public function test_demo_details_show_real_options_markup_without_enabling_checkout(): void
    {
        $this->seedProtections();
        $page = $this->demoDetail()->assertOk()->assertSee('Protezione standard inclusa')
            ->assertSee('AMD Plus')->assertSee('+88,00 € per il noleggio')->assertSee('3.000,00 €')
            ->assertSee('500,00 €')->assertSee('condizioni dimostrativi')->assertDontSee('Prenota questa auto');
        $document = new \DOMDocument();
        $document->loadHTML($page->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $plus = AmdRentOption::where('kind', 'plus')->sole();
        $input = $document->getElementById('booking-extra-'.$plus->id);
        $this->assertTrue($input->hasAttribute('disabled'));
        $this->assertFalse($input->hasAttribute('checked'));
        $this->get(route('public-cars.booking.create', ['pricelist' => 1] + $this->demoPeriod()))->assertRedirect();
        $this->assertDatabaseCount('public_bookings', 0);
        $this->assertDatabaseCount('amd_rent_enquiries', 0);
    }

    public function test_real_vehicle_details_keep_the_existing_booking_flow(): void
    {
        $this->seedProtections();
        $this->get(route('public-cars.show', ['pricelist' => 2] + $this->demoPeriod()))->assertOk()
            ->assertSee('Prenota questa auto')->assertDontSee('Anteprima delle protezioni');
        $this->get(route('public-cars.booking.create', ['pricelist' => 2] + $this->demoPeriod()))->assertOk()
            ->assertSee('Protezione standard inclusa')->assertDontSee('AMD Plus');
    }

    protected function seedProtections(): void
    {
        $this->artisan('amd-rent:demo-insurance', ['renter' => '2', '--min-cars' => '1', '--apply' => true])->assertSuccessful();
    }

    public function test_demo_fleet_is_completed_once_and_every_demo_vehicle_is_visible(): void
    {
        $product = \App\Models\VehicleProduct::create(['name' => 'Citycar dimostrativa']);
        DB::table('vehicles')->where('id', 1)->update(['vehicle_product_id' => $product->id]);
        $this->artisan('amd-rent:demo-insurance', ['renter' => '2', '--apply' => true])->assertSuccessful();
        $this->artisan('amd-rent:demo-insurance', ['renter' => '2', '--apply' => true])->assertSuccessful();
        $this->assertDatabaseCount('vehicles', 4);
        $this->assertDatabaseCount('vehicle_pricelists', 4);
        $this->assertDatabaseCount('amd_rent_options', 2);
        $this->get(route('public-cars.index', $this->demoPeriod()))->assertOk()
            ->assertViewHas('results', fn ($cars) => collect($cars->items())->where('is_demo', true)->count() === 3);
    }

    protected function demoPeriod(): array
    {
        return $this->period(['return_at' => '2026-09-11T10:00']);
    }

    protected function demoDetail(): \Illuminate\Testing\TestResponse
    {
        return $this->get(route('public-cars.show', ['pricelist' => 1] + $this->demoPeriod()));
    }
}
