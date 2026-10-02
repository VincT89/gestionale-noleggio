<?php

namespace Tests\Feature;

use App\Models\{AmdRentEnquiry, Location, PublicDeliveryLocation, PublicPickupPlace};
use Illuminate\Support\Facades\{DB, Http};
use Tests\Support\PublicBookingTestCase;

class PublicCustomPickupSearchTest extends PublicBookingTestCase
{
    private const ADDRESS = 'Hotel dimostrativo, via di prova 10, Bari';

    private function airport(): PublicDeliveryLocation
    {
        $this->offer();
        $data = ['name' => 'Aeroporto dimostrativo', 'kind' => 'airport', 'city' => 'Bari',
            'address_line' => 'Punto di riconsegna dimostrativo', 'country_code' => 'IT'];
        $place = PublicPickupPlace::create($data + ['identity_key' => PublicPickupPlace::identity($data)]);
        $location = Location::create(['organization_id' => 1] + $place->only(['name', 'city', 'address_line', 'country_code']));
        return PublicDeliveryLocation::create(['organization_id' => 1, 'public_pickup_place_id' => $place->id,
            'location_id' => $location->id, 'is_active' => true, 'custom_delivery_enabled' => true,
            'delivery_area' => 'Zona dimostrativa, indirizzo da confermare']);
    }

    private function custom(array $changes = []): array
    {
        return $this->period(array_replace(['city' => 'Bari', 'request_delivery' => 1, 'delivery_address' => self::ADDRESS], $changes));
    }

    public function test_city_search_selects_a_capable_location_before_grouping_suppliers(): void
    {
        $delivery = $this->airport();
        $this->offer(2, organization: 2);
        DB::table('vehicle_assignments')->insert(['vehicle_id' => 2, 'renter_org_id' => 2, 'start_at' => '2026-09-01', 'end_at' => null]);

        $this->get(route('public-cars.index', $this->custom()))->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertViewHas('results', fn ($rows) => $rows->pluck('id')->all() === [1]
                && $rows->first()['place_id'] === $delivery->public_pickup_place_id)
            ->assertSee(self::ADDRESS)->assertSee('Luogo di riconsegna')
            ->assertDontSee('Ritiro e riconsegna nello stesso luogo');

        $this->get(route('public-cars.index', $this->period(['city' => 'Bari'])))
            ->assertOk()->assertViewHas('results', fn ($rows) => $rows->pluck('id')->all() === [1, 2]);
    }

    public function test_address_and_airport_reach_the_delivery_request_without_creating_a_booking(): void
    {
        Http::fake();
        $delivery = $this->airport();
        $filters = $this->custom(['place_id' => $delivery->public_pickup_place_id]);
        unset($filters['city']);
        $bookingUrl = route('public-cars.booking.create', ['pricelist' => 1] + $filters);
        $this->get(route('public-cars.show', ['pricelist' => 1] + $filters))->assertOk()
            ->assertSee(self::ADDRESS)->assertSee('Aeroporto dimostrativo')->assertSee($bookingUrl)
            ->assertSee('Richiedi il ritiro a questo indirizzo');
        $page = $this->get($bookingUrl)->assertOk()->assertSee('value="'.self::ADDRESS.'"', false)
            ->assertSee('Ritiro richiesto')->assertSee('Luogo di riconsegna')->assertSee('Invia richiesta di consegna');
        $form = $filters + ['first_name' => 'Cliente', 'last_name' => 'Dimostrativo', 'email' => 'ritiro@example.test',
            'phone' => '+393200000000', 'accept_summary' => 1, 'checkout_token' => $page->viewData('checkoutToken')];
        $response = $this->post(route('public-cars.booking.store', 1), $form)->assertStatus(303)->assertSessionHasNoErrors();
        $case = AmdRentEnquiry::firstOrFail();
        $this->assertSame(self::ADDRESS, $case->delivery_address);
        $this->assertSame($delivery->public_pickup_place_id, $case->booking_context['period']['place_id']);
        $this->assertSame('Aeroporto dimostrativo', $case->booking_context['car']['location']);
        $this->assertDatabaseCount('public_bookings', 0);
        $this->assertDatabaseCount('rentals', 0);
        Http::assertNothingSent();
        $this->get($response->headers->get('Location'))->assertOk()->assertSee(self::ADDRESS)->assertSee('Riconsegna presso Aeroporto dimostrativo');
    }

    public function test_filters_sorting_and_pagination_keep_the_custom_pickup(): void
    {
        $delivery = $this->airport();
        $this->offer(2);
        config(['public_cars.per_page' => 1]);
        $filters = $this->custom(['place_id' => $delivery->public_pickup_place_id, 'sort' => 'price_desc', 'seats' => 4]);
        $page = $this->get(route('public-cars.index', $filters))->assertOk();
        $page->assertSee('name="request_delivery" value="1"', false)->assertSee('name="delivery_address" value="'.self::ADDRESS.'"', false);
        $next = $page->viewData('results')->nextPageUrl();
        parse_str(parse_url($next, PHP_URL_QUERY), $query);
        $this->assertSame('1', $query['request_delivery']);
        $this->assertSame(self::ADDRESS, $query['delivery_address']);
        $this->get($next)->assertOk()->assertViewHas('results', fn ($rows) => $rows->currentPage() === 2 && $rows->total() === 2);
    }

    public function test_custom_pickup_cannot_open_an_offer_whose_service_was_disabled(): void
    {
        $delivery = $this->airport();
        $filters = $this->custom(['place_id' => $delivery->public_pickup_place_id]);
        $delivery->update(['custom_delivery_enabled' => false]);
        $this->get(route('public-cars.index', $filters))->assertOk()->assertViewHas('results', fn ($rows) => $rows->isEmpty());
        foreach (['public-cars.show', 'public-cars.booking.create'] as $name) {
            $this->get(route($name, ['pricelist' => 1] + $filters))->assertOk()->assertViewHas('car', null);
        }
    }

    public function test_custom_address_is_validated_and_unused_addresses_are_excluded(): void
    {
        $this->airport();
        foreach (['', 'breve', str_repeat('a', 501), ['unexpected']] as $address) {
            $this->get(route('public-cars.index', $this->custom(['delivery_address' => $address])))
                ->assertRedirect(route('public-cars.index'))->assertSessionHasErrors('delivery_address');
        }
        $this->flushSession();
        $this->get(route('public-cars.index', $this->custom(['request_delivery' => 0])))
            ->assertOk()->assertViewHas('filters', fn ($filters) => !array_key_exists('delivery_address', $filters))
            ->assertDontSee(self::ADDRESS);
    }

    public function test_an_address_is_escaped_on_search_detail_and_booking_pages(): void
    {
        $delivery = $this->airport();
        $address = 'Hotel dimostrativo <script>alert("fixture")</script>, Bari';
        $filters = $this->custom(['place_id' => $delivery->public_pickup_place_id, 'delivery_address' => $address]);
        foreach (['public-cars.index', 'public-cars.show', 'public-cars.booking.create'] as $name) {
            $this->get(route($name, ['pricelist' => 1] + $filters))->assertOk()
                ->assertSee($address)->assertDontSee('<script>alert("fixture")</script>', false);
        }
    }
}
