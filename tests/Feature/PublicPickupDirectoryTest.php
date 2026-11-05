<?php

namespace Tests\Feature;

use App\Models\{Location, PublicDeliveryLocation, PublicPickupPlace};
use Illuminate\Support\Facades\DB;
use Tests\Support\PublicBookingTestCase;

class PublicPickupDirectoryTest extends PublicBookingTestCase
{
    private function landmark(int $organization, string $name, string $kind = 'airport', string $city = 'Bari'): PublicPickupPlace
    {
        $fields = ['name' => $name, 'kind' => $kind, 'city' => $city, 'country_code' => 'IT'];
        $place = PublicPickupPlace::create($fields + ['identity_key' => PublicPickupPlace::identity($fields)]);
        $location = Location::create(['organization_id' => $organization] + $place->only(['name', 'city', 'country_code']));
        PublicDeliveryLocation::create(['organization_id' => $organization, 'public_pickup_place_id' => $place->id,
            'location_id' => $location->id, 'is_active' => true]);
        return $place;
    }

    public function test_home_selector_only_lists_the_three_bari_groups_without_legacy_cities_or_business_names(): void
    {
        $a = PublicDeliveryLocation::forLocation(Location::findOrFail(1));
        $b = PublicDeliveryLocation::forLocation(Location::findOrFail(2));
        $b->place->update(['city' => ' BARI ']);
        $airport = $this->landmark(1, 'Aeroporto dimostrativo');
        $station = $this->landmark(2, 'Stazione dimostrativa', 'station');
        $area = $this->landmark(2, 'Zona dimostrativa', 'area', 'ADELFIA');
        $this->landmark(2, 'Aeroporto dimostrativo fuori Bari', 'airport', 'BRINDISI');

        $page = $this->get(route('public-cars.index'))->assertOk()
            ->assertDontSee($a->place->name)->assertDontSee($b->place->name)
            ->assertDontSee($airport->name)->assertDontSee($station->name)->assertDontSee($area->name)
            ->assertSee('Bari Aeroporto')->assertSee('Bari Porto')->assertSee('Bari Stazione')
            ->assertDontSee('ADELFIA')->assertDontSee('BRINDISI')->assertDontSee('Tutti i punti di ritiro')
            ->assertViewHas('destinations', fn ($items) => $items->pluck('value')->all() === ['group:bari-airport', 'group:bari-port', 'group:bari-station']);
        $this->assertStringContainsString('name="destination"', $page->getContent());
        $page->assertSee('id="search-request-delivery"', false)->assertSee('id="search-delivery-address"', false);
        $this->assertSame('ADELFIA', $area->fresh()->city);
        $this->assertSame(' BARI ', $b->place->fresh()->city);
        $this->assertDatabaseCount('vehicles', 0);
    }

    public function test_city_choice_searches_every_served_point_and_respects_vehicle_availability(): void
    {
        $this->offer(1);
        $this->offer(2, organization: 2);
        DB::table('vehicle_assignments')->insert(['vehicle_id' => 2, 'renter_org_id' => 2, 'start_at' => '2026-01-01']);
        $this->offer(3);
        DB::table('vehicle_blocks')->insert(['vehicle_id' => 3, 'status' => 'active', 'start_at' => '2026-09-01']);
        $legacyPoint = PublicDeliveryLocation::where('organization_id', 1)->first()->public_pickup_place_id;
        $this->get(route('public-cars.index', $this->period(['destination' => 'city:BARI', 'place_id' => $legacyPoint])))
            ->assertOk()->assertViewHas('results', fn ($rows) => $rows->pluck('id')->all() === [1, 2])
            ->assertViewHas('filters', fn ($filters) => $filters['city'] === 'BARI' && $filters['place_id'] === null && !isset($filters['destination']))
            ->assertSee('BARI — Tutti i punti di ritiro');
    }

