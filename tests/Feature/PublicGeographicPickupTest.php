<?php

namespace Tests\Feature;

use App\Models\{AmdRentEnquiry, Location, PublicBooking, PublicDeliveryLocation, PublicPickupPlace};
use App\Services\Geocoding\PlaceSelection;
use Illuminate\Support\Facades\{Cache, DB, Http, URL};
use Illuminate\Support\Str;
use Tests\Support\PublicBookingTestCase;

class PublicGeographicPickupTest extends PublicBookingTestCase
{
    private const LABEL = 'Hotel dimostrativo, Via di prova 10, Bari, Italia';

    private function responsePlace(string $label = self::LABEL, float $lat = 41.12): array
    {
        return ['display_name' => $label, 'lat' => (string) $lat, 'lon' => '16.86', 'place_rank' => 30];
    }

    private function service(int $organization, float $lat, ?float $radius = 10): PublicDeliveryLocation
    {
        $this->offer($organization, organization: $organization);
        if ($organization !== 1) DB::table('vehicle_assignments')->insert(['vehicle_id' => $organization, 'renter_org_id' => $organization, 'start_at' => '2026-09-01', 'end_at' => null]);
        $data = ['name' => 'Aeroporto di prova', 'city' => 'Bari', 'kind' => 'airport', 'address_line' => 'Terminal dimostrativo', 'country_code' => 'IT'];
        $place = PublicPickupPlace::firstOrCreate(['identity_key' => PublicPickupPlace::identity($data)], $data);
        $returnLocation = Location::create(['organization_id' => $organization] + $place->only(['name', 'city', 'address_line', 'country_code']));
        Location::findOrFail($organization)->update(['lat' => $lat, 'lng' => 16.86]);
        return PublicDeliveryLocation::create(['organization_id' => $organization, 'public_pickup_place_id' => $place->id,
            'location_id' => $returnLocation->id, 'is_active' => true, 'custom_delivery_enabled' => true,
            'delivery_area' => 'Zona dimostrativa, soggetta a conferma', 'delivery_origin_location_id' => $organization, 'delivery_radius_km' => $radius]);
    }

    private function filters(PublicDeliveryLocation $service, bool $selected = true): array
    {
        $filters = $this->period(['place_id' => $service->public_pickup_place_id, 'request_delivery' => 1, 'delivery_address' => self::LABEL]);
        if ($selected) {
            $this->startSession();
            $filters['delivery_place'] = app(PlaceSelection::class)->issue([['label' => self::LABEL, 'lat' => 41.12, 'lng' => 16.86]])[0]['token'];
        }
        return $filters;
    }

