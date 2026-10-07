<?php

namespace Tests\Feature;

use App\Models\{AmdRentEnquiry, Location, PublicDeliveryLocation, PublicPickupPlace};
use App\Services\Geocoding\PlaceSelection;
use Illuminate\Support\Facades\{Cache, DB, Http};
use Tests\Support\PublicBookingTestCase;

class PublicPickupMapTest extends PublicBookingTestCase
{
    private const ADDRESS = 'Indirizzo dimostrativo 14, Comune di prova';

    private function mapData(array $overrides = []): array
    {
        return array_replace($this->period(), [
            'request_delivery' => 1, 'delivery_address' => self::ADDRESS,
            'map_lat' => '41.1200000', 'map_lng' => '16.8600000', 'map_zoom' => 17, 'map_confirmed' => 1,
        ], $overrides);
    }

    private function service(int $id, float $lat, ?float $radius = 10): PublicDeliveryLocation
    {
        $this->offer($id, organization: $id);
        if ($id !== 1) DB::table('vehicle_assignments')->insert(['vehicle_id' => $id, 'renter_org_id' => $id, 'start_at' => '2026-09-01']);
        $data = ['name' => 'Stazione dimostrativa', 'city' => 'Comune di prova', 'kind' => 'station', 'address_line' => 'Piazzale di prova', 'country_code' => 'IT'];
        $place = PublicPickupPlace::firstOrCreate(['identity_key' => PublicPickupPlace::identity($data)], $data);
        $return = Location::create(['organization_id' => $id] + $place->only(['name', 'city', 'address_line', 'country_code']));
        Location::findOrFail($id)->update(['lat' => $lat, 'lng' => 16.86]);
        return PublicDeliveryLocation::create(['organization_id' => $id, 'public_pickup_place_id' => $place->id,
            'location_id' => $return->id, 'is_active' => true, 'custom_delivery_enabled' => true,
            'delivery_area' => 'Zona dimostrativa', 'delivery_origin_location_id' => $id, 'delivery_radius_km' => $radius]);
    }

