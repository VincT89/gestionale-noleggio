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
        Schema::table('vehicle_pricelists', fn (Blueprint $table) => $table->string('notes')->nullable());
        DB::table('organizations')->where('id', 2)->update(['name' => 'DEMO AMD Rent']);
        $this->offer(1, 2, vehicle: ['make' => 'Fiat', 'model' => 'Panda dimostrativa', 'notes' => DemoFleet::marker(2, 'vehicle', 10)]);
        $this->offer(2);
        DB::table('vehicle_assignments')->insert(['vehicle_id' => 1, 'renter_org_id' => 2, 'start_at' => '2026-01-01']);
    }

    public function test_inventory_is_read_only_and_apply_is_idempotent_without_changing_originals(): void
    {
        $vehicle = DB::table('vehicles')->where('id', 2)->first();
        $price = DB::table('vehicle_pricelists')->where('id', 2)->first();
        $this->artisan('amd-rent:demo-insurance')->expectsOutputToContain('Solo lettura')->assertSuccessful();
        $this->assertDatabaseCount('amd_rent_options', 0);
        $this->seedProtections();
        $this->assertDatabaseCount('amd_rent_options', 4);
        $plus = AmdRentOption::where('kind', 'plus')->sole();
        $plus->update(['description' => 'Modifica manuale da conservare', 'published' => false]);
        $this->seedProtections();
        $this->assertDatabaseCount('amd_rent_options', 4);
        $this->assertSame('Modifica manuale da conservare', $plus->fresh()->description);
        $this->assertFalse($plus->fresh()->published);
        $this->assertEquals($vehicle, DB::table('vehicles')->where('id', 2)->first());
        $this->assertEquals($price, DB::table('vehicle_pricelists')->where('id', 2)->first());
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
        $this->assertDatabaseCount('amd_rent_options', 4);
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

    public function test_fifty_existing_demo_cars_receive_the_new_settings_without_creating_more_cars(): void
    {
        for ($id = 3; $id <= 51; $id++) {
            $this->offer($id, 2, vehicle: ['notes' => DemoFleet::marker(2, 'vehicle', $id + 100)]);
        }
        $pricesBefore = DB::table('vehicle_pricelists')->orderBy('id')->get(['id', 'base_daily_cents', 'km_included_per_day', 'extra_km_cents', 'deposit_cents'])->toJson();
        $this->seedProtections();
        $this->assertDatabaseCount('vehicles', 51);
        $this->assertDatabaseCount('vehicle_pricelists', 51);
        $this->assertSame(50, \App\Models\VehiclePricelist::where('renter_org_id', 2)->whereNotNull('driver_age_supplements')->count());
        $this->assertSame($pricesBefore, DB::table('vehicle_pricelists')->orderBy('id')->get(['id', 'base_daily_cents', 'km_included_per_day', 'extra_km_cents', 'deposit_cents'])->toJson());
        $this->get(route('public-cars.show', ['pricelist' => 1] + $this->demoPeriod() + ['driver_age_band' => '18_21']))->assertOk()
            ->assertSee('3.000,00 €')->assertSee('1.500,00 €')->assertSee('10,00 €')->assertSee('Catene da neve — demo')->assertSee('Seggiolino — demo');
    }

    public function test_earlier_untouched_demo_protections_receive_kasko_once(): void
    {
        foreach (['standard' => ['rca' => 150000], 'plus' => ['rca' => 50000]] as $kind => $coverage) {
            AmdRentOption::create(['organization_id' => 2, 'kind' => $kind, 'name' => $kind, 'published' => true,
                'coverage' => $coverage, 'description' => 'Esempio dimostrativo di coperture e franchigie. Non è un’offerta assicurativa.']);
        }
        $this->seedProtections();
        foreach (['standard' => 30000, 'plus' => 15000] as $kind => $factor) {
            $option = AmdRentOption::where('kind', $kind)->sole();
            $this->assertSame($factor, $option->franchise_multipliers['kasko']);
            $this->assertSame(2, $option->revision);
        }
        $this->seedProtections();
        $this->assertSame(2, AmdRentOption::where('kind', 'plus')->sole()->revision);
    }

    public function test_manual_settings_and_later_disabled_supplements_are_preserved(): void
    {
        $price = \App\Models\VehiclePricelist::findOrFail(1);
        $rules = ['18_21' => ['amount_cents' => 2750, 'unit' => 'rental']];
        $price->update(['driver_age_supplements' => $rules]);
        DB::table('vehicles')->where('id', 1)->update(['insurance_kasko_cents' => 0]);
        $this->seedProtections();
        $this->assertSame($rules, $price->fresh()->driver_age_supplements);
        $this->assertSame(0, (int) DB::table('vehicles')->where('id', 1)->value('insurance_kasko_cents'));
        $price->update(['driver_age_supplements' => null]);
        $service = AmdRentOption::where('name', 'Seggiolino — demo')->sole();
        $service->update(['is_available' => false, 'price_cents' => 735]);
        $this->seedProtections();
        $this->assertNull($price->fresh()->driver_age_supplements);
        $this->assertFalse($service->fresh()->is_available);
        $this->assertSame(735, $service->fresh()->price_cents);
        $this->assertDatabaseCount('amd_rent_options', 4);
    }

    public function test_missing_checkout_migration_stops_before_any_data_is_written(): void
    {
        (require database_path('migrations/2026_11_26_120000_add_public_checkout_settings.php'))->down();
        $this->artisan('amd-rent:demo-insurance', ['renter' => '2', '--apply' => true])->assertFailed();
        $this->assertDatabaseCount('amd_rent_options', 0);
        $this->assertDatabaseCount('vehicles', 2);
    }
}
