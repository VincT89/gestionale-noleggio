<?php

namespace Tests\Feature;

use App\Domain\Pricing\VehiclePricingService;
use App\Domain\Rentals\{PublicVehiclePhoto, PublicVehicleSearch};
use App\Models\{Location, PublicDeliveryLocation, Vehicle, VehiclePricelist};
use App\Services\AmdRent\DemoFleet;
use App\Services\AmdRent\DemoMap;
use App\Domain\Rentals\{DeliveryCoverage, PublicBookingService};
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
            /** Match the nullable deposit and extra-km columns from the production migrations. */
            $t->integer('extra_km_cents')->nullable()->change(); $t->integer('deposit_cents')->nullable()->change();
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

    public function test_demo_rate_covers_missing_and_ambiguous_prices_and_preserves_unique_prices(): void
    {
        $this->sourceFleet();
        $this->offer(3, 2, ['base_daily_cents' => 11750]);
        DB::table('vehicle_pricelists')->insert(['vehicle_id' => 1, 'renter_org_id' => 1, 'base_daily_cents' => 24000]);
        DB::table('public_rental_offers')->where('pricelist_id', 2)->delete();
        DB::table('vehicle_pricelists')->where('id', 2)->delete();
        $before = $this->snapshot();
        $fleet = app(DemoFleet::class);
        $plan = $fleet->preview(4, demoDailyCents: 5000, availablePhotos: true);
        $this->assertSame([], $plan['errors']);
        $this->assertSame([true, true, false], array_map(fn ($v) => $v['price']['is_demo'], $plan['vehicles']));
        $this->assertSame($before, $this->snapshot());
        $output = new \Symfony\Component\Console\Output\BufferedOutput;
        $status = $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->handle(
            new \Symfony\Component\Console\Input\ArgvInput(['artisan', 'amd-rent:demo-fleet', 'DEMO', 'AMD', 'Rent',
                '--demo-daily=50', '--available-photos', '--apply', '--confirm='.$plan['fingerprint']]), $output);
        $this->assertSame(0, $status, $output->fetch());
        $copies = Vehicle::where('notes', 'like', DemoFleet::MARKER.'4:vehicle:%')->orderBy('id')->get();
        $this->assertCount(3, $copies);
        foreach ($copies->take(2) as $vehicle) {
            $price = $vehicle->pricelists()->sole();
            $this->assertSame('DEMO - Tariffa dimostrativa', $price->name);
            $this->assertSame(5000, $price->base_daily_cents);
            $this->assertSame(0, $price->seasons()->count());
            $this->assertSame(0, $price->tiers()->count());
            $quote = app(VehiclePricingService::class)->quote($price,
                CarbonImmutable::parse($this->period()['pickup_at']), CarbonImmutable::parse($this->period()['return_at']));
            $this->assertSame(15000, $quote['total']);
        }
        $this->assertSame(11750, $copies->last()->pricelists()->sole()->base_daily_cents);
        $results = app(PublicVehicleSearch::class)->search(VehiclePricelist::forPublicRental()->where('renter_org_id', 4), $this->period());
        $this->assertEqualsCanonicalizing($copies->modelKeys(), $results->pluck('vehicle_id')->all());
        foreach ($before as $table => $rows) {
            foreach ($rows as $row) {
                $this->assertSame($row, (array) DB::table($table)->where('id', $row['id'])->first(), $table.' source changed');
            }
        }
    }

    public function test_available_photos_preserves_valid_photos_and_falls_back_to_reference_images(): void
    {
        $this->sourceFleet();
        $missing = $this->photo(2);
        Storage::disk('demo_photos')->delete($missing.'/fixture.png');
        config()->set('filesystems.disks.car_models', ['driver' => 'local', 'root' => $this->photoRoot.'/references', 'throw' => true]);
        Storage::forgetDisk('car_models');
        Storage::disk('car_models')->put('reference.png', Storage::disk('demo_photos')->get('1/fixture.png'));
        config()->set('public_car_images', [['makes' => ['Marca test'], 'models' => ['Modello due'],
            'mime_type' => 'image/png', 'file' => 'reference.png']]);
        $before = $this->snapshot();
        $fleet = app(DemoFleet::class);
        $plan = $fleet->preview(4, availablePhotos: true);
        $this->assertSame([], $plan['errors']);
        $this->assertStringContainsString('Foto esclusa dalla copia', implode(' ', $plan['warnings']));
        $this->assertSame($before, $this->snapshot());
        $result = $fleet->apply(4, [], false, $plan['fingerprint'], availablePhotos: true);
        $this->assertSame(1, $result['photos']);
        $photos = app(PublicVehiclePhoto::class);
        $actual = $photos->forVehicle(Vehicle::findOrFail($result['vehicles'][1]));
        $reference = $photos->forVehicle(Vehicle::findOrFail($result['vehicles'][2]));
        $this->assertFalse($actual['is_reference']);
        $this->assertNotSame('1/fixture.png', $actual['path']);
        $this->assertSame(Storage::disk('demo_photos')->get('1/fixture.png'), Storage::disk($actual['disk'])->get($actual['path']));
        $this->assertTrue($reference['is_reference']);
        $this->assertSame('reference.png', $reference['path']);
        $this->assertSame(0, Vehicle::findOrFail($result['vehicles'][2])->getMedia('vehicle_photos')->count());
        $this->assertNotNull(DB::table('media')->find($missing));
    }

    public function test_explicit_pricelist_choice_is_not_replaced_by_a_demo_rate(): void
    {
        $this->sourceFleet();
        DB::table('vehicle_pricelists')->insert(['vehicle_id' => 1, 'renter_org_id' => 1, 'base_daily_cents' => 24000]);
        $fleet = app(DemoFleet::class);
        $plan = $fleet->preview(4, [1 => 1], demoDailyCents: 5000);
        $this->assertSame([], $plan['errors']);
        $this->assertFalse($plan['vehicles'][0]['price']['is_demo']);
        $this->assertSame(6500, $plan['vehicles'][0]['price']['attributes']['base_daily_cents']);
        $invalid = $fleet->preview(4, [1 => 2], demoDailyCents: 5000);
        $this->assertNotEmpty($invalid['errors']);
        $this->assertNull($invalid['vehicles'][0]['price']);
    }

    public function test_demo_options_are_validated_and_bound_to_the_reviewed_preview(): void
    {
        $this->sourceFleet();
        $before = $this->snapshot();
        foreach (['0', '-50', '50.005', '1e2', '', '1000000', '50abc'] as $amount) {
            $this->artisan('amd-rent:demo-fleet', ['renter' => 4, '--demo-daily' => $amount])->assertFailed();
        }
        $this->artisan('amd-rent:demo-fleet', ['renter' => 4, '--without-photos' => true, '--available-photos' => true])->assertFailed();
        $plan = app(DemoFleet::class)->preview(4, demoDailyCents: 5000, availablePhotos: true);
        foreach ([['--demo-daily' => '60', '--available-photos' => true], ['--demo-daily' => '50']] as $options) {
            $this->artisan('amd-rent:demo-fleet', $options + ['renter' => 4, '--apply' => true, '--confirm' => $plan['fingerprint']])->assertFailed();
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_product_and_legacy_segment_are_both_linked_to_searchable_demo_categories(): void
    {
        $this->sourceFleet();
        $product = DB::table('vehicle_products')->insertGetId(['name' => 'Categoria di prova', 'name_key' => 'categoria di prova']);
        DB::table('vehicles')->where('id', 1)->update(['vehicle_product_id' => $product, 'segment' => null]);
        $result = $this->prepare();
        $search = app(PublicVehicleSearch::class);
        foreach ([1 => 'Categoria di prova', 2 => 'SUV'] as $source => $category) {
            $copy = Vehicle::findOrFail($result['vehicles'][$source]);
            $this->assertSame('DEMO 4 - '.$category, $copy->product->name);
            $this->assertNotSame($product, $copy->vehicle_product_id);
            $results = $search->search(VehiclePricelist::forPublicRental()->where('renter_org_id', 4),
                $this->period(['segment' => $copy->product->name]));
            $this->assertSame([$copy->id], $results->pluck('vehicle_id')->all());
        }
        $this->assertSame($product, Vehicle::findOrFail(1)->vehicle_product_id);
    }

    public function test_existing_demo_map_configuration_is_previewed_and_does_not_duplicate_or_change_originals(): void
    {
        $this->sourceFleet();
        $this->prepare();
        $before = $this->snapshot();
        $map = app(DemoMap::class);
        $plan = $map->preview(4);
        $this->artisan('amd-rent:demo-fleet', ['renter' => 4, '--enable-demo-map' => true])->assertSuccessful();
        $this->artisan('amd-rent:demo-fleet', ['renter' => 4, '--enable-demo-map' => true, '--apply' => true])->assertFailed();
        $this->assertSame($before, $this->snapshot());
        $this->artisan('amd-rent:demo-fleet', ['renter' => ['DEMO', 'AMD', 'Rent'], '--enable-demo-map' => true,
            '--apply' => true, '--confirm' => $plan['fingerprint']])->assertSuccessful();
        foreach ($before as $table => $rows) {
            $this->assertSame(count($rows), DB::table($table)->count());
            foreach ($rows as $row) {
                if ($table === 'public_delivery_locations' && $row['organization_id'] === 4) continue;
                $this->assertSame($row, (array) DB::table($table)->where('id', $row['id'])->first());
            }
        }
        $after = $this->snapshot();
        $this->artisan('amd-rent:demo-fleet', ['renter' => 4, '--enable-demo-map' => true, '--apply' => true])->assertSuccessful();
        $this->assertSame($after, $this->snapshot());
    }

    public function test_demo_map_accepts_distant_pickup_and_return_but_real_coverage_remains_limited(): void
    {
        $this->sourceFleet();
        $result = $this->prepare();
        $map = app(DemoMap::class);
        $map->apply(4, $map->preview(4)['fingerprint']);
        $search = app(PublicVehicleSearch::class);
        $scope = VehiclePricelist::forPublicRental()->where('renter_org_id', 4);
        foreach ([[41.14, 16.76], [45.46, 9.19], [40.71, -74.00], [-33.86, 151.21]] as [$lat, $lng]) {
            $cars = $search->search($scope, $this->period(['request_delivery' => 1,
                'delivery_point' => ['lat' => $lat, 'lng' => $lng, 'label' => 'Indirizzo di prova'],
                'request_custom_return' => 1, 'return_point' => ['lat' => 51.5, 'lng' => -0.12, 'label' => 'Riconsegna di prova']]));
            $this->assertEqualsCanonicalizing(array_values($result['vehicles']), $cars->pluck('vehicle_id')->all());
            $this->assertTrue($cars->every(fn ($car) => $car['is_demo'] && $car['delivery_fee_cents'] === 0));
        }
        $real = PublicDeliveryLocation::where('organization_id', 2)->firstOrFail();
        $coverage = app(DeliveryCoverage::class);
        $this->assertTrue($coverage->covers($real, ['lat' => 41.1, 'lng' => 16.9]));
        $this->assertFalse($coverage->covers($real, ['lat' => 45.46, 'lng' => 9.19]));
        $demo = PublicDeliveryLocation::where('organization_id', 4)->firstOrFail();
        foreach ([['lat' => 91, 'lng' => 0], ['lat' => 0, 'lng' => 181], ['lat' => 'invalid', 'lng' => 0]] as $invalid) {
            $this->assertFalse($coverage->covers($demo, $invalid));
        }
        $real->delivery_area = DemoMap::NOTICE;
        $this->assertFalse(DemoMap::isDelivery($real));
    }

    public function test_demo_map_rejects_real_targets_mixed_fleets_and_changed_previews(): void
    {
        $this->sourceFleet();
        $this->prepare();
        $before = $this->snapshot();
        $this->artisan('amd-rent:demo-fleet', ['renter' => 2, '--enable-demo-map' => true])->assertFailed();
        $this->assertSame($before, $this->snapshot());
        $plan = app(DemoMap::class)->preview(4);
        DB::table('public_delivery_locations')->where('organization_id', 4)->update(['custom_delivery_fee_cents' => 100]);
        $changed = $this->snapshot();
        $this->artisan('amd-rent:demo-fleet', ['renter' => 4, '--enable-demo-map' => true, '--apply' => true,
            '--confirm' => $plan['fingerprint']])->assertFailed();
        $this->assertSame($changed, $this->snapshot());
        DB::table('vehicle_pricelists')->insert(['vehicle_id' => 1, 'renter_org_id' => 4, 'base_daily_cents' => 10000]);
        $mixed = $this->snapshot();
        $this->artisan('amd-rent:demo-fleet', ['renter' => 4, '--enable-demo-map' => true])->assertFailed();
        $this->assertSame($mixed, $this->snapshot());
    }

    public function test_demo_search_is_labelled_and_cannot_create_bookings_or_payments(): void
    {
        $this->sourceFleet();
        $result = $this->prepare();
        $map = app(DemoMap::class);
        $map->apply(4, $map->preview(4)['fingerprint']);
        $price = VehiclePricelist::where('vehicle_id', $result['vehicles'][1])->sole();
        $car = app(PublicVehicleSearch::class)->search(VehiclePricelist::forPublicRental()->whereKey($price->id), $this->period())->sole();
        $this->get(route('public-cars.show', ['pricelist' => $price->id] + $this->period()))
            ->assertOk()->assertSee('Auto dimostrativa.')->assertDontSee('Prenota questa auto')->assertDontSee('Continua con i tuoi dati');
        $this->get(route('public-cars.booking.create', ['pricelist' => $price->id] + $this->period()))->assertRedirect();
        $before = $this->snapshot();
        try {
            app(PublicBookingService::class)->reserve(['nonce' => 'demo-test', 'pricelist' => $price->id,
                'period' => $this->period(), 'fingerprint' => PublicBookingService::fingerprint($car), 'expires_at' => now()->addHour()->timestamp], []);
            $this->fail('Demo booking was allowed.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertStringContainsString('dimostrativa', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
        $realCar = $car;
        $realCar['is_demo'] = false;
        app(PublicBookingService::class)->assertQuote(['fingerprint' => PublicBookingService::fingerprint($realCar),
            'expires_at' => now()->addHour()->timestamp], $realCar);
    }
}
