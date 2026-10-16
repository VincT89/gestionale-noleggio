<?php

namespace Tests\Feature;

use App\Models\{AmdRentEnquiry, Location, PublicBooking, PublicDeliveryLocation, VehicleProduct};
use App\Services\AmdRent\{Commission, DeliveryQuotes};
use App\Services\Geocoding\PlaceSelection;
use Illuminate\Support\Facades\{DB, Http, URL};
use Tests\Support\PublicBookingTestCase;

class PublicSegmentJourneyTest extends PublicBookingTestCase
{
    public function test_standard_checkout_cannot_add_unquoted_delivery_even_with_a_valid_map_token(): void
    {
        $this->car(1, 1, 3000, 2000);
        $data = $this->form($this->period());
        foreach ([[], ['delivery_place' => $this->trip()['delivery_place']]] as $selection) {
            $this->postJson(route('public-cars.booking.store', 1), $data + $selection + [
                'request_delivery' => 1, 'delivery_address' => 'Hotel dimostrativo, indirizzo di prova',
            ])->assertUnprocessable()->assertJsonValidationErrors('delivery_address');
        }
        $this->assertDatabaseCount('amd_rent_enquiries', 0);
        $this->assertDatabaseCount('public_bookings', 0);
        Http::assertNothingSent();
    }