    public function test_lookup_requires_a_confirmed_result_and_reuses_the_cached_response(): void
    {
        $service = $this->service(1, 41.13);
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([$this->responsePlace(), $this->responsePlace('Hotel omonimo dimostrativo, Roma, Italia', 41.9)])]);
        $filters = $this->filters($service, false);
        $page = $this->get(route('public-cars.index', $filters))->assertOk()->assertViewHas('deliveryLookupNeeded', true)
            ->assertViewHas('results', fn ($rows) => $rows->isEmpty())->assertSee('Conferma il luogo di ritiro')->assertSee('OpenStreetMap');
        $choices = $page->viewData('placeChoices');
        $this->assertCount(2, $choices);
        $this->get(route('public-cars.index', $filters))->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['q'] === self::LABEL && $request['limit'] === 5
            && $request->hasHeader('User-Agent', config('geocoding.user_agent')) && !isset($request['email']));
        $this->get(route('public-cars.index', $filters + ['delivery_place' => $choices[0]['token']]))
            ->assertOk()->assertViewHas('showDeliverySuppliers', true)->assertSee('Vedi auto e prezzi');
        Http::assertSentCount(1);
    }

    public function test_custom_address_search_can_start_without_a_return_location(): void
    {
        $service = $this->service(1, 41.13);
        Http::fake(['*' => Http::response([$this->responsePlace()])]);
        $filters = $this->filters($service, false);
        unset($filters['place_id']);
        $filters['destination'] = '';
        $page = $this->get(route('public-cars.index', $filters))->assertOk()->assertSessionHasNoErrors()
            ->assertViewHas('deliveryLookupNeeded', true);
        $choice = $page->viewData('placeChoices')[0];
        $filters['delivery_place'] = $choice['token'];
        $this->get(route('public-cars.index', $filters))->assertOk()
            ->assertViewHas('supplierResults', fn ($rows) => $rows->pluck('id')->all() === [1])
            ->assertSee('da scegliere dopo l’auto');
        $this->get(route('public-cars.index', $filters + ['supplier' => 1]))->assertOk()
            ->assertSee('Da scegliere tra i punti serviti');
        unset($filters['destination']);
        $detail = $this->get(route('public-cars.show', ['pricelist' => 1] + $filters))->assertOk()
            ->assertSee('Dove riconsegni l’auto?');
        $this->assertSame([$service->public_pickup_place_id], $detail->viewData('returnPlaces')->pluck('id')->all());
        $this->get(route('public-cars.booking.create', ['pricelist' => 1] + $filters))
            ->assertRedirect(route('public-cars.show', ['pricelist' => 1] + $filters))->assertSessionHasErrors('place_id');
        $this->get(route('public-cars.booking.create', ['pricelist' => 1, 'place_id' => $service->public_pickup_place_id] + $filters))
            ->assertOk()->assertViewIs('public-cars.booking');
        $this->assertDatabaseCount('amd_rent_enquiries', 0);
        Http::assertSentCount(1);
    }

    public function test_optional_return_does_not_accept_invalid_destinations_or_remove_standard_validation(): void
    {
        $service = $this->service(1, 41.13);
        $filters = $this->filters($service, false);
        unset($filters['place_id']);
        $this->get(route('public-cars.index', $filters + ['destination' => 'unlisted-value']))
            ->assertRedirect()->assertSessionHasErrors('destination');
        $this->get(route('public-cars.index', $this->period(['destination' => ''])))
            ->assertRedirect()->assertSessionHasErrors('destination');
        Http::assertNothingSent();
    }

    public function test_deferred_return_only_offers_active_points_served_by_the_selected_supplier(): void
    {
        $service = $this->service(1, 41.13);
        $this->service(2, 41.125);
        $data = ['name' => 'Stazione dimostrativa', 'city' => 'Comune di prova', 'kind' => 'station', 'address_line' => 'Piazzale di prova', 'country_code' => 'IT'];
        $place = PublicPickupPlace::create($data + ['identity_key' => PublicPickupPlace::identity($data)]);
        $location = Location::create(['organization_id' => 1] + $place->only(['name', 'city', 'address_line', 'country_code']));
        $second = PublicDeliveryLocation::create(['organization_id' => 1, 'public_pickup_place_id' => $place->id,
            'location_id' => $location->id, 'is_active' => true, 'custom_delivery_enabled' => true,
            'delivery_origin_location_id' => 1, 'delivery_radius_km' => 10]);
        $filters = $this->filters($service);
        unset($filters['place_id']);
        $detailUrl = route('public-cars.show', ['pricelist' => 1] + $filters);
        $this->get($detailUrl)->assertOk()->assertViewHas('returnPlaces', fn ($places) =>
            $places->pluck('id')->all() === [$service->public_pickup_place_id, $place->id]);
        $second->update(['is_active' => false]);
        $this->get($detailUrl)->assertOk()->assertViewHas('returnPlaces', fn ($places) =>
            $places->pluck('id')->all() === [$service->public_pickup_place_id]);
        $this->get(route('public-cars.booking.create', ['pricelist' => 1, 'place_id' => $place->id] + $filters))
            ->assertOk()->assertViewHas('car', null)->assertDontSee('checkout_token');
        $this->assertDatabaseCount('amd_rent_enquiries', 0);
        Http::assertNothingSent();
    }

    public function test_suppliers_are_ranked_by_origin_distance_and_require_real_coverage_and_cars(): void
    {
        $farther = $this->service(1, 41.14);
        $nearer = $this->service(2, 41.125);
        $outside = $this->service(3, 41.6, 1);
        $filters = $this->filters($farther);
        $this->get(route('public-cars.index', $filters))->assertOk()
            ->assertViewHas('supplierResults', fn ($rows) => $rows->pluck('id')->all() === [2, 1])
            ->assertSee('km in linea d’aria');
        $nearer->update(['delivery_radius_km' => null]);
        $this->get(route('public-cars.index', $filters))->assertViewHas('supplierResults', fn ($rows) => $rows->pluck('id')->all() === [1]);
        $nearer->update(['delivery_radius_km' => 10, 'delivery_origin_location_id' => 1]);
        $this->get(route('public-cars.index', $filters))->assertViewHas('supplierResults', fn ($rows) => $rows->pluck('id')->all() === [1]);
        DB::table('vehicle_blocks')->insert(['vehicle_id' => 1, 'status' => 'active', 'start_at' => '2026-09-09', 'end_at' => '2026-09-15']);
        $this->get(route('public-cars.index', $filters))->assertViewHas('supplierResults', fn ($rows) => $rows->isEmpty());
        Http::assertNothingSent();
    }

    public function test_return_location_filter_is_respected_and_products_do_not_hide_another_supplier(): void
    {
        $first = $this->service(1, 41.13);
        $second = $this->service(2, 41.125);
        $product = DB::table('vehicle_products')->insertGetId(['name' => 'Prodotto dimostrativo', 'name_key' => 'prodotto dimostrativo']);
        DB::table('vehicles')->whereIn('id', [1, 2])->update(['vehicle_product_id' => $product]);
        $filters = $this->filters($first);
        $this->get(route('public-cars.index', $filters))->assertViewHas('supplierResults', fn ($rows) => $rows->count() === 2);
        $this->get(route('public-cars.index', $filters + ['supplier' => 1]))->assertOk()
            ->assertViewHas('results', fn ($rows) => $rows->pluck('supplier_id')->all() === [1]);
        $second->update(['is_active' => false]);
        $this->get(route('public-cars.index', $filters))->assertViewHas('supplierResults', fn ($rows) => $rows->pluck('id')->all() === [1]);
    }

    public function test_coordinates_and_selection_cannot_be_forged_or_reused_in_another_session(): void
    {
        $service = $this->service(1, 41.13);
        $filters = $this->filters($service);
        $this->get(route('public-cars.index', array_replace($filters, ['delivery_place' => (string) Str::uuid(), 'lat' => 41.12, 'lng' => 16.86])))
            ->assertRedirect()->assertSessionHasErrors('delivery_place');
        $this->get(route('public-cars.index', array_replace($filters, ['delivery_address' => 'Indirizzo diverso, Roma, Italia'])))
            ->assertRedirect()->assertSessionHasErrors('delivery_place');
        $this->flushSession();
        $this->get(route('public-cars.index', $filters))->assertRedirect()->assertSessionHasErrors('delivery_place');
        Http::assertNothingSent();
    }

    public function test_lookup_errors_and_imprecise_matches_never_return_arbitrary_suppliers(): void
    {
        $service = $this->service(1, 41.13);
        Http::fake(['*' => Http::sequence()->push([['display_name' => 'Bari, Puglia, Italia', 'lat' => '41.12', 'lon' => '16.86', 'place_rank' => 16]])->push([], 503)]);
        $this->get(route('public-cars.index', $this->filters($service, false)))->assertOk()
            ->assertViewHas('placeChoices', [])->assertSee('Non abbiamo trovato un indirizzo preciso')
            ->assertViewHas('results', fn ($rows) => $rows->isEmpty());
        Cache::flush();
        $this->get(route('public-cars.index', $this->filters($service, false)))->assertOk()
            ->assertViewHas('placeChoices', [])->assertSee('La ricerca dei luoghi non è disponibile')
            ->assertViewHas('supplierResults', fn ($rows) => $rows->isEmpty());
    }

    public function test_global_limit_applies_to_different_searches_and_origins(): void
    {
        $service = $this->service(1, 41.13);
        Http::fake(['*' => Http::response([$this->responsePlace()])]);
        $this->get(route('public-cars.index', $this->filters($service, false)))->assertOk();
        $this->get(route('public-cars.index', array_replace($this->filters($service, false), ['delivery_address' => 'Altro hotel dimostrativo, Bari'])))
            ->assertOk()->assertSee('momentaneamente occupata');
        Http::assertSentCount(1);
    }

    public function test_geographic_search_reaches_the_existing_enquiry_with_immutable_destination(): void
    {
        $service = $this->service(1, 41.13);
        $filters = $this->filters($service);
        $page = $this->get(route('public-cars.booking.create', ['pricelist' => 1] + $filters))->assertOk();
        $form = $filters + ['first_name' => 'Cliente', 'last_name' => 'Dimostrativo', 'email' => 'demo@example.test',
            'phone' => '+393200000000', 'accept_summary' => 1, 'checkout_token' => $page->viewData('checkoutToken')];
        $withoutReturn = $form;
        unset($withoutReturn['place_id']);
        $this->post(route('public-cars.booking.store', 1), $withoutReturn)
            ->assertRedirect()->assertSessionHasErrors('checkout_token');
        $this->assertDatabaseCount('amd_rent_enquiries', 0);
        $this->post(route('public-cars.booking.store', 1), array_replace($form, ['delivery_address' => 'Altro indirizzo di prova, Roma']))
            ->assertRedirect()->assertSessionHasErrors();
        $this->assertDatabaseCount('amd_rent_enquiries', 0);
        $this->post(route('public-cars.booking.store', 1), array_replace($form, ['request_delivery' => 0]))
            ->assertRedirect()->assertSessionHasErrors('delivery_address');
        $this->assertDatabaseCount('public_bookings', 0);
        $this->post(route('public-cars.booking.store', 1), $form)->assertStatus(303);
        $case = AmdRentEnquiry::firstOrFail();
        $this->assertSame(1, (int) $case->organization_id);
        $this->assertSame(self::LABEL, $case->delivery_address);
        $this->assertEquals(41.12, $case->booking_context['delivery_destination']['lat']);
        $this->assertSame($service->public_pickup_place_id, $case->booking_context['period']['place_id']);
        $this->assertDatabaseCount('rentals', 0);
        $this->post(route('public-cars.booking.store', 1), $form)->assertStatus(303);
        $this->assertDatabaseCount('amd_rent_enquiries', 1);
        Http::assertNothingSent();
    }

    public function test_coverage_is_checked_again_when_the_customer_submits_the_enquiry(): void
    {
        $service = $this->service(1, 41.13);
        $filters = $this->filters($service);
        $page = $this->get(route('public-cars.booking.create', ['pricelist' => 1] + $filters))->assertOk();
        $service->update(['delivery_radius_km' => .01]);
        $this->post(route('public-cars.booking.store', 1), $filters + [
            'first_name' => 'Cliente', 'last_name' => 'Dimostrativo', 'email' => 'demo@example.test',
            'phone' => '+393200000000', 'accept_summary' => 1, 'checkout_token' => $page->viewData('checkoutToken'),
        ])->assertRedirect()->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('amd_rent_enquiries', 0);
    }

    public function test_management_requires_an_owned_origin_and_explicit_radius(): void
    {
        $service = $this->service(2, 41.13, null);
        $this->actingAs($this->publisher(2, 'renter'));
        $form = ['is_active' => 1, 'custom_delivery_enabled' => 1, 'delivery_area' => 'Zona dimostrativa',
            'delivery_origin_location_id' => 2, 'delivery_radius_km' => 12.5];
        $this->put(route('public-deliveries.update', $service), array_replace($form, ['delivery_origin_location_id' => 1]))->assertNotFound();
        $this->put(route('public-deliveries.update', $service), array_replace($form, ['delivery_radius_km' => null]))
            ->assertSessionHasErrors('delivery_radius_km');
        $this->put(route('public-deliveries.update', $service), $form)->assertRedirect(route('public-deliveries.index'))->assertSessionHasNoErrors();
        $this->assertSame('12.50', $service->fresh()->delivery_radius_km);
        Http::assertNothingSent();
    }

    public function test_quoted_delivery_can_be_booked_after_the_original_place_selection_expires(): void
    {
        $service = $this->service(1, 41.13);
        $filters = $this->filters($service);
        $contact = ['first_name' => 'Cliente', 'last_name' => 'Dimostrativo', 'email' => 'demo@example.test',
            'phone' => '+393200000000', 'accept_summary' => 1];
        $page = $this->get(route('public-cars.booking.create', ['pricelist' => 1] + $filters))->assertOk();
        $this->post(route('public-cars.booking.store', 1), $contact + $filters + ['checkout_token' => $page->viewData('checkoutToken')])->assertStatus(303);
        $case = AmdRentEnquiry::firstOrFail();
        $case->update(['status' => 'quoted', 'delivery_fee_cents' => 3000, 'quote_expires_at' => now()->addDay(), 'revision' => 2]);
        DB::table('amd_rent_settings')->insert(['id' => 1, 'delivery_commission_bps' => 0]);
        $this->flushSession();
        $accept = URL::signedRoute('public-enquiries.accept', ['reference' => $case->reference]);
        $page = $this->post($accept, ['accept_quote' => 1])->assertOk()->assertSee(self::LABEL);
        $form = $contact + $page->viewData('filters') + ['checkout_token' => $page->viewData('checkoutToken')];
        $service->update(['delivery_radius_km' => .01]);
        $this->post(route('public-cars.booking.store', 1), $form)->assertRedirect()->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('public_bookings', 0);
        $this->post($accept, ['accept_quote' => 1])->assertRedirect()->assertSessionHasErrors('booking');
        $service->update(['delivery_radius_km' => 10]);
        $page = $this->post($accept, ['accept_quote' => 1])->assertOk();
        $form = $contact + $page->viewData('filters') + ['checkout_token' => $page->viewData('checkoutToken')];
        $this->post(route('public-cars.booking.store', 1), $form)->assertStatus(303);
        $booking = PublicBooking::firstOrFail();
        $this->assertSame(self::LABEL, $booking->quote_snapshot['delivery_address']);
        $this->assertEquals(41.12, $booking->quote_snapshot['delivery_destination']['lat']);
        $this->assertSame($service->location_id, (int) $booking->rental->return_location_id);
        $this->assertSame($booking->id, (int) $case->fresh()->public_booking_id);
        $this->assertSame(3000, $booking->quote_snapshot['delivery_fee_cents']);
        Http::assertNothingSent();
    }

    public function test_expired_selections_and_unavailable_cache_fail_without_external_requests(): void
    {
        $service = $this->service(1, 41.13);
        $filters = $this->filters($service);
        $this->travel(121)->minutes();
        $this->get(route('public-cars.index', $filters))->assertRedirect(route('public-cars.index'))->assertSessionHasErrors('delivery_place');
        config(['geocoding.cache_store' => 'missing-demo-store']);
        $this->get(route('public-cars.index', $this->filters($service, false)))->assertOk()
            ->assertSee('La ricerca dei luoghi non è disponibile')->assertViewHas('placeChoices', []);
        Http::assertNothingSent();
    }

    public function test_origin_search_requires_confirmation_and_cannot_change_another_organization(): void
    {
        $service = $this->service(2, 41.13, null);
        Location::findOrFail(2)->update(['lat' => null, 'lng' => null]);
        $this->actingAs($this->publisher(2, 'renter'));
        Http::fake(['*' => Http::response([$this->responsePlace('Sede dimostrativa, Bari, Italia', 41.125)])]);
        $form = ['is_active' => 1, 'custom_delivery_enabled' => 1, 'delivery_area' => 'Zona dimostrativa',
            'delivery_origin_location_id' => 2, 'delivery_radius_km' => 12.5];
        $page = $this->put(route('public-deliveries.update', $service), $form + ['locate_origin' => 1])->assertOk();
        $this->assertNull(Location::findOrFail(2)->lat);
        $choice = $page->viewData('choices')[0];
        $this->put(route('public-deliveries.update', $service), $form + ['origin_choice' => $choice['token']])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('41.1250000', Location::findOrFail(2)->lat);
        $this->assertSame('12.50', $service->fresh()->delivery_radius_km);
        Http::assertSentCount(1);
    }
}