    private function selectPoint(array $overrides = []): array
    {
        $response = $this->post(route('public-cars.map.store'), $this->mapData($overrides))
            ->assertStatus(303)->assertSessionHasNoErrors();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $filters);
        return $filters;
    }

    public function test_map_fallback_is_available_for_empty_and_unavailable_geocoding(): void
    {
        Http::fake(['*' => Http::sequence()->push([])->push([], 503)]);
        $filters = $this->period(['request_delivery' => 1, 'delivery_address' => self::ADDRESS]);
        $this->get(route('public-cars.index', $filters))->assertOk()->assertSee('Indica il punto sulla mappa')
            ->assertViewHas('deliveryLookupNeeded', true)->assertViewHas('results', fn ($rows) => $rows->isEmpty());
        Cache::forget('amd-geocoding:next-request');
        $this->get(route('public-cars.index', array_replace($filters, ['delivery_address' => 'Altro luogo dimostrativo'])))
            ->assertOk()->assertSee('La ricerca dei luoghi non è disponibile')->assertSee('Indica il punto sulla mappa');
    }

    public function test_a_single_town_centers_the_map_without_confirming_a_pickup_or_repeating_the_lookup(): void
    {
        Http::fake(['*' => Http::response([['display_name' => 'Comune di prova, Italia', 'lat' => '41.12', 'lon' => '16.86', 'place_rank' => 16]])]);
        $this->get(route('public-cars.index', $this->period(['request_delivery' => 1, 'delivery_address' => 'Comune di prova'])))
            ->assertOk()->assertViewHas('placeChoices', [])
            ->assertViewHas('mapCenter', fn ($point) => $point['lat'] === 41.12 && $point['zoom'] === 13)
            ->assertSee('data-initial-lat="41.12"', false)->assertSee('data-initial-zoom="13"', false)
            ->assertSee('name="query" type="search" value="Comune di prova"', false);
        $this->assertEmpty(session('amd_place_selections', []));
        $this->postJson(route('public-cars.map.search'), ['query' => 'Comune di prova'])->assertOk();
        Http::assertSentCount(1);
    }

    public function test_ambiguous_towns_do_not_automatically_center_on_an_arbitrary_result(): void
    {
        Http::fake(['*' => Http::response([
            ['display_name' => 'Comune di prova A, Italia', 'lat' => '41.12', 'lon' => '16.86', 'place_rank' => 16],
            ['display_name' => 'Comune di prova B, Italia', 'lat' => '42.12', 'lon' => '17.86', 'place_rank' => 16],
        ])]);
        $this->get(route('public-cars.index', $this->period(['request_delivery' => 1, 'delivery_address' => 'Comune di prova'])))
            ->assertOk()->assertViewHas('mapCenter', null)->assertViewHas('placeChoices', []);
        $this->assertEmpty(session('amd_place_selections', []));
    }

    public function test_area_search_never_confirms_a_city_as_a_delivery_point_and_is_cached(): void
    {
        Http::fake(['*' => Http::response([['display_name' => 'Comune di prova, Italia', 'lat' => '41.12', 'lon' => '16.86', 'place_rank' => 16]])]);
        $this->postJson(route('public-cars.map.search'), ['query' => 'Comune di prova'])->assertOk()
            ->assertJsonPath('places.0.zoom', 13)->assertJsonMissingPath('places.0.token');
        $this->assertEmpty(session('amd_place_selections', []));
        $this->postJson(route('public-cars.map.search'), ['query' => 'Comune di prova'])->assertOk();
        Http::assertSentCount(1);
        Cache::forget('amd-geocoding:next-request');
        $this->get(route('public-cars.index', $this->period(['request_delivery' => 1, 'delivery_address' => 'Comune di prova'])))
            ->assertOk()->assertViewHas('placeChoices', [])->assertViewHas('results', fn ($rows) => $rows->isEmpty());
    }

    public function test_explicit_point_preserves_address_and_applies_radius_and_nearest_order_without_geocoding(): void
    {
        $this->service(1, 41.15); $this->service(2, 41.125); $this->service(3, 41.5, 1);
        $filters = $this->selectPoint(['supplier' => 3, 'delivery_place' => 'old-selection']);
        $this->assertSame(self::ADDRESS, $filters['delivery_address']);
        $this->assertArrayNotHasKey('map_lat', $filters);
        $this->assertArrayNotHasKey('supplier', $filters);
        $point = app(PlaceSelection::class)->resolve($filters['delivery_place'], self::ADDRESS);
        $this->assertSame('map', $point['source']);
        $this->assertEquals(41.12, $point['lat']); $this->assertEquals(16.86, $point['lng']);
        $this->get(route('public-cars.index', $filters))->assertOk()
            ->assertViewHas('supplierResults', fn ($rows) => $rows->pluck('id')->all() === [2, 1])
            ->assertSee('L’auto giusta, dove comincia il tuo viaggio.')->assertSee('Modifica il punto sulla mappa');
        Http::assertNothingSent();
        $this->assertDatabaseCount('amd_rent_enquiries', 0);
    }

    public function test_map_rejects_invalid_coordinates_insufficient_zoom_and_missing_confirmation(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        foreach (['map_lat' => ['91', '-86', 'NaN', 'Infinity', '1e309', null, []],
            'map_lng' => ['181', '-181', 'NaN', null], 'map_zoom' => [5, 15, 20, 16.5, null],
            'map_confirmed' => [0, null], 'delivery_address' => ['x', null], 'pickup_at' => [null], 'return_at' => [null]] as $field => $values) {
            foreach ($values as $value) {
                $this->post(route('public-cars.map.store'), $this->mapData([$field => $value]))
                    ->assertRedirect()->assertSessionHasErrors($field);
                $this->assertEmpty(session('amd_place_selections', []));
            }
        }
        Http::assertNothingSent();
    }

    public function test_direct_get_coordinates_do_not_skip_confirmation_and_expired_tokens_are_rejected(): void
    {
        Http::fake(['*' => Http::response([])]);
        $this->get(route('public-cars.index', $this->mapData()))->assertOk()->assertViewHas('deliveryLookupNeeded', true);
        $filters = $this->selectPoint();
        $this->get(route('public-cars.index', array_replace($filters, ['delivery_address' => 'Indirizzo diverso, Comune di prova'])))
            ->assertRedirect()->assertSessionHasErrors('delivery_place');
        $this->travel(3)->hours();
        $this->get(route('public-cars.index', $filters))->assertRedirect()->assertSessionHasErrors('delivery_place');
    }

    public function test_map_point_and_original_address_reach_enquiry_with_separate_return_and_immutable_coordinates(): void
    {
        $service = $this->service(1, 41.13);
        $filters = $this->selectPoint();
        $this->get(route('public-cars.show', ['pricelist' => 1] + $filters))->assertOk()->assertSee('Dove riconsegni l’auto?');
        $filters['place_id'] = $service->public_pickup_place_id;
        $page = $this->get(route('public-cars.booking.create', ['pricelist' => 1] + $filters))->assertOk()
            ->assertSee(self::ADDRESS)->assertSee('Vedi il punto di ritiro indicato sulla mappa');
        $this->post(route('public-cars.booking.store', 1), $filters + ['first_name' => 'Cliente', 'last_name' => 'Mappa',
            'email' => 'map@example.test', 'phone' => '+393200000000', 'accept_summary' => 1,
            'checkout_token' => $page->viewData('checkoutToken'), 'map_lat' => -20, 'map_lng' => -40])->assertStatus(303);
        $case = AmdRentEnquiry::firstOrFail();
        $this->assertSame(self::ADDRESS, $case->delivery_address);
        $this->assertSame(['label' => self::ADDRESS, 'lat' => 41.12, 'lng' => 16.86, 'source' => 'map'], $case->booking_context['delivery_destination']);
        $this->assertSame($service->public_pickup_place_id, $case->booking_context['period']['place_id']);
        $this->get($case->publicUrl())->assertOk()->assertSee('Vedi il punto di ritiro indicato sulla mappa');
        $this->assertDatabaseCount('public_bookings', 0); $this->assertDatabaseCount('rentals', 0);
        Http::assertNothingSent();
    }

    public function test_area_search_rejects_invalid_input_and_preview_remains_protected(): void
    {
        $this->postJson(route('public-cars.map.search'), ['query' => ['invalid']])->assertUnprocessable();
        $this->postJson(route('public-cars.preview.map.search'), ['query' => 'Comune di prova'])->assertUnauthorized();
        $this->postJson(route('public-cars.preview.map.store'), $this->mapData())->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_area_search_and_normal_lookup_share_the_provider_rate_limit(): void
    {
        Http::fake(['*' => Http::response([])]);
        $this->postJson(route('public-cars.map.search'), ['query' => 'Comune di prova'])->assertOk();
        $this->get(route('public-cars.index', $this->period(['request_delivery' => 1, 'delivery_address' => self::ADDRESS])))
            ->assertOk()->assertSee('momentaneamente occupata');
        Http::assertSentCount(1);
    }

    public function test_reverse_lookup_returns_an_address_suggestion_without_changing_or_confirming_the_point(): void
    {
        Http::fake(['*' => Http::response(['display_name' => 'Via dimostrativa 14, Comune di prova, Italia',
            'lat' => '41.1202', 'lon' => '16.8603', 'place_rank' => 30])]);
        $coordinates = ['lat' => 41.12, 'lng' => 16.86];
        $this->postJson(route('public-cars.map.address'), $coordinates)->assertOk()
            ->assertExactJson(['label' => 'Via dimostrativa 14, Comune di prova, Italia']);
        $this->postJson(route('public-cars.map.address'), $coordinates)->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/reverse?') && $request['lat'] === '41.1200000'
            && $request['lon'] === '16.8600000' && $request['zoom'] === 18 && !isset($request['q']));
        $this->assertEmpty(session('amd_place_selections', []));
    }

    public function test_reverse_lookup_does_not_suggest_a_town_centre_or_an_unmapped_address(): void
    {
        Http::fake(['*' => Http::sequence()->push(['display_name' => 'Comune di prova, Italia',
            'lat' => '41.12', 'lon' => '16.86', 'place_rank' => 16])->push(['error' => 'Unable to geocode'], 404)]);
        $this->postJson(route('public-cars.map.address'), ['lat' => 41.12, 'lng' => 16.86])->assertOk()->assertJsonPath('label', null);
        Cache::forget('amd-geocoding:next-request');
        $this->postJson(route('public-cars.map.address'), ['lat' => 41.121, 'lng' => 16.86])->assertOk()->assertJsonPath('label', null);
    }

    public function test_reverse_waits_for_the_shared_short_interval_after_an_area_search(): void
    {
        $sent = [];
        Http::fake(function ($request) use (&$sent) {
            $sent[] = microtime(true);
            $row = ['display_name' => 'Via dimostrativa, Comune di prova, Italia', 'lat' => '41.12', 'lon' => '16.86', 'place_rank' => 26];
            return Http::response(str_contains($request->url(), '/reverse?') ? $row : [$row]);
        });
        $this->postJson(route('public-cars.map.search'), ['query' => 'Comune di prova'])->assertOk();
        $this->postJson(route('public-cars.map.address'), ['lat' => 41.12, 'lng' => 16.86])->assertOk();
        $this->assertCount(2, $sent);
        $this->assertGreaterThanOrEqual(1.0, $sent[1] - $sent[0]);
    }

    public function test_reverse_rejects_invalid_coordinates_and_respects_provider_cooldown(): void
    {
        $this->postJson(route('public-cars.map.address'), ['lat' => 91, 'lng' => 0])->assertUnprocessable();
        $this->postJson(route('public-cars.map.address'), ['lat' => '1e309', 'lng' => 0])->assertUnprocessable();
        $this->postJson(route('public-cars.map.address'), ['lat' => 0, 'lng' => []])->assertUnprocessable();
        Http::assertNothingSent();
        Http::fake(['*' => Http::response([], 503)]);
        $this->postJson(route('public-cars.map.address'), ['lat' => 41.12, 'lng' => 16.86])->assertStatus(503);
        $this->postJson(route('public-cars.map.address'), ['lat' => 41.121, 'lng' => 16.86])->assertStatus(429);
        Http::assertSentCount(1);
    }
}