    public function test_map_from_standard_booking_rechecks_coverage_fee_and_keeps_dates_and_type(): void
    {
        $this->car(1, 1, 3000, 2000);
        $filters = $this->period(['city' => 'Bari', 'segment' => 'Citycar', 'sort' => 'price_asc', 'budget' => '200']);
        $page = $this->get(route('public-cars.booking.create', ['pricelist' => 1] + $filters))->assertOk()
            ->assertSee('Scegli il ritiro sulla mappa')->assertDontSee('data-delivery-choice', false);
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$page->getContent());
        $xpath = new \DOMXPath($dom);
        $mapData = [];
        foreach ($xpath->query('//form[@data-map-confirm]//input[@type="hidden"]') as $input) {
            $mapData[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        $this->assertSame('Citycar', $mapData['segment']);
        $this->assertArrayNotHasKey('city', $mapData);
        $this->assertArrayNotHasKey('place_id', $mapData);
        $response = $this->post(route('public-cars.map.store'), array_replace($mapData, [
            'map_lat' => 41.12, 'map_lng' => 16.86, 'map_zoom' => 17, 'map_confirmed' => 1,
            'delivery_address' => 'Hotel dimostrativo, indirizzo di prova',
        ]))->assertStatus(303)->assertSessionHasNoErrors();
        $result = $this->get($response->headers->get('Location'))->assertOk();
        $this->assertSame(11000, $result->viewData('results')->sole()['total_cents']);
        $this->assertSame($filters['pickup_at'], $result->viewData('filters')['pickup_at']);
        $this->assertSame($filters['return_at'], $result->viewData('filters')['return_at']);
        $this->assertSame('Citycar', $result->viewData('filters')['segment']);
        $this->assertSame('price_asc', $result->viewData('filters')['sort']);
        $response = $this->post(route('public-cars.map.store'), array_replace($mapData, [
            'map_lat' => 42, 'map_lng' => 16.86, 'map_zoom' => 17, 'map_confirmed' => 1,
            'delivery_address' => 'Indirizzo dimostrativo fuori zona',
        ]))->assertStatus(303);
        $this->get($response->headers->get('Location'))->assertOk()->assertViewHas('results', fn ($cars) => $cars->isEmpty());
        $this->assertDatabaseCount('amd_rent_enquiries', 0);
    }

    public function test_delivery_summary_shows_pickup_and_return_dates_before_and_after_quote(): void
    {
        $this->car(1, 1, 3000, 2000);
        $form = $this->form($this->trip(true));
        $this->post(route('public-cars.booking.store', 1), $form)->assertStatus(303);
        $case = AmdRentEnquiry::sole();
        $this->get($case->publicUrl())->assertOk()->assertSee('10/09/2026 10:00')->assertSee('13/09/2026 10:00');
        $case->update(['status' => 'quoted', 'quote_expires_at' => now()->addDay()]);
        $this->get($case->publicUrl())->assertOk()->assertSee('10/09/2026 10:00')->assertSee('13/09/2026 10:00')
            ->assertSee('Ho verificato auto, luoghi, date e importo');
    }

    public function test_admin_and_customer_summaries_show_updated_delivery_total_and_preserve_original_request(): void
    {
        $this->car(1, 1, 3000, 2000);
        $this->post(route('public-cars.booking.store', 1), $this->form($this->trip(true)))->assertStatus(303);
        $case = AmdRentEnquiry::sole();
        $this->assertSame(11000, $case->booking_context['car']['total_cents']);
        $this->actingAs($this->publisher());
        $this->get(route('amd-rent.enquiries.show', $case))->assertOk()
            ->assertSee('Totale richiesto dal cliente')->assertSee('110,00 €');

        foreach ([35 => '125,00 €', 0 => '90,00 €'] as $fee => $total) {
            $this->put(route('amd-rent.enquiries.update', $case), [
                'revision' => $case->fresh()->revision, 'status' => 'quoted',
                'delivery_fee' => $fee, 'quote_valid_until' => now()->addDay()->toDateString(),
                'confirm_custom_return' => 1,
            ])->assertRedirect()->assertSessionHasNoErrors();

            $this->get(route('amd-rent.enquiries.show', $case))->assertOk()
                ->assertSee('Totale della proposta al cliente')->assertSee($total)->assertDontSee('110,00 €');
            $this->get($case->publicUrl())->assertOk()->assertSee('La tua proposta: '.$total);
            $this->assertSame(11000, $case->fresh()->booking_context['car']['total_cents']);
        }
        $this->assertDatabaseCount('public_bookings', 0);
        Http::assertNothingSent();
    }

    private function car(int $id, int $organization, int $daily, ?int $package, string $segment = 'Citycar', float $radius = 20): PublicDeliveryLocation
    {
        $group = VehicleProduct::firstOrCreate(['name_key' => mb_strtolower($segment)], ['name' => $segment]);
        $this->offer($id, $organization, ['base_daily_cents' => $daily], ['vehicle_product_id' => $group->id]);
        if ($organization !== 1) DB::table('vehicle_assignments')->insert([
            'vehicle_id' => $id, 'renter_org_id' => $organization, 'start_at' => '2026-09-01', 'end_at' => null,
        ]);
        Location::findOrFail($organization)->update(['lat' => 41.12, 'lng' => 16.86]);
        $service = PublicDeliveryLocation::where('organization_id', $organization)->firstOrFail();
        $service->update(['custom_delivery_enabled' => true, 'delivery_origin_location_id' => $organization,
            'delivery_radius_km' => $radius, 'custom_delivery_fee_cents' => $package]);
        return $service;
    }

    private function trip(bool $withReturn = false, float $returnLat = 41.14): array
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->startSession();
        $pickup = app(PlaceSelection::class)->issue([
            ['label' => 'Hotel dimostrativo, indirizzo di prova', 'lat' => 41.12, 'lng' => 16.86, 'source' => 'map'],
        ])[0];
        $filters = $this->period(['request_delivery' => 1, 'delivery_address' => $pickup['label'], 'delivery_place' => $pickup['token']]);
        if ($withReturn) {
            $return = app(PlaceSelection::class)->issue([
                ['label' => 'Aeroporto dimostrativo, partenze', 'lat' => $returnLat, 'lng' => 16.86, 'source' => 'map'],
            ], 'public-return')[0];
            $filters += ['request_custom_return' => 1, 'return_address' => $return['label'], 'return_place' => $return['token']];
        }
        return $filters;
    }

