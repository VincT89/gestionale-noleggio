<?php

namespace Tests\Feature;

use App\Models\Vehicle;
use App\Services\AmdRent\{DemoCleanup, DemoFleet, DemoMap, DemoPickupGroups};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\{DB, File, Http, Mail, Queue, Schema, Storage};
use Mockery;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Support\PublicBookingTestCase;

class AmdRentDemoCleanupTest extends PublicBookingTestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        Schema::table('vehicles', function (Blueprint $table) {
            $table->text('notes')->nullable(); $table->string('color')->nullable(); $table->integer('mileage_current')->nullable();
        });
        Schema::table('locations', function (Blueprint $table) {
            $table->text('notes')->nullable(); $table->string('province')->nullable(); $table->string('postal_code')->nullable();
        });
        Schema::table('vehicle_pricelists', function (Blueprint $table) {
            $table->text('notes')->nullable(); $table->integer('second_driver_daily_cents')->nullable();
        });
        Schema::table('vehicle_assignments', function (Blueprint $table) {
            $table->text('notes')->nullable(); $table->integer('mileage_start')->nullable();
        });
        DB::table('organizations')->insert(['id' => 4, 'name' => 'DEMO AMD Rent', 'type' => 'renter', 'is_active' => true]);
        DB::table('locations')->update(['country_code' => 'IT']);
        $this->fixtureRoot = storage_path('framework/testing/demo-cleanup-'.bin2hex(random_bytes(8)));
        config(['filesystems.disks.demo_cleanup_fixture' => ['driver' => 'local', 'root' => $this->fixtureRoot, 'throw' => true]]);
        Storage::forgetDisk('demo_cleanup_fixture');
        $this->offer(1, 2, vehicle: ['notes' => 'Auto originale da preservare']);
        DB::table('vehicle_assignments')->insert(['vehicle_id' => 1, 'renter_org_id' => 2, 'start_at' => '2026-01-01', 'status' => 'active']);
        DB::table('customers')->insert(['organization_id' => 2, 'name' => 'Cliente originale dimostrativo']);
        DB::table('rentals')->insert(['vehicle_id' => 1, 'organization_id' => 2, 'status' => 'closed',
            'planned_pickup_at' => '2026-09-01 10:00:00', 'planned_return_at' => '2026-09-02 10:00:00', 'actual_return_at' => '2026-09-02 10:00:00']);
        $media = DB::table('media')->insertGetId(['model_type' => Vehicle::class, 'model_id' => 1,
            'collection_name' => 'vehicle_photos', 'name' => 'Foto originale di prova', 'file_name' => 'fixture.png',
            'mime_type' => 'image/png', 'disk' => 'demo_cleanup_fixture', 'conversions_disk' => 'demo_cleanup_fixture']);
        Storage::disk('demo_cleanup_fixture')->put($media.'/fixture.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aVv8AAAAASUVORK5CYII='));
    }

    protected function tearDown(): void
    {
        Mail::assertNothingSent(); Queue::assertNothingPushed(); Http::assertNothingSent();
        Storage::forgetDisk('demo_cleanup_fixture');
        if (isset($this->fixtureRoot)) {
            $resolved = realpath($this->fixtureRoot);
            $parent = realpath(storage_path('framework/testing'));
            if ($resolved && $parent && dirname($resolved) === $parent && str_starts_with(basename($resolved), 'demo-cleanup-')) File::deleteDirectory($resolved);
        }
        parent::tearDown();
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if ($table === 'sqlite_sequence') continue;
            $snapshot[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }
        return $snapshot;
    }

    private function files(): array
    {
        $result = [];
        if (!is_dir($this->fixtureRoot)) return [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->fixtureRoot, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            $path = str_replace('\\', '/', substr($entry->getPathname(), strlen($this->fixtureRoot) + 1));
            $result[$path] = $entry->isLink() ? 'link:'.readlink($entry->getPathname()) : hash_file('sha256', $entry->getPathname());
        }
        ksort($result);
        return $result;
    }

    private function prepare(): void
    {
        $fleet = app(DemoFleet::class);
        $plan = $fleet->preview(4);
        $this->assertSame([], $plan['errors']);
        $fleet->apply(4, [], false, $plan['fingerprint']);
        $this->artisan('amd-rent:demo-insurance', ['renter' => 4, '--apply' => true])->assertSuccessful();
        $map = app(DemoMap::class);
        $map->apply(4, $map->preview(4)['fingerprint']);
        $groups = app(DemoPickupGroups::class);
        $groups->apply(4, $groups->preview(4)['fingerprint']);
    }

    private function demoVehicle(): object
    {
        return DB::table('vehicles')->where('notes', DemoFleet::marker(4, 'vehicle', 1))->first();
    }

    private function assertBlocked(): void
    {
        $before = $this->snapshot(); $files = $this->files();
        $plan = app(DemoCleanup::class)->preview(4);
        $this->assertNotEmpty($plan['errors']);
        $this->artisan('amd-rent:demo-cleanup', ['renter' => 4, '--apply' => true, '--confirm' => $plan['fingerprint']])->assertFailed();
        $this->assertSame($before, $this->snapshot()); $this->assertSame($files, $this->files());
    }

    public function test_preview_and_missing_confirmation_never_change_rows_or_files(): void
    {
        $this->prepare();
        $before = $this->snapshot(); $files = $this->files();
        $plan = app(DemoCleanup::class)->preview(4);
        $this->assertSame([], $plan['errors']);
        $this->assertCount(3, $plan['ids']['vehicles']);
        $this->assertCount(4, $plan['ids']['amd_rent_options']);
        $this->artisan('amd-rent:demo-cleanup', ['renter' => 4])->assertSuccessful();
        $this->artisan('amd-rent:demo-cleanup', ['renter' => 4, '--apply' => true])->assertFailed();
        $this->assertSame($before, $this->snapshot()); $this->assertSame($files, $this->files());
    }

    public function test_cleanup_removes_only_demo_copies_and_preserves_originals_and_accounts(): void
    {
        $originals = $this->snapshot(); $originalFiles = $this->files();
        $this->prepare();
        $user = $this->publisher(4, 'renter');
        $plan = app(DemoCleanup::class)->preview(4);
        $this->assertSame([], $plan['errors']);
        $this->artisan('amd-rent:demo-cleanup', ['renter' => 4, '--apply' => true, '--confirm' => $plan['fingerprint']])->assertSuccessful();
        foreach ($originals as $table => $rows) {
            foreach ($rows as $row) {
                if ($table === 'organizations' && $row['id'] === 4) continue;
                if (isset($row['id'])) $this->assertSame($row, (array) DB::table($table)->find($row['id']), $table.' original changed');
            }
        }
        $this->assertSame($originalFiles, $this->files());
        $this->assertFalse((bool) DB::table('organizations')->where('id', 4)->value('is_active'));
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        foreach ($plan['ids'] as $table => $ids) {
            if ($ids !== []) $this->assertSame(0, DB::table($table)->whereIn('id', $ids)->count(), $table.' demo rows remain');
        }
        $again = app(DemoCleanup::class)->preview(4);
        $this->assertSame([], $again['errors']);
        $this->assertTrue($again['empty']);
    }

    public function test_operational_rentals_even_if_archived_block_the_entire_cleanup(): void
    {
        $this->prepare();
        $vehicle = Vehicle::where('notes', 'like', DemoFleet::marker(4, 'vehicle', '').'%')->firstOrFail();
        DB::table('rentals')->insert(['vehicle_id' => $vehicle->id, 'organization_id' => 4, 'status' => 'closed', 'deleted_at' => now()]);
        $before = $this->snapshot(); $files = $this->files();
        $plan = app(DemoCleanup::class)->preview(4);
        $this->assertNotEmpty($plan['errors']);
        $this->artisan('amd-rent:demo-cleanup', ['renter' => 4, '--apply' => true, '--confirm' => $plan['fingerprint']])->assertFailed();
        $this->assertSame($before, $this->snapshot()); $this->assertSame($files, $this->files());
    }

    public function test_real_profiles_and_invalid_identifiers_are_rejected_without_changes(): void
    {
        $before = $this->snapshot(); $files = $this->files();
        foreach (['1', '2', '999', '0', '04', '4 OR 1=1'] as $renter) {
            $this->artisan('amd-rent:demo-cleanup', ['renter' => $renter, '--apply' => true, '--confirm' => str_repeat('a', 64)])->assertFailed();
        }
        $this->assertSame($before, $this->snapshot()); $this->assertSame($files, $this->files());
    }

    public function test_a_real_vehicle_priced_to_the_demo_blocks_cleanup(): void
    {
        $this->prepare();
        DB::table('vehicle_pricelists')->insert(['vehicle_id' => 1, 'renter_org_id' => 4]);
        $this->assertBlocked();
    }

    public function test_a_copy_assigned_to_another_renter_blocks_cleanup(): void
    {
        $this->prepare();
        DB::table('vehicle_assignments')->insert(['vehicle_id' => $this->demoVehicle()->id, 'renter_org_id' => 3, 'start_at' => now()]);
        $this->assertBlocked();
    }

    public function test_shared_pickup_points_and_segments_are_never_deleted(): void
    {
        $this->prepare();
        $delivery = DB::table('public_delivery_locations')->where('organization_id', 4)->first();
        DB::table('public_delivery_locations')->insert(['organization_id' => 3, 'location_id' => 3, 'public_pickup_place_id' => $delivery->public_pickup_place_id]);
        $this->assertBlocked();
        DB::table('public_delivery_locations')->where('organization_id', 3)->where('public_pickup_place_id', $delivery->public_pickup_place_id)->delete();
        DB::table('vehicles')->where('id', 1)->update(['vehicle_product_id' => $this->demoVehicle()->vehicle_product_id]);
        $this->assertBlocked();
    }

    public function test_new_foreign_keys_and_unconstrained_operational_records_block_cleanup(): void
    {
        $this->prepare();
        Schema::create('future_vehicle_references', function (Blueprint $table) {
            $table->id(); $table->foreignId('unusual_link')->constrained('vehicles')->cascadeOnDelete();
        });
        DB::table('future_vehicle_references')->insert(['unusual_link' => $this->demoVehicle()->id]);
        $this->assertBlocked();
        DB::table('future_vehicle_references')->delete();
        DB::table('vehicle_blocks')->insert(['vehicle_id' => $this->demoVehicle()->id, 'start_at' => now()]);
        $this->assertBlocked();
    }

    public function test_customers_and_requests_even_outside_the_demo_block_cleanup(): void
    {
        $this->prepare();
        $id = DB::table('customers')->insertGetId(['organization_id' => 4, 'name' => 'Cliente dimostrativo da verificare', 'deleted_at' => now()]);
        $this->assertBlocked();
        DB::table('customers')->where('id', $id)->delete();
        DB::table('amd_rent_enquiries')->insert(['reference' => 'CON-CLEANUP-FIXTURE', 'type' => 'delivery',
            'customer_name' => 'Cliente dimostrativo', 'email' => 'fixture@example.test', 'phone' => '+390000000000',
            'booking_context' => json_encode(['car' => ['vehicle_id' => $this->demoVehicle()->id]], JSON_THROW_ON_ERROR)]);
        $this->assertBlocked();
    }

    public function test_demo_options_referencing_real_vehicles_and_unmarked_photos_block_cleanup(): void
    {
        $this->prepare();
        $id = DB::table('amd_rent_options')->insertGetId(['organization_id' => 4, 'vehicle_id' => 1, 'kind' => 'service', 'name' => 'Da verificare']);
        $this->assertBlocked();
        DB::table('amd_rent_options')->where('id', $id)->delete();
        DB::table('media')->insert(['model_type' => Vehicle::class, 'model_id' => $this->demoVehicle()->id,
            'collection_name' => 'vehicle_documents', 'name' => 'Documento da verificare', 'file_name' => 'document.pdf', 'mime_type' => 'application/pdf', 'disk' => 'demo_cleanup_fixture']);
        $this->assertBlocked();
    }

    public function test_unknown_records_with_demo_in_their_name_or_similar_markers_are_not_classified_as_copies(): void
    {
        $this->prepare();
        $this->offer(90, 3, vehicle: ['notes' => 'AMDXRENTXDEMOXV1:4:vehicle:1']);
        $plan = app(DemoCleanup::class)->preview(4);
        $this->assertSame([], $plan['errors']);
        $this->assertNotContains(90, $plan['ids']['vehicles']);
        DB::table('public_pickup_places')->insert(['name' => 'DEMO 4 - Sede da verificare', 'identity_key' => str_repeat('b', 64), 'kind' => 'location']);
        $this->assertBlocked();
    }

    public function test_changes_to_settings_files_or_the_application_invalidate_confirmation(): void
    {
        $this->prepare();
        $service = app(DemoCleanup::class);
        $original = $service->preview(4);
        DB::table('amd_rent_options')->where('organization_id', 4)->where('kind', 'service')->update(['price_cents' => 1234]);
        $before = $this->snapshot(); $files = $this->files();
        $this->artisan('amd-rent:demo-cleanup', ['renter' => 4, '--apply' => true, '--confirm' => $original['fingerprint']])->assertFailed();
        $this->assertSame($before, $this->snapshot()); $this->assertSame($files, $this->files());
        $original = $service->preview(4);
        $file = $original['photos'][0]['manifest']['files'][0];
        Storage::disk($file['disk'])->put($file['path'], 'Contenuto dimostrativo modificato');
        $before = $this->snapshot(); $files = $this->files();
        $this->artisan('amd-rent:demo-cleanup', ['renter' => 4, '--apply' => true, '--confirm' => $original['fingerprint']])->assertFailed();
        $this->assertSame($before, $this->snapshot()); $this->assertSame($files, $this->files());
        $original = $service->preview(4);
        config(['app.url' => 'https://altro-sito.example.test']);
        $this->artisan('amd-rent:demo-cleanup', ['renter' => 4, '--apply' => true, '--confirm' => $original['fingerprint']])->assertFailed();
        $this->assertSame($before, $this->snapshot()); $this->assertSame($files, $this->files());
    }

    public function test_deactivation_has_its_own_confirmation_and_keeps_every_record_and_file(): void
    {
        $this->prepare();
        $purge = app(DemoCleanup::class)->preview(4);
        $deactivate = app(DemoCleanup::class)->preview(4, true);
        $before = $this->snapshot(); $files = $this->files();
        $this->artisan('amd-rent:demo-cleanup', ['renter' => 4, '--deactivate' => true])->assertSuccessful();
        $this->assertSame($before, $this->snapshot());
        $this->artisan('amd-rent:demo-cleanup', ['renter' => 4, '--deactivate' => true, '--apply' => true, '--confirm' => $purge['fingerprint']])->assertFailed();
        $this->assertSame($before, $this->snapshot());
        $this->artisan('amd-rent:demo-cleanup', ['renter' => 4, '--deactivate' => true, '--apply' => true, '--confirm' => $deactivate['fingerprint']])->assertSuccessful();
        $after = $this->snapshot();
        foreach ($after['organizations'] as &$organization) {
            if ($organization['id'] === 4) $organization = $before['organizations'][array_search(4, array_column($before['organizations'], 'id'), true)];
        }
        unset($organization);
        $this->assertSame($before, $after); $this->assertSame($files, $this->files());
        $stable = $this->snapshot();
        $this->artisan('amd-rent:demo-cleanup', ['renter' => 4, '--deactivate' => true, '--apply' => true])->assertSuccessful();
        $this->assertSame($stable, $this->snapshot());
    }

    public function test_missing_storage_blocks_cleanup_without_creating_any_directory(): void
    {
        $this->prepare();
        $before = $this->snapshot();
        $missing = $this->fixtureRoot.'/missing-storage';
        config(['filesystems.disks.demo_cleanup_fixture.root' => $missing]);
        $plan = app(DemoCleanup::class)->preview(4);
        $this->assertNotEmpty($plan['errors']);
        $this->assertDirectoryDoesNotExist($missing);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_local_disk_aliases_cannot_turn_other_media_into_demo_files(): void
    {
        $this->prepare();
        $photo = Media::where('model_id', $this->demoVehicle()->id)->sole();
        config(['filesystems.disks.demo_cleanup_nested' => ['driver' => 'local', 'root' => $this->fixtureRoot.'/'.$photo->id, 'throw' => true]]);
        $id = DB::table('media')->insertGetId(['model_type' => Vehicle::class, 'model_id' => 1,
            'collection_name' => 'vehicle_photos', 'name' => 'Foto originale annidata', 'file_name' => 'original.png', 'mime_type' => 'image/png',
            'disk' => 'demo_cleanup_nested', 'conversions_disk' => 'demo_cleanup_nested']);
        Storage::disk('demo_cleanup_nested')->put($id.'/original.png', 'Originale da conservare');
        $this->assertBlocked();
        $this->assertSame('Originale da conservare', Storage::disk('demo_cleanup_nested')->get($id.'/original.png'));
        Storage::forgetDisk('demo_cleanup_nested');
    }

    public function test_custom_database_triggers_block_automatic_changes(): void
    {
        $this->prepare();
        DB::unprepared('CREATE TRIGGER fixture_cleanup_trigger BEFORE DELETE ON vehicles BEGIN SELECT RAISE(ABORT, \'Fixture trigger\'); END');
        $this->assertBlocked();
    }

    public function test_database_failure_rolls_back_all_rows_before_any_photo_is_removed(): void
    {
        $this->prepare();
        $plan = app(DemoCleanup::class)->preview(4);
        $before = $this->snapshot(); $files = $this->files();
        DB::connection()->beforeExecuting(function ($query) {
            if (preg_match('/^delete from ["`]vehicles["`]/i', $query)) throw new RuntimeException('Errore simulato prima delle auto.');
        });
        $this->artisan('amd-rent:demo-cleanup', ['renter' => 4, '--apply' => true, '--confirm' => $plan['fingerprint']])->assertFailed();
        $this->assertSame($before, $this->snapshot()); $this->assertSame($files, $this->files());
    }

    private function leavePendingPhotos(): array
    {
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('delete')->once()->andReturn(false);
        $service = new DemoCleanup($filesystem);
        $plan = $service->preview(4);
        try {
            $service->apply(4, $plan['fingerprint']);
            $this->fail('La foto non eliminabile deve essere segnalata.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('resta in sospeso', $exception->getMessage());
        }
        return $plan;
    }

    public function test_photo_failures_are_retryable_and_mysql_json_key_order_is_supported(): void
    {
        $this->prepare();
        $original = $this->files();
        $plan = $this->leavePendingPhotos();
        $this->assertSame($original, $this->files());
        $this->assertDatabaseMissing('vehicles', ['id' => $plan['ids']['vehicles'][0]]);
        $media = Media::whereIn('id', $plan['ids']['media'])->sole();
        $reorder = function ($value) use (&$reorder) {
            if (!is_array($value)) return $value;
            foreach ($value as &$item) $item = $reorder($item);
            if (!array_is_list($value)) ksort($value);
            return $value;
        };
        DB::table('media')->where('id', $media->id)->update(['custom_properties' => json_encode($reorder($media->custom_properties), JSON_THROW_ON_ERROR)]);
        $again = app(DemoCleanup::class)->preview(4);
        $this->assertSame([], $again['errors']);
        $this->artisan('amd-rent:demo-cleanup', ['renter' => 4, '--apply' => true, '--confirm' => $again['fingerprint']])->assertSuccessful();
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        $this->assertSame(['1/fixture.png'], array_keys($this->files()));
    }

    public function test_forged_pending_manifests_cannot_delete_original_photos(): void
    {
        $this->prepare();
        $plan = $this->leavePendingPhotos();
        $media = Media::whereIn('id', $plan['ids']['media'])->sole();
        $properties = $media->custom_properties;
        $properties['amd_rent_demo_cleanup']['files'][0]['path'] = '1/fixture.png';
        DB::table('media')->where('id', $media->id)->update(['custom_properties' => json_encode($properties, JSON_THROW_ON_ERROR)]);
        $this->assertBlocked();
        $this->assertFileExists($this->fixtureRoot.'/1/fixture.png');
    }

    public function test_database_failure_after_file_removal_can_be_completed_with_a_new_preview(): void
    {
        $this->prepare();
        $plan = app(DemoCleanup::class)->preview(4);
        $fail = true;
        DB::connection()->beforeExecuting(function ($query) use (&$fail) {
            if ($fail && preg_match('/^delete from ["`]media["`]/i', $query)) {
                $fail = false;
                throw new RuntimeException('Errore simulato dopo il file.');
            }
        });
        $this->artisan('amd-rent:demo-cleanup', ['renter' => 4, '--apply' => true, '--confirm' => $plan['fingerprint']])->assertFailed();
        $this->assertSame(['1/fixture.png'], array_keys($this->files()));
        $this->assertDatabaseCount('media', 2);
        $again = app(DemoCleanup::class)->preview(4);
        $this->assertSame([], $again['errors']);
        $this->artisan('amd-rent:demo-cleanup', ['renter' => 4, '--apply' => true, '--confirm' => $again['fingerprint']])->assertSuccessful();
        $this->assertDatabaseCount('media', 1);
    }

    public function test_a_new_operational_reference_after_preview_invalidates_the_entire_apply(): void
    {
        $this->prepare();
        $plan = app(DemoCleanup::class)->preview(4);
        DB::table('rentals')->insert(['vehicle_id' => $this->demoVehicle()->id, 'organization_id' => 3, 'status' => 'reserved']);
        $before = $this->snapshot(); $files = $this->files();
        $this->artisan('amd-rent:demo-cleanup', ['renter' => 4, '--apply' => true, '--confirm' => $plan['fingerprint']])->assertFailed();
        $this->assertSame($before, $this->snapshot()); $this->assertSame($files, $this->files());
    }

    public function test_modified_vehicle_markers_or_vin_block_cleanup(): void
    {
        $this->prepare();
        $vehicle = $this->demoVehicle();
        DB::table('vehicles')->where('id', $vehicle->id)->update(['notes' => $vehicle->notes.' - modificato']);
        $this->assertBlocked();
        DB::table('vehicles')->where('id', $vehicle->id)->update(['notes' => $vehicle->notes, 'vin' => 'VIN-DA-VERIFICARE']);
        $this->assertBlocked();
    }

    public function test_original_rental_linked_to_a_demo_pricelist_in_a_snapshot_blocks_cleanup(): void
    {
        $this->prepare();
        $price = DB::table('vehicle_pricelists')->where('renter_org_id', 4)->first();
        DB::table('public_bookings')->insert(['reference' => 'AMD-CLEANUP-FIXTURE', 'request_hash' => str_repeat('c', 64),
            'rental_id' => 1, 'organization_id' => 2, 'public_rental_offer_id' => 1,
            'first_name' => 'Cliente', 'last_name' => 'Dimostrativo', 'email' => 'cliente@example.test', 'phone' => '+390000000000',
            'pickup_at' => '2026-09-10 10:00:00', 'return_at' => '2026-09-13 10:00:00', 'total_cents' => 10000, 'deposit_cents' => 0,
            'payment_method' => 'pay_at_pickup', 'quote_snapshot' => json_encode(['id' => $price->id], JSON_THROW_ON_ERROR), 'accepted_at' => now()]);
        $this->assertBlocked();
    }

    public function test_files_added_after_a_partial_failure_require_manual_review(): void
    {
        $this->prepare();
        $plan = $this->leavePendingPhotos();
        Storage::disk('demo_cleanup_fixture')->put($plan['ids']['media'][0].'/nuovo-file.txt', 'File da verificare');
        $this->assertBlocked();
    }

    public function test_symbolic_links_are_never_followed_by_photo_cleanup(): void
    {
        $this->prepare();
        $photo = Media::where('model_id', $this->demoVehicle()->id)->sole();
        if (!@symlink($this->fixtureRoot.'/1', $this->fixtureRoot.'/'.$photo->id.'/symlink-originale')) {
            $this->markTestSkipped('Il sistema non permette alla fixture di creare collegamenti simbolici.');
        }
        $this->assertBlocked();
        $this->assertFileExists($this->fixtureRoot.'/1/fixture.png');
    }

    public function test_unsupported_photo_storage_blocks_purge_but_allows_reviewing_deactivation(): void
    {
        $this->prepare();
        config(['filesystems.disks.demo_cleanup_fixture.driver' => 's3']);
        $plan = app(DemoCleanup::class)->preview(4);
        $this->assertNotEmpty($plan['errors']);
        $deactivate = app(DemoCleanup::class)->preview(4, true);
        $this->assertSame([], $deactivate['errors']);
    }

    public function test_public_search_hides_the_demo_after_deactivation_and_preserves_real_offers(): void
    {
        $this->prepare();
        $search = app(\App\Domain\Rentals\PublicVehicleSearch::class);
        $filters = $this->period(['driver_age_band' => 'over_21']);
        $demo = fn () => $search->search(\App\Models\VehiclePricelist::forPublicRental()->where('renter_org_id', 4), $filters);
        $real = fn () => $search->search(\App\Models\VehiclePricelist::forPublicRental()->where('renter_org_id', 2), $filters)->all();
        $this->assertCount(3, $demo()); $originals = $real(); $this->assertNotEmpty($originals);
        $plan = app(DemoCleanup::class)->preview(4, true);
        $this->artisan('amd-rent:demo-cleanup', ['renter' => 4, '--deactivate' => true, '--apply' => true, '--confirm' => $plan['fingerprint']])->assertSuccessful();
        $this->assertCount(0, $demo()); $this->assertSame($originals, $real());
    }

    public function test_unregistered_files_and_reference_image_directories_are_preserved(): void
    {
        $this->prepare();
        $photo = Media::where('model_id', $this->demoVehicle()->id)->sole();
        Storage::disk('demo_cleanup_fixture')->put($photo->id.'/non-registrato.txt', 'File da conservare');
        $this->assertBlocked();
        Storage::disk('demo_cleanup_fixture')->delete($photo->id.'/non-registrato.txt');
        config(['filesystems.disks.car_models.root' => $this->fixtureRoot.'/'.$photo->id]);
        $this->assertBlocked();
    }

    public function test_registered_conversions_are_included_with_their_exact_file_hashes(): void
    {
        $this->prepare();
        $photo = Media::where('model_id', $this->demoVehicle()->id)->sole();
        $photo->generated_conversions = ['thumb' => true]; $photo->save();
        $path = $photo->id.'/conversions/fixture-thumb.png';
        Storage::disk('demo_cleanup_fixture')->put($path, Storage::disk('demo_cleanup_fixture')->get($photo->id.'/fixture.png'));
        $plan = app(DemoCleanup::class)->preview(4);
        $this->assertSame([], $plan['errors']);
        $this->assertContains($path, array_column($plan['photos'][0]['manifest']['files'], 'path'));
        $this->artisan('amd-rent:demo-cleanup', ['renter' => 4, '--apply' => true, '--confirm' => $plan['fingerprint']])->assertSuccessful();
        $this->assertSame(['1/fixture.png'], array_keys($this->files()));
    }
}