    public function test_airport_choice_is_exact_and_replaces_a_previous_city(): void
    {
        $this->offer(1);
        $this->offer(2, organization: 2);
        DB::table('vehicle_assignments')->insert(['vehicle_id' => 2, 'renter_org_id' => 2, 'start_at' => '2026-01-01']);
        $airport = $this->landmark(2, 'Aeroporto dimostrativo');
        $this->get(route('public-cars.index', $this->period(['destination' => (string) $airport->id, 'city' => 'Roma'])))
            ->assertOk()->assertViewHas('results', fn ($rows) => $rows->pluck('id')->all() === [2])
            ->assertViewHas('filters', fn ($filters) => $filters['place_id'] === $airport->id && $filters['city'] === null);
    }

    public function test_city_search_keeps_the_correct_pickup_point_through_detail_and_checkout(): void
    {
        DB::table('locations')->where('id', 2)->update(['city' => 'Roma']);
        $this->offer(1, organization: 2);
        DB::table('vehicle_assignments')->insert(['vehicle_id' => 1, 'renter_org_id' => 2, 'start_at' => '2026-01-01']);
        $servedPlace = $this->landmark(2, 'Punto dimostrativo di Bari', 'location', ' Bari ');
        $period = $this->period(['city' => 'Bari']);
        $this->get(route('public-cars.index', $period))->assertOk()
            ->assertViewHas('results', fn ($rows) => $rows->sole()['place_id'] === $servedPlace->id);
        $bookingUrl = route('public-cars.booking.create', ['pricelist' => 1] + $period);
        $this->get(route('public-cars.show', ['pricelist' => 1] + $period))->assertOk()
            ->assertViewHas('car', fn ($car) => $car['place_id'] === $servedPlace->id)->assertSee($bookingUrl);
        $this->get($bookingUrl)->assertOk()->assertViewHas('car', fn ($car) => $car['place_id'] === $servedPlace->id)
            ->assertViewHas('filters', fn ($filters) => $filters['place_id'] === $servedPlace->id)
            ->assertViewHas('browseFilters', fn ($filters) => $filters['city'] === 'Bari' && !isset($filters['place_id']));
        $this->assertDatabaseCount('public_bookings', 0);
    }

    public function test_preview_is_admin_only_and_excludes_inactive_destinations(): void
    {
        $own = PublicDeliveryLocation::forLocation(Location::findOrFail(2));
        PublicDeliveryLocation::forLocation(Location::findOrFail(3));
        $this->landmark(1, 'Punto disattivato', 'location', 'Città disattivata');
        PublicDeliveryLocation::where('organization_id', 1)->update(['is_active' => false]);
        $this->get(route('public-cars.index'))->assertOk()->assertDontSee('Città disattivata');
        $this->actingAs($this->publisher(2, 'renter'))->get(route('public-cars.preview.index'))->assertForbidden();
        $this->flushSession(); $this->actingAs($this->publisher());
        $this->get(route('public-cars.preview.index'))->assertOk()
            ->assertViewHas('destinations', fn ($items) => $items->pluck('value')->all() === ['group:bari-airport', 'group:bari-port', 'group:bari-station'])
            ->assertDontSee('Città disattivata')->assertDontSee($own->place->name);
    }

    public function test_legacy_exact_point_links_still_work_without_listing_the_office_name_on_home(): void
    {
        $point = PublicDeliveryLocation::forLocation(Location::findOrFail(1));
        $this->get(route('public-cars.index', ['place_id' => $point->public_pickup_place_id]))->assertOk()
            ->assertDontSee($point->place->name)->assertSee('Punto di ritiro — Bari')
            ->assertViewHas('filters', fn ($filters) => $filters['place_id'] === $point->public_pickup_place_id);
    }

    public function test_invalid_destination_does_not_silently_search_everywhere_or_reuse_old_filters(): void
    {
        foreach (['', 'city:', 'city:   ', 'not-a-place', ['city:Bari']] as $destination) {
            $this->get(route('public-cars.index', $this->period(['destination' => $destination, 'place_id' => 1])))
                ->assertRedirect(route('public-cars.index'))->assertSessionHasErrors('destination');
        }
    }