    private function form(array $filters, int $id = 1): array
    {
        $page = $this->get(route('public-cars.booking.create', ['pricelist' => $id] + $filters))->assertOk()
            ->assertViewIs('public-cars.booking');
        return $page->viewData('filters') + ['checkout_token' => $page->viewData('checkoutToken'),
            'first_name' => 'Cliente', 'last_name' => 'Dimostrativo', 'email' => 'segmenti@example.test',
            'phone' => '+393200000000', 'accept_summary' => 1];
    }

    public function test_matching_shows_cheapest_inclusive_offer_per_segment_and_no_supplier_selection(): void
    {
        $this->car(1, 1, 3000, 6000);
        $this->car(2, 2, 4000, 1000);
        $this->car(3, 3, 6000, 2000, 'SUV');
        $filters = $this->trip();
        $page = $this->get(route('public-cars.index', $filters))->assertOk()
            ->assertViewHas('results', fn ($cars) => $cars->pluck('id')->all() === [2, 3])
            ->assertSee('130,00 €')->assertSee('43,33 € al giorno in media')
            ->assertDontSee('name="supplier"', false)->assertDontSee('Noleggiatore di prova')
            ->assertDontSee('Altro noleggiatore di prova');
        $this->assertSame(13000, $page->viewData('results')->first()['total_cents']);
        $this->get(route('public-cars.index', $filters + ['segment' => 'citycar', 'supplier' => 1]))->assertOk()
            ->assertViewHas('results', fn ($cars) => $cars->pluck('id')->all() === [2]);
        $this->get(route('public-cars.index', $filters + ['budget' => '129.99']))->assertOk()
            ->assertViewHas('results', fn ($cars) => $cars->isEmpty());
        Http::assertNothingSent();
    }

    public function test_when_cheapest_vehicle_is_busy_next_car_in_same_segment_is_shown(): void
    {
        $this->car(1, 1, 3000, 6000);
        $this->car(2, 2, 4000, 1000);
        DB::table('vehicle_blocks')->insert(['vehicle_id' => 2, 'status' => 'active', 'start_at' => '2026-09-11', 'end_at' => '2026-09-12']);
        $this->get(route('public-cars.index', $this->trip()))->assertOk()
            ->assertViewHas('results', fn ($cars) => $cars->pluck('id')->all() === [1]);
    }

    public function test_booked_panda_is_replaced_by_next_panda_and_stale_selection_cannot_create_request(): void
    {
        $this->car(1, 1, 3000, 1000);
        $this->car(2, 2, 4000, 1000);
        $this->car(3, 3, 2000, 1000, 'SUV');
        DB::table('vehicles')->whereIn('id', [1, 2])->update(['make' => 'Fiat', 'model' => 'Panda']);
        $filters = $this->trip(true) + ['segment' => 'Citycar'];
        $url = route('public-cars.index', $filters);
        $this->get($url)->assertOk()->assertViewHas('results', fn ($cars) => $cars->pluck('id')->all() === [1]);
        $staleForm = $this->form($filters);

        $firstRental = DB::table('rentals')->insertGetId(['organization_id' => 1, 'vehicle_id' => 1,
            'status' => 'reserved', 'planned_pickup_at' => '2026-09-11', 'planned_return_at' => '2026-09-12']);
        $this->get($url)->assertOk()->assertSee('Fiat Panda')->assertSee('130,00 €')
            ->assertViewHas('results', fn ($cars) => $cars->pluck('vehicle_id')->all() === [2]);
        $this->get(route('public-cars.show', ['pricelist' => 1] + $filters))->assertOk()
            ->assertViewHas('car', null)->assertSee('La disponibilità è cambiata');
        $this->post(route('public-cars.booking.store', 1), $staleForm)->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('amd_rent_enquiries', 0);
        $this->assertDatabaseCount('public_bookings', 0);

        DB::table('rentals')->insert(['organization_id' => 2, 'vehicle_id' => 2,
            'status' => 'reserved', 'planned_pickup_at' => '2026-09-11', 'planned_return_at' => '2026-09-12']);
        $this->get($url)->assertOk()->assertViewHas('results', fn ($cars) => $cars->isEmpty());
        DB::table('rentals')->where('id', $firstRental)->update(['status' => 'cancelled']);
        $this->get($url)->assertOk()->assertSee('100,00 €')
            ->assertViewHas('results', fn ($cars) => $cars->pluck('vehicle_id')->all() === [1]);
        Http::assertNothingSent();
    }

