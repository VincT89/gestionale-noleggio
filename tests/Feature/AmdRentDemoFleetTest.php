<?php

namespace Tests\Feature;

use App\Domain\Pricing\VehiclePricingService;
use App\Domain\Rentals\{PublicVehiclePhoto, PublicVehicleSearch};
use App\Models\{Location, PublicDeliveryLocation, Vehicle, VehiclePricelist};
use App\Services\AmdRent\DemoFleet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, File, Http, Mail, Queue, Schema, Storage};
use RuntimeException;
use Tests\Support\PublicBookingTestCase;

class AmdRentDemoFleetTest extends PublicBookingTestCase
{
    private string $photoRoot;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        Schema::table('vehicles', function (Blueprint $t) {
            $t->string('color')->nullable(); $t->integer('mileage_current')->nullable(); $t->text('notes')->nullable();
            $t->unique('plate'); $t->unique('vin');
        });
        Schema::table('vehicle_pricelists', function (Blueprint $t) {
            $t->text('notes')->nullable(); $t->integer('second_driver_daily_cents')->nullable();
            $t->unique(['vehicle_id', 'renter_org_id', 'active_flag']);
        });
        Schema::table('locations', function (Blueprint $t) {
            $t->text('notes')->nullable(); $t->string('province')->nullable(); $t->string('postal_code')->nullable();
        });
        Schema::table('vehicle_assignments', function (Blueprint $t) {
            $t->text('notes')->nullable(); $t->integer('mileage_start')->nullable();
        });
        DB::table('organizations')->insert(['id' => 4, 'name' => 'DEMO AMD Rent', 'type' => 'renter', 'is_active' => true]);
        DB::table('locations')->update(['country_code' => 'IT']);
        $this->photoRoot = storage_path('framework/testing/demo-fleet-'.bin2hex(random_bytes(8)));
        config()->set('filesystems.disks.demo_photos', ['driver' => 'local', 'root' => $this->photoRoot, 'throw' => true]);
        config()->set('public_car_images', []);
        Storage::forgetDisk('demo_photos');
    }

    protected function tearDown(): void
    {
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        Storage::forgetDisk('demo_photos');
        /** Only this test's randomly named fixture directory is eligible for cleanup. */
        if (isset($this->photoRoot) && str_starts_with($this->photoRoot, storage_path('framework/testing/demo-fleet-'))) {
            File::deleteDirectory($this->photoRoot);
        }
        parent::tearDown();
    }

    private function sourceFleet(): void
    {
        $this->offer(1, 2, ['base_daily_cents' => 6500, 'second_driver_daily_cents' => 700,
            'weekend_pct' => 10, 'notes' => 'Nota interna da non copiare'],
            ['make' => 'Marca test', 'model' => 'Modello uno', 'mileage_current' => 30000, 'notes' => 'Nota privata originale']);
        $this->offer(2, 3, ['base_daily_cents' => 9000],
            ['make' => 'Marca test', 'model' => 'Modello due', 'fuel_type' => 'diesel', 'transmission' => 'automatic', 'seats' => 7, 'segment' => 'SUV']);
        DB::table('vehicle_pricelist_seasons')->insert(['vehicle_pricelist_id' => 1, 'name' => 'Stagione test',
            'start_mmdd' => '09-01', 'end_mmdd' => '09-30', 'season_pct' => 15, 'weekend_pct_override' => 5, 'priority' => 1]);
        DB::table('vehicle_pricelist_tiers')->insert(['vehicle_pricelist_id' => 1, 'name' => 'Durata test',
            'min_days' => 3, 'max_days' => 7, 'discount_pct' => 10, 'priority' => 1]);
        DB::table('vehicle_assignments')->insert(['vehicle_id' => 1, 'renter_org_id' => 2,
            'start_at' => '2026-01-01', 'status' => 'active', 'notes' => 'Assegnazione operativa']);
        DB::table('rentals')->insert(['organization_id' => 2, 'vehicle_id' => 1, 'status' => 'reserved',
            'planned_pickup_at' => '2026-09-09', 'planned_return_at' => '2026-09-20']);
        DB::table('vehicle_blocks')->insert(['vehicle_id' => 2, 'status' => 'active', 'start_at' => '2026-09-01']);
        DB::table('customers')->insert(['organization_id' => 2, 'name' => 'Cliente originale di prova']);
        DB::table('locations')->where('id', 2)->update(['lat' => 41.1, 'lng' => 16.9]);
        DB::table('public_delivery_locations')->where('organization_id', 2)->update([
            'custom_delivery_enabled' => true, 'delivery_origin_location_id' => 2,
            'delivery_radius_km' => 30, 'custom_delivery_fee_cents' => 2500, 'delivery_area' => 'Area di prova',
        ]);
        $this->photo(1);
    }

    private function photo(int $vehicle, string $collection = 'vehicle_photos'): int
    {
        $id = DB::table('media')->insertGetId(['model_type' => Vehicle::class, 'model_id' => $vehicle,
            'collection_name' => $collection, 'name' => 'Foto di prova', 'file_name' => 'fixture.png',
            'mime_type' => 'image/png', 'disk' => 'demo_photos', 'conversions_disk' => 'demo_photos']);
        Storage::disk('demo_photos')->put($id.'/fixture.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aVv8AAAAASUVORK5CYII='));
        return $id;
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['organizations', 'users', 'locations', 'vehicles', 'vehicle_pricelists', 'vehicle_pricelist_seasons',
            'vehicle_pricelist_tiers', 'vehicle_assignments', 'vehicle_states', 'rentals', 'customers', 'vehicle_blocks',
            'public_rental_offers', 'public_delivery_locations', 'public_pickup_places', 'vehicle_products', 'media',
            'public_bookings', 'amd_rent_enquiries', 'amd_rent_settings'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        return $result;
    }

    private function prepare(bool $withoutPhotos = false, array $choices = []): array
    {
        $fleet = app(DemoFleet::class);
        $plan = $fleet->preview(4, $choices, $withoutPhotos);
        $this->assertSame([], $plan['errors']);
        return $fleet->apply(4, $choices, $withoutPhotos, $plan['fingerprint']);
    }

    public function test_preview_changes_no_database_rows_or_files_and_requires_confirmation(): void
    {
        $this->sourceFleet();
        $before = $this->snapshot();
        $files = Storage::disk('demo_photos')->allFiles();
        $this->artisan('amd-rent:demo-fleet', ['renter' => 4])
            ->expectsOutput('Solo anteprima: nessun dato o file creato.')->assertSuccessful();
        $this->artisan('amd-rent:demo-fleet', ['renter' => 4, '--apply' => true])->assertFailed();
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($files, Storage::disk('demo_photos')->allFiles());
    }

    public function test_complete_copy_is_searchable_with_matching_quotes_and_no_changes_to_originals(): void
    {
        $this->sourceFleet();
        $damage = $this->photo(1, 'vehicle_damage_photos');
        $before = $this->snapshot();
        $result = $this->prepare();
        $this->assertCount(2, $result['vehicles']);
        $this->assertCount(3, $result['locations']);
        $this->assertSame(3, $result['deliveries']);
        $this->assertSame(1, $result['photos']);
        foreach ($before as $table => $rows) {
            foreach ($rows as $row) {
                $this->assertSame($row, (array) DB::table($table)->where('id', $row['id'])->first(), $table.' source changed');
            }
        }
        foreach (['organizations', 'users', 'customers', 'rentals', 'vehicle_blocks', 'public_rental_offers'] as $table) {
            $this->assertSame(count($before[$table]), DB::table($table)->count(), $table.' must not receive rows');
        }
        $copy = Vehicle::findOrFail($result['vehicles'][1]);
        $this->assertNull($copy->vin);
        $this->assertSame(DemoFleet::plate(4, 1), $copy->plate);
        $this->assertNotSame(1, $copy->id);
        $this->assertSame(4, $copy->defaultPickupLocation->organization_id);
        $this->assertSame(0, $copy->rentals()->count());
        $this->assertSame(0, $copy->blocks()->count());
        $this->assertSame(4, $copy->assignments()->sole()->renter_org_id);
        $this->assertStringStartsWith('DEMO 4 - ', $copy->product->name);
        $price = $copy->pricelists()->with(['seasons', 'tiers'])->sole();
        $this->assertSame(700, $price->second_driver_daily_cents);
        $this->assertCount(1, $price->seasons);
        $this->assertCount(1, $price->tiers);
        $start = CarbonImmutable::parse($this->period()['pickup_at']);
        $end = CarbonImmutable::parse($this->period()['return_at']);
        $pricing = app(VehiclePricingService::class);
        $this->assertSame($pricing->quote(VehiclePricelist::findOrFail(1), $start, $end), $pricing->quote($price, $start, $end));
        $photos = app(PublicVehiclePhoto::class);
        $photo = $photos->forVehicle($copy);
        $this->assertNotNull($photo);
        $original = $photos->forVehicle(Vehicle::findOrFail(1));
        $this->assertNotSame($original['path'], $photo['path']);
        $this->assertSame(Storage::disk($original['disk'])->get($original['path']), Storage::disk($photo['disk'])->get($photo['path']));
        $this->assertSame(0, $copy->getMedia('vehicle_damage_photos')->count());
        $this->assertNotNull(DB::table('media')->find($damage));

        $search = app(PublicVehicleSearch::class);
        $scope = VehiclePricelist::forPublicRental()->where('renter_org_id', 4);
        foreach (['Bari', 'Roma'] as $city) {
            $results = $search->search($scope, $this->period(['city' => $city]));
            $this->assertEqualsCanonicalizing(array_values($result['vehicles']), $results->pluck('vehicle_id')->all());
        }
        $filtered = $search->search($scope, $this->period(['transmission' => 'automatic', 'fuel_type' => 'diesel', 'seats' => 7]));
        $this->assertSame([$result['vehicles'][2]], $filtered->pluck('vehicle_id')->all());
        $this->assertCount(1, $search->search($scope, $this->period(['segment' => $copy->product->name])));
        $originalResults = $search->search(VehiclePricelist::forPublicRental()->where('renter_org_id', '!=', 4), $this->period());
        $this->assertCount(0, $originalResults);

        $delivery = PublicDeliveryLocation::where('organization_id', 4)->where('custom_delivery_enabled', true)->sole();
        $this->assertSame(4, $delivery->origin->organization_id);
        $this->assertSame('30.00', $delivery->delivery_radius_km);
        $this->assertSame(2500, $delivery->custom_delivery_fee_cents);
        $this->assertSame('41.1000000', $delivery->origin->lat);
        $this->assertCount(2, $search->search($scope, $this->period(['request_delivery' => true,
            'delivery_point' => ['lat' => 41.1, 'lng' => 16.9, 'label' => 'Punto dimostrativo']])));
    }

    public function test_second_run_never_duplicates_or_resynchronizes_data(): void
    {
        $this->sourceFleet();
        $this->prepare();
        $before = $this->snapshot();
        $files = Storage::disk('demo_photos')->allFiles();
        $this->artisan('amd-rent:demo-fleet', ['renter' => 4, '--apply' => true])->assertSuccessful();
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($files, Storage::disk('demo_photos')->allFiles());
    }

    public function test_price_change_invalidates_the_reviewed_preview(): void
    {
        $this->sourceFleet();
        $plan = app(DemoFleet::class)->preview(4);
        DB::table('vehicle_pricelists')->where('id', 1)->update(['base_daily_cents' => 9999]);
        $before = $this->snapshot();
        $this->artisan('amd-rent:demo-fleet', ['renter' => 4, '--apply' => true, '--confirm' => $plan['fingerprint']])->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_multiple_pricelists_require_an_explicit_choice_and_copy_the_chosen_rules(): void
    {
        $this->sourceFleet();
        $id = DB::table('vehicle_pricelists')->insertGetId(['vehicle_id' => 1, 'renter_org_id' => 1, 'base_daily_cents' => 24000]);
        $fleet = app(DemoFleet::class);
        $plan = $fleet->preview(4);
        $this->assertStringContainsString('più listini attivi', implode(' ', $plan['errors']));
        $before = $this->snapshot();
        $this->artisan('amd-rent:demo-fleet', ['renter' => 4, '--apply' => true, '--confirm' => $plan['fingerprint']])->assertFailed();
        $this->assertSame($before, $this->snapshot());
        $result = $this->prepare(choices: [1 => $id]);
        $this->assertSame(24000, Vehicle::findOrFail($result['vehicles'][1])->pricelists()->sole()->base_daily_cents);
    }

    public function test_missing_price_does_not_silently_drop_a_vehicle_or_insert_a_default_price(): void
    {
        $this->sourceFleet();
        DB::table('public_rental_offers')->where('pricelist_id', 2)->delete();
        DB::table('vehicle_pricelists')->where('id', 2)->delete();
        $before = $this->snapshot();
        $this->artisan('amd-rent:demo-fleet', ['renter' => 4])->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_draft_prices_are_only_published_on_demo_when_explicitly_chosen(): void
    {
        $this->sourceFleet();
        DB::table('vehicle_pricelists')->where('id', 2)->update(['status' => 'draft', 'active_flag' => null, 'is_active' => false]);
        $fleet = app(DemoFleet::class);
        $this->assertNotEmpty($fleet->preview(4)['errors']);
        $result = $this->prepare(choices: [2 => 2]);
        $this->assertSame('draft', VehiclePricelist::findOrFail(2)->status);
        $this->assertSame('active', Vehicle::findOrFail($result['vehicles'][2])->pricelists()->sole()->status);
    }

    public function test_missing_files_block_copy_unless_photos_are_explicitly_omitted(): void
    {
        $this->sourceFleet();
        Storage::disk('demo_photos')->delete('1/fixture.png');
        $plan = app(DemoFleet::class)->preview(4);
        $this->assertStringContainsString('file assente o non copiabile', implode(' ', $plan['errors']));
        $before = $this->snapshot();
        $this->artisan('amd-rent:demo-fleet', ['renter' => 4, '--apply' => true, '--confirm' => $plan['fingerprint']])->assertFailed();
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, $this->prepare(withoutPhotos: true)['photos']);
    }

    public function test_destination_file_collision_rolls_back_every_row_and_preserves_existing_files(): void
    {
        $this->sourceFleet();
        $this->photo(2);
        $plan = app(DemoFleet::class)->preview(4);
        $this->assertSame([], $plan['errors']);
        Storage::disk('demo_photos')->put('4/fixture.png', 'Existing file - must not overwrite');
        $before = $this->snapshot();
        $files = Storage::disk('demo_photos')->allFiles();
        $this->artisan('amd-rent:demo-fleet', ['renter' => 4, '--apply' => true, '--confirm' => $plan['fingerprint']])->assertFailed();
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($files, Storage::disk('demo_photos')->allFiles());
        $this->assertSame('Existing file - must not overwrite', Storage::disk('demo_photos')->get('4/fixture.png'));
    }

    public function test_real_used_or_nonempty_organizations_are_refused(): void
    {
        $this->sourceFleet();
        $this->artisan('amd-rent:demo-fleet', ['renter' => 2])->assertFailed();
        DB::table('organizations')->where('id', 4)->update(['type' => 'admin']);
        $this->artisan('amd-rent:demo-fleet', ['renter' => 4])->assertFailed();
        DB::table('organizations')->where('id', 4)->update(['type' => 'renter']);
        DB::table('locations')->insert(['organization_id' => 4, 'name' => 'Sede esistente', 'city' => 'Città test']);
        $this->artisan('amd-rent:demo-fleet', ['renter' => 4])->assertFailed();
        DB::table('locations')->where('organization_id', 4)->delete();
        DB::table('customers')->insert(['organization_id' => 4, 'name' => 'Cliente test']);
        $this->artisan('amd-rent:demo-fleet', ['renter' => 4])->assertFailed();
    }

    public function test_archived_inactive_and_existing_demo_vehicles_are_not_source_candidates(): void
    {
        $this->sourceFleet();
        $this->offer(3, 1, vehicle: ['deleted_at' => now()]);
        $this->offer(4, 1, vehicle: ['is_active' => false]);
        $this->offer(5, 1, vehicle: ['notes' => DemoFleet::marker(99, 'vehicle', 1)]);
        $plan = app(DemoFleet::class)->preview(4);
        $this->assertSame([1, 2], array_column($plan['vehicles'], 'source_id'));
    }

    public function test_uncategorized_vehicle_is_reported_without_inventing_a_category(): void
    {
        $this->sourceFleet();
        DB::table('vehicles')->where('id', 1)->update(['segment' => null]);
        $plan = app(DemoFleet::class)->preview(4);
        $this->assertNull($plan['vehicles'][0]['product_key']);
        $this->assertStringContainsString('categoria assente', implode(' ', $plan['warnings']));
    }

    public function test_inconsistent_delivery_and_invalid_geographic_coverage_are_reported(): void
    {
        $this->sourceFleet();
        DB::table('public_delivery_locations')->where('organization_id', 2)->update(['delivery_radius_km' => null]);
        $plan = app(DemoFleet::class)->preview(4);
        $this->assertStringContainsString('copertura o prezzo', implode(' ', $plan['errors']));
        DB::table('public_delivery_locations')->where('organization_id', 2)->update(['location_id' => 3]);
        $this->assertStringContainsString('collegamenti originali incoerenti', implode(' ', app(DemoFleet::class)->preview(4)['errors']));
    }

    public function test_invalid_command_arguments_do_not_write(): void
    {
        $this->sourceFleet();
        $before = $this->snapshot();
        foreach ([['renter' => '4;anything'], ['renter' => 4, '--price' => ['1:1', '1:2']],
            ['renter' => 4, '--price' => ['1:2']], ['renter' => 4, '--price' => ['999:1']]] as $arguments) {
            $this->artisan('amd-rent:demo-fleet', $arguments)->assertFailed();
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_full_renter_name_is_accepted_only_when_unambiguous(): void
    {
        $this->sourceFleet();
        $before = $this->snapshot();
        $this->artisan('amd-rent:demo-fleet', ['renter' => 'DEMO AMD Rent'])->assertSuccessful();
        $this->assertSame($before, $this->snapshot());
        DB::table('organizations')->insert(['name' => 'DEMO AMD Rent', 'type' => 'renter', 'is_active' => true]);
        $before = $this->snapshot();
        $this->artisan('amd-rent:demo-fleet', ['renter' => 'DEMO AMD Rent'])->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_hosting_panel_arguments_accept_split_names_without_writing(): void
    {
        $this->sourceFleet();
        $before = $this->snapshot();
        $files = Storage::disk('demo_photos')->allFiles();
        $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
        foreach ([['DEMO AMD Rent'], ['DEMO', 'AMD', 'Rent'], ['"DEMO', 'AMD', 'Rent"'],
            ["'DEMO", 'AMD', "Rent'"], ['4'], ['DEMO', 'AMD', 'Rent', '--without-photos', '--price=1:1']] as $arguments) {
            $output = new \Symfony\Component\Console\Output\BufferedOutput;
            $status = $kernel->handle(new \Symfony\Component\Console\Input\ArgvInput(
                ['artisan', 'amd-rent:demo-fleet', ...$arguments]
            ), $output);
            $this->assertSame(0, $status, $text = $output->fetch());
            $this->assertStringContainsString('Solo anteprima: nessun dato o file creato.', $text);
            $this->assertSame($before, $this->snapshot());
            $this->assertSame($files, Storage::disk('demo_photos')->allFiles());
        }
        foreach ([['4', 'parola-inattesa'], ['DEMO', 'AMD', 'Rent', '--apply']] as $arguments) {
            $this->assertSame(1, $kernel->handle(new \Symfony\Component\Console\Input\ArgvInput(
                ['artisan', 'amd-rent:demo-fleet', ...$arguments]
            ), new \Symfony\Component\Console\Output\BufferedOutput));
            $this->assertSame($before, $this->snapshot());
            $this->assertSame($files, Storage::disk('demo_photos')->allFiles());
        }
    }
}