    public function test_bari_group_combines_explicitly_classified_points_and_keeps_the_exact_pickup_at_booking(): void
    {
        $this->offer(1);
        $this->offer(2, organization: 2);
        DB::table('vehicle_assignments')->insert(['vehicle_id' => 2, 'renter_org_id' => 2, 'start_at' => '2026-01-01']);
        $airportA = $this->landmark(1, 'Incontro dimostrativo A');
        $airportB = $this->landmark(2, 'Incontro dimostrativo B', city: ' BARI ');
        $this->landmark(1, 'Porto dimostrativo', 'port');
        $this->landmark(2, 'Stazione dimostrativa', 'station');
        $filters = $this->period(['destination' => 'group:bari-airport', 'city' => 'Roma', 'place_id' => 1]);
        $page = $this->get(route('public-cars.index', $filters))->assertOk()
            ->assertViewHas('results', fn ($rows) => $rows->pluck('id')->all() === [1, 2])
            ->assertViewHas('filters', fn ($filters) => $filters['pickup_group'] === 'bari-airport'
                && $filters['city'] === null && $filters['place_id'] === null);
        $this->assertSame([$airportA->id, $airportB->id], $page->viewData('results')->pluck('place_id')->all());
        $group = $this->period(['pickup_group' => 'bari-airport']);
        $this->get(route('public-cars.show', ['pricelist' => 2] + $group))->assertOk()
            ->assertViewHas('car', fn ($car) => $car['place_id'] === $airportB->id);
        $form = $this->get(route('public-cars.booking.create', ['pricelist' => 2] + $group))->assertOk();
        $this->assertSame($airportB->id, $form->viewData('filters')['place_id']);
        $this->post(route('public-cars.booking.store', 2), $form->viewData('filters') + [
            'checkout_token' => $form->viewData('checkoutToken'), 'first_name' => 'Cliente', 'last_name' => 'Dimostrativo',
            'email' => 'group@example.test', 'phone' => '+390000000000', 'accept_summary' => 1,
        ])->assertStatus(303)->assertSessionHasNoErrors();
        $booking = \App\Models\PublicBooking::sole();
        $this->assertSame($airportB->id, $booking->quote_snapshot['place_id']);
        $this->assertSame(2, (int) $booking->organization_id);
        $this->assertSame($booking->quote_snapshot['pickup_location_id'], (int) $booking->rental->pickup_location_id);
        $this->assertSame($airportA->name, $airportA->fresh()->name);
    }

    public function test_group_does_not_infer_membership_from_names_or_ignore_availability(): void
    {
        $this->offer(1);
        $this->offer(2);
        $this->landmark(1, 'Aeroporto Bari nel nome soltanto', 'location');
        $period = $this->period(['destination' => 'group:bari-airport']);
        $this->get(route('public-cars.index', $period))->assertOk()->assertViewHas('results', fn ($rows) => $rows->isEmpty());
        $this->landmark(1, 'Aeroporto di un’altra città', city: 'Roma');
        $this->get(route('public-cars.index', $period))->assertOk()->assertViewHas('results', fn ($rows) => $rows->isEmpty());
        $airport = $this->landmark(1, 'Aeroporto configurato per il test');
        DB::table('vehicle_blocks')->insert(['vehicle_id' => 1, 'status' => 'active', 'start_at' => '2026-09-01']);
        $this->get(route('public-cars.index', $period))->assertOk()->assertViewHas('results', fn ($rows) => $rows->pluck('id')->all() === [2]);
        PublicDeliveryLocation::where('public_pickup_place_id', $airport->id)->update(['is_active' => false]);
        $this->get(route('public-cars.index', $period))->assertOk()->assertViewHas('results', fn ($rows) => $rows->isEmpty());
        $this->get(route('public-cars.index', $this->period(['pickup_group' => 'invented'])))->assertSessionHasErrors('pickup_group');
        $this->get(route('public-cars.index', $this->period(['destination' => 'group:bari-anywhere'])))->assertSessionHasErrors('destination');
    }