    public function test_unconfigured_package_is_excluded_but_explicitly_free_service_is_available(): void
    {
        $service = $this->car(1, 1, 3000, null);
        $filters = $this->trip();
        $this->get(route('public-cars.index', $filters))->assertOk()->assertViewHas('results', fn ($cars) => $cars->isEmpty());
        $service->update(['custom_delivery_fee_cents' => 0]);
        $this->get(route('public-cars.index', $filters))->assertOk()
            ->assertViewHas('results', fn ($cars) => $cars->first()['total_cents'] === 9000);
    }

    public function test_return_outside_service_area_offers_same_segment_alternative_without_substitution(): void
    {
        $this->car(1, 1, 3000, 1000, radius: 3);
        $this->car(2, 2, 4000, 1000, radius: 30);
        $filters = $this->trip(true, 41.22);
        $page = $this->get(route('public-cars.booking.create', ['pricelist' => 1] + $filters))->assertOk()
            ->assertViewHas('car', null)->assertDontSee('checkout_token');
        $this->get(route('public-cars.index', $page->viewData('filters')))->assertOk()
            ->assertViewHas('results', fn ($cars) => $cars->pluck('id')->all() === [2]);
        $this->assertDatabaseCount('amd_rent_enquiries', 0);
        $this->assertDatabaseCount('public_bookings', 0);
    }

