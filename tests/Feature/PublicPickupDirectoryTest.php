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

    public function test_home_and_selector_show_cities_and_landmarks_without_business_names(): void
    {
        $a = PublicDeliveryLocation::forLocation(Location::findOrFail(1));
        $b = PublicDeliveryLocation::forLocation(Location::findOrFail(2));
        $b->place->update(['city' => ' BARI ']);
        $airport = $this->landmark(1, 'Aeroporto dimostrativo');
        $station = $this->landmark(2, 'Stazione dimostrativa', 'station');
        $area = $this->landmark(2, 'Zona dimostrativa', 'area');

        $page = $this->get(route('public-cars.index'))->assertOk()
            ->assertDontSee($a->place->name)->assertDontSee($b->place->name)
            ->assertSee($airport->name)->assertSee($station->name)->assertSee($area->name)
            ->assertSee('BARI — Tutti i punti di ritiro')
            ->assertViewHas('destinations', fn ($items) => $items->count() === 4 && $items->where('kind', 'city')->count() === 1);
        $this->assertStringContainsString('name="destination"', $page->getContent());
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
        $bookingUrl = route('public-cars.booking.create', ['pricelist' => 1] + $this->period(['place_id' => $servedPlace->id]));
        $this->get(route('public-cars.show', ['pricelist' => 1] + $period))->assertOk()
            ->assertViewHas('car', fn ($car) => $car['place_id'] === $servedPlace->id)->assertSee($bookingUrl);
        $this->get($bookingUrl)->assertOk()->assertViewHas('car', fn ($car) => $car['place_id'] === $servedPlace->id);
        $this->assertDatabaseCount('public_bookings', 0);
    }

    public function test_inactive_and_foreign_preview_destinations_are_excluded(): void
    {
        $own = PublicDeliveryLocation::forLocation(Location::findOrFail(2));
        PublicDeliveryLocation::forLocation(Location::findOrFail(3));
        $this->landmark(1, 'Punto disattivato', 'location', 'Città disattivata');
        PublicDeliveryLocation::where('organization_id', 1)->update(['is_active' => false]);
        $this->get(route('public-cars.index'))->assertOk()->assertDontSee('Città disattivata');
        $this->actingAs($this->publisher(2, 'renter'))->get(route('public-cars.preview.index'))->assertOk()
            ->assertViewHas('destinations', fn ($items) => $items->pluck('value')->all() === ['city:Bari'])
            ->assertDontSee('Roma')->assertDontSee($own->place->name);
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
}