    public function test_admin_can_classify_an_existing_point_without_moving_it_or_reassigning_vehicles(): void
    {
        $delivery = PublicDeliveryLocation::forLocation(Location::findOrFail(2));
        $original = $delivery->place->only(['name', 'city', 'address_line', 'identity_key']);
        $this->actingAs($this->publisher(2, 'renter'))->put(route('public-deliveries.update', $delivery), ['is_active' => 1, 'place_kind' => 'port'])->assertForbidden();
        $this->assertSame('location', $delivery->place->fresh()->kind);
        $this->flushSession();
        $this->actingAs($this->publisher())->put(route('public-deliveries.update', $delivery), ['is_active' => 1, 'place_kind' => 'port'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('port', $delivery->place->fresh()->kind);
        $this->assertSame($original, $delivery->place->fresh()->only(array_keys($original)));
        $this->assertDatabaseCount('vehicles', 0);
        $this->assertDatabaseCount('public_pickup_places', 1);
        $this->put(route('public-deliveries.update', $delivery), ['is_active' => 1, 'place_kind' => 'invalid'])->assertSessionHasErrors('place_kind');
    }

    public function test_address_pickup_remains_usable_with_a_bari_group_as_return_destination(): void
    {
        $this->offer();
        $airport = $this->landmark(1, 'Punto aeroporto dimostrativo');
        Location::findOrFail(1)->update(['lat' => 41.12, 'lng' => 16.86]);
        PublicDeliveryLocation::where('public_pickup_place_id', $airport->id)->update([
            'custom_delivery_enabled' => true, 'delivery_origin_location_id' => 1, 'delivery_radius_km' => 20, 'custom_delivery_fee_cents' => 0,
        ]);
        $this->startSession();
        $point = app(\App\Services\Geocoding\PlaceSelection::class)->issue([
            ['label' => 'Indirizzo dimostrativo, Bari', 'lat' => 41.12, 'lng' => 16.86],
        ])[0];
        $filters = $this->period(['request_delivery' => 1, 'delivery_address' => $point['label'], 'delivery_place' => $point['token'], 'pickup_group' => 'bari-airport']);
        $this->get(route('public-cars.index', $filters))->assertOk()->assertViewHas('results', fn ($rows) => $rows->count() === 1);
        $this->get(route('public-cars.booking.create', ['pricelist' => 1] + $filters))->assertOk()
            ->assertViewHas('filters', fn ($filters) => $filters['place_id'] === $airport->id);
    }

    public function test_renter_can_configure_each_bari_area_and_disable_their_own_pickup_points(): void
    {
        $this->offer(1, organization: 2);
        DB::table('vehicle_assignments')->insert(['vehicle_id' => 1, 'renter_org_id' => 2, 'start_at' => '2026-01-01']);
        $this->actingAs($this->publisher(2, 'renter'));
        foreach (\App\Domain\Rentals\PublicPickupDirectory::BARI_GROUPS as $key => $group) {
            $period = $this->period(['destination' => 'group:'.$key]);
            $this->get(route('public-cars.index', $period))->assertOk()->assertViewHas('results', fn ($rows) => $rows->isEmpty());
            $data = ['organization_id' => 2, 'pickup_group' => $key, 'name' => 'Incontro dimostrativo '.$group['kind'],
                'address_line' => 'Indirizzo dimostrativo '.$group['kind'], 'confirm_delivery' => 1];
            $this->post(route('public-deliveries.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
            $place = PublicPickupPlace::where('name', $data['name'])->sole();
            $this->assertSame(['Bari', 'IT', $group['kind']], [$place->city, $place->country_code, $place->kind]);
            $delivery = PublicDeliveryLocation::where('organization_id', 2)->where('public_pickup_place_id', $place->id)->sole();
            $this->assertSame(2, (int) $delivery->location->organization_id);
            $this->assertSame($data['address_line'], $delivery->location->address_line);
            $count = PublicDeliveryLocation::count();
            $this->post(route('public-deliveries.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertDatabaseCount('public_delivery_locations', $count);
            $this->get(route('public-cars.index', $period))->assertOk()
                ->assertViewHas('results', fn ($rows) => $rows->pluck('id')->all() === [1] && $rows->sole()['place_id'] === $place->id);
            $this->put(route('public-deliveries.update', $delivery), ['is_active' => 0])->assertRedirect()->assertSessionHasNoErrors();
            $this->get(route('public-cars.index', $period))->assertOk()->assertViewHas('results', fn ($rows) => $rows->isEmpty());
            $this->put(route('public-deliveries.update', $delivery), ['is_active' => 1])->assertRedirect()->assertSessionHasNoErrors();
        }
        $page = $this->get(route('public-deliveries.index'))->assertOk()
            ->assertSee('Aree di Bari servite')->assertSee('Bari Aeroporto')->assertSee('Bari Porto')->assertSee('Bari Stazione')
            ->assertSee('Incontro dimostrativo airport')->assertDontSee('Tipo del luogo pubblico');
        if ($target = getenv('ADM_ERA_QA_RENDER_DIR')) {
            $root = realpath(storage_path('framework/testing'));
            $resolved = realpath($target);
            $this->assertSame($root, dirname($resolved));
            $this->assertStringStartsWith('booking-ui-', basename($resolved));
            file_put_contents($resolved.'/era.html', $page->getContent());
        }
    }

    public function test_area_activation_requires_a_real_matching_meeting_point_and_cannot_change_another_renter(): void
    {
        $airport = $this->landmark(1, 'Aeroporto dimostrativo condiviso');
        $airport->update(['address_line' => 'Punto d’incontro dimostrativo']);
        $otherDelivery = $airport->deliveries()->sole();
        $this->actingAs($this->publisher(2, 'renter'));
        $data = ['organization_id' => 2, 'pickup_group' => 'bari-airport', 'confirm_delivery' => 1];
        $this->post(route('public-deliveries.store'), $data + ['name' => 'Punto senza indirizzo'])
            ->assertSessionHasErrors('address_line');
        $this->post(route('public-deliveries.store'), array_replace($data, ['pickup_group' => 'bari-port', 'place_id' => $airport->id]))
            ->assertSessionHasErrors('place_id');
        $this->post(route('public-deliveries.store'), array_replace($data, ['organization_id' => 1, 'place_id' => $airport->id]))->assertNotFound();
        $this->post(route('public-deliveries.store'), $data + ['place_id' => $airport->id])->assertRedirect()->assertSessionHasNoErrors();
        $ownDelivery = $airport->deliveries()->where('organization_id', 2)->sole();
        $this->put(route('public-deliveries.update', $otherDelivery), ['is_active' => 0])->assertNotFound();
        $this->put(route('public-deliveries.update', $ownDelivery), ['is_active' => 0])->assertRedirect();
        $this->assertTrue($otherDelivery->fresh()->is_active);
        $this->assertFalse($ownDelivery->fresh()->is_active);
        $this->assertSame('airport', $airport->fresh()->kind);
        $this->assertDatabaseCount('public_pickup_places', 1);
        $this->assertDatabaseCount('vehicles', 0);
    }

    public function test_delivery_settings_reject_disabled_accounts_and_organizations(): void
    {
        $renter = $this->publisher(2, 'renter');
        $renter->update(['is_active' => false]);
        $this->actingAs($renter)->get(route('public-deliveries.index'))->assertForbidden();
        $this->post(route('public-deliveries.store'), ['organization_id' => 2])->assertRedirect(route('login'));
        $renter->update(['is_active' => true]);
        DB::table('organizations')->where('id', 2)->update(['is_active' => false]);
        $this->flushSession();
        $this->actingAs($renter->fresh())->get(route('public-deliveries.index'))->assertForbidden();
        $this->assertDatabaseCount('public_delivery_locations', 0);
    }
}