    public function test_customer_can_change_an_uncovered_return_without_losing_pickup_dates_or_vehicle_type(): void
    {
        $this->car(1, 1, 3000, 1000, radius: 3);
        $filters = $this->trip(true, 41.22) + ['segment' => 'Citycar'];

        foreach (['public-cars.booking.create' => ['pricelist' => 1], 'public-cars.index' => []] as $route => $parameters) {
            $unavailable = $this->get(route($route, $parameters + $filters))->assertOk();
            $document = new \DOMDocument();
            $document->loadHTML($unavailable->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
            $links = (new \DOMXPath($document))->query('//a[normalize-space(.)="Cambia luogo di riconsegna"]');
            $this->assertSame(1, $links->length);

            $recovered = $this->get($links->item(0)->getAttribute('href'))->assertOk();
            $updatedFilters = $recovered->viewData('filters');
            foreach (['pickup_at', 'return_at', 'request_delivery', 'delivery_address', 'delivery_place', 'segment'] as $key) {
                $this->assertSame((string) $filters[$key], (string) $updatedFilters[$key]);
            }
            foreach (['request_custom_return', 'return_address', 'return_place', 'place_id', 'city'] as $key) {
                $this->assertEmpty($updatedFilters[$key] ?? null);
            }
            if ($parameters) {
                $recovered->assertViewHas('car', fn ($car) => $car['id'] === 1)
                    ->assertSee('Dove riconsegni l’auto?');
            } else {
                $recovered->assertViewHas('results', fn ($cars) => $cars->pluck('id')->all() === [1]);
            }
        }
        $this->assertDatabaseCount('amd_rent_enquiries', 0);
        $this->assertDatabaseCount('public_bookings', 0);
        Http::assertNothingSent();
    }

    public function test_changed_package_or_return_coverage_invalidates_summary_before_request_is_created(): void
    {
        $service = $this->car(1, 1, 3000, 1000);
        $form = $this->form($this->trip(true));
        $service->update(['custom_delivery_fee_cents' => 2000]);
        $this->post(route('public-cars.booking.store', 1), $form)->assertSessionHasErrors('booking');
        $this->flushSession();
        $form = $this->form($this->trip(true));
        $service->update(['delivery_radius_km' => 1]);
        $this->post(route('public-cars.booking.store', 1), $form)->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('amd_rent_enquiries', 0);
        Http::assertNothingSent();
    }

    public function test_request_saves_complete_trip_and_commission_and_setting_change_only_affects_new_requests(): void
    {
        $service = $this->car(1, 1, 10000, 3000);
        $form = $this->form($this->trip(true));
        $this->post(route('public-cars.booking.store', 1), $form)->assertStatus(303)->assertSessionHasNoErrors();
        $case = AmdRentEnquiry::sole();
        $this->assertSame(33000, $case->booking_context['car']['total_cents']);
        $this->assertSame(2000, $case->booking_context['car']['commission_bps']);
        $this->assertSame(3000, $case->delivery_fee_cents);
        $this->assertSame('Aeroporto dimostrativo, partenze', $case->booking_context['return_address']);
        $this->assertDatabaseCount('public_bookings', 0);
        $this->assertDatabaseCount('rentals', 0);
        Http::assertNothingSent();

        $this->actingAs($this->publisher())->put(route('amd-rent.settings.save'), ['commission_percent' => '25.50'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2550, Commission::currentBps());
        $this->put(route('amd-rent.enquiries.update', $case), ['revision' => 1, 'status' => 'quoted',
            'delivery_fee' => 30, 'quote_valid_until' => '2026-09-09', 'confirm_custom_return' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $service->update(['custom_delivery_fee_cents' => 9000]);
        $page = $this->post(URL::signedRoute('public-enquiries.accept', ['reference' => $case->reference]), ['accept_quote' => 1])->assertOk()
            ->assertDontSee('Supplemento consegna')->assertSee('110,00 € al giorno in media')
            ->assertSee('Torna alla proposta')->assertDontSee('Torna all’auto');
        $document = new \DOMDocument();
        $document->loadHTML($page->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $backLink = (new \DOMXPath($document))->query('//a[normalize-space(.)="Torna alla proposta"]')->item(0);
        $this->get($backLink->getAttribute('href'))->assertOk()->assertViewIs('public-cars.enquiry')
            ->assertSee('Hotel dimostrativo, indirizzo di prova')->assertSee('Aeroporto dimostrativo, partenze')
            ->assertSee('330,00 €');
        $car = $page->viewData('car');
        $this->assertSame(33000, $car['total_cents']);
        $this->assertSame(6600, DeliveryQuotes::onlineDue($car));
        $this->assertSame(2000, $car['commission_bps']);
        $newPage = $this->get(route('public-cars.booking.create', ['pricelist' => 1] + $this->trip(true)))->assertOk();
        $this->assertSame(2550, $newPage->viewData('car')['commission_bps']);
        $this->post(route('public-cars.booking.store', 1), array_replace($form, $page->viewData('filters'),
            ['request_delivery' => 0, 'request_custom_return' => 0, 'checkout_token' => $page->viewData('checkoutToken')]))->assertStatus(303)->assertSessionHasNoErrors();
        $booking = PublicBooking::sole();
        $this->assertSame(2000, $booking->quote_snapshot['commission_bps']);
        $this->assertSame(33000, $booking->total_cents);
    }

    public function test_legacy_request_keeps_split_commission_without_repricing_existing_bookings(): void
    {
        $this->car(1, 1, 10000, 3000);
        $this->post(route('public-cars.booking.store', 1), $this->form($this->trip(true)))->assertStatus(303);
        $case = AmdRentEnquiry::sole();
        $context = $case->booking_context;
        unset($context['car']['commission_bps']);
        $case->update(['booking_context' => $context, 'status' => 'quoted', 'quote_expires_at' => now()->addDay()]);
        DB::table('amd_rent_settings')->insert(['id' => 1, 'commission_bps' => 3500, 'delivery_commission_bps' => 1000]);
        $page = $this->post(URL::signedRoute('public-enquiries.accept', ['reference' => $case->reference]), ['accept_quote' => 1])->assertOk();
        $car = $page->viewData('car');
        $this->assertArrayNotHasKey('commission_bps', $car);
        $this->assertSame(33000, $car['total_cents']);
        $this->assertSame(6300, DeliveryQuotes::onlineDue($car));
        $this->assertDatabaseCount('public_bookings', 0);
    }

    public function test_supplier_confirmation_rechecks_calendar_and_return_coverage(): void
    {
        $service = $this->car(1, 1, 3000, 1000);
        $this->post(route('public-cars.booking.store', 1), $this->form($this->trip(true)))->assertStatus(303);
        $case = AmdRentEnquiry::sole();
        $data = ['revision' => 1, 'status' => 'quoted', 'delivery_fee' => 10,
            'quote_valid_until' => '2026-09-09', 'confirm_custom_return' => 1];
        $this->actingAs($this->publisher());
        DB::table('vehicle_blocks')->insert(['vehicle_id' => 1, 'status' => 'active', 'start_at' => '2026-09-11', 'end_at' => null]);
        $this->put(route('amd-rent.enquiries.update', $case), $data)->assertSessionHasErrors('status');
        DB::table('vehicle_blocks')->delete();
        $service->update(['delivery_radius_km' => 1]);
        $this->put(route('amd-rent.enquiries.update', $case), $data)->assertSessionHasErrors('status');
        $this->assertSame('new', $case->fresh()->status);
    }

    public function test_rejection_link_keeps_trip_and_segment_and_requires_customer_to_choose_again(): void
    {
        $this->car(1, 1, 3000, 1000);
        $this->car(2, 2, 4000, 1000);
        $this->car(3, 3, 2000, 1000, 'SUV');
        $this->post(route('public-cars.booking.store', 1), $this->form($this->trip(true)))->assertStatus(303);
        $case = AmdRentEnquiry::sole();
        $case->update(['status' => 'lost']);
        $this->flushSession();
        $this->get(route('public-enquiries.alternatives', ['reference' => $case->reference]))->assertForbidden();
        $response = $this->get(URL::signedRoute('public-enquiries.alternatives', ['reference' => $case->reference]))->assertRedirect();
        $page = $this->get($response->headers->get('Location'))->assertOk()
            ->assertViewHas('results', fn ($cars) => $cars->pluck('id')->all() === [2]);
        $this->assertSame('1', $page->viewData('filters')['exclude']);
        $this->assertSame('Aeroporto dimostrativo, partenze', $page->viewData('filters')['return_address']);
        $this->assertDatabaseCount('amd_rent_enquiries', 1);
        $this->assertDatabaseCount('public_bookings', 0);
        Http::assertNothingSent();
    }

    public function test_only_admin_can_change_total_commission_and_invalid_rates_are_rejected(): void
    {
        $this->actingAs($this->publisher(2, 'renter'))->put(route('amd-rent.settings.save'), ['commission_percent' => 30])->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->publisher());
        foreach (['', -1, 101, '10.123', 'invalid'] as $value) {
            $response = $this->put(route('amd-rent.settings.save'), ['commission_percent' => $value]);
            $this->assertSame(302, $response->status(), 'Invalid commission: '.json_encode($value));
            $response->assertSessionHasErrors('commission_percent');
        }
        $this->put(route('amd-rent.settings.save'), ['commission_percent' => 0])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0, Commission::currentBps());
        $this->assertSame(251, DeliveryQuotes::onlineDue(['total_cents' => 1003, 'commission_bps' => 2500, 'deposit_cents' => 999999]));
    }

    public function test_package_is_added_once_to_real_24_hour_periods(): void
    {
        $this->car(1, 1, 10000, 3000);
        $filters = $this->trip();
        $filters['return_at'] = '2026-09-11T10:00';
        $this->get(route('public-cars.index', $filters))->assertOk()
            ->assertViewHas('results', fn ($cars) => $cars->first()['days'] === 1 && $cars->first()['total_cents'] === 13000);
        $filters['return_at'] = '2026-09-11T10:01';
        $this->get(route('public-cars.index', $filters))->assertOk()
            ->assertViewHas('results', fn ($cars) => $cars->first()['days'] === 2 && $cars->first()['total_cents'] === 23000);
    }
}

