<?php

namespace Tests\Feature;

use App\Models\{AmdRentEnquiry, Location, PublicBooking, PublicDeliveryLocation};
use App\Services\Geocoding\PlaceSelection;
use Illuminate\Support\Facades\{DB, Http, URL};
use Tests\Support\PublicBookingTestCase;

class PublicCustomReturnTest extends PublicBookingTestCase
{
    private const PICKUP = 'Hotel dimostrativo, via di prova 10, Bari';
    private const RETURN = 'Aeroporto dimostrativo, terminal partenze, Bari';

    private function filters(array $extra = []): array
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->offer();
        Location::findOrFail(1)->update(['lat' => 41.12, 'lng' => 16.86]);
        PublicDeliveryLocation::firstOrFail()->update(['custom_delivery_enabled' => true,
            'delivery_origin_location_id' => 1, 'delivery_radius_km' => 10]);
        $this->startSession();
        $token = app(PlaceSelection::class)->issue([['label' => self::PICKUP, 'lat' => 41.12, 'lng' => 16.86]])[0]['token'];
        $returnToken = app(PlaceSelection::class)->issue([['label' => $extra['return_address'] ?? self::RETURN,
            'lat' => 41.1387594, 'lng' => 16.7651234, 'source' => 'map']], 'public-return')[0]['token'];
        return array_replace($this->period(), ['request_delivery' => 1, 'delivery_address' => self::PICKUP,
            'delivery_place' => $token, 'request_custom_return' => 1, 'return_address' => self::RETURN, 'return_place' => $returnToken], $extra);
    }

    private function contact(): array
    {
        return ['first_name' => 'Cliente', 'last_name' => 'Dimostrativo', 'email' => 'return@example.test',
            'phone' => '+393200000000', 'accept_summary' => 1];
    }

    private function form(array $filters): array
    {
        $page = $this->get(route('public-cars.booking.create', ['pricelist' => 1] + $filters))->assertOk();
        return $this->contact() + $page->viewData('filters') + ['checkout_token' => $page->viewData('checkoutToken')];
    }

    private function enquiry(): AmdRentEnquiry
    {
        $this->post(route('public-cars.booking.store', 1), $this->form($this->filters()))->assertStatus(303)->assertSessionHasNoErrors();
        return AmdRentEnquiry::firstOrFail();
    }

    public function test_custom_return_needs_no_listed_return_point_and_saves_both_addresses(): void
    {
        $filters = $this->filters();
        unset($filters['place_id']);
        $this->get(route('public-cars.show', ['pricelist' => 1] + $filters))->assertOk()
            ->assertSee('Voglio riconsegnare l’auto in un altro luogo')->assertSee(self::RETURN);
        $form = $this->form($filters);
        $page = $this->get(route('public-cars.booking.create', ['pricelist' => 1] + $filters))->assertOk();
        $page->assertSee(self::PICKUP)->assertSee(self::RETURN)->assertSee('Richiedi ritiro e riconsegna');
        $this->assertSame(self::RETURN, $page->viewData('car')['return_address']);
        $this->assertNotSame(self::RETURN, $page->viewData('car')['location']);
        $response = $this->post(route('public-cars.booking.store', 1), $form)->assertStatus(303)->assertSessionHasNoErrors();
        $case = AmdRentEnquiry::firstOrFail();
        $this->assertSame(self::PICKUP, $case->delivery_address);
        $this->assertSame(self::RETURN, $case->booking_context['return_address']);
        $this->get($response->headers->get('Location'))->assertOk()->assertSee(self::RETURN)->assertSee('Riconsegna richiesta');
        $this->post(route('public-cars.booking.store', 1), $form)->assertStatus(303);
        $this->assertDatabaseCount('amd_rent_enquiries', 1);
        $this->assertDatabaseCount('public_bookings', 0);
        $this->assertDatabaseCount('rentals', 0);
        Http::assertNothingSent();
    }

    public function test_changed_or_removed_return_cannot_bypass_the_signed_summary(): void
    {
        $form = $this->form($this->filters());
        foreach ([['return_address' => 'Altro aeroporto dimostrativo, Roma'], ['request_custom_return' => 0]] as $change) {
            $response = $this->post(route('public-cars.booking.store', 1), array_replace($form, $change));
            $response->assertRedirect()->assertSessionHasErrors(isset($change['return_address']) ? 'return_place' : 'return_address');
            parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
            $this->assertSame(self::RETURN, $query['return_address']);
        }
        $this->post(route('public-cars.booking.store', 1), array_replace($form, ['request_delivery' => 0]))->assertRedirect()->assertSessionHasErrors();
        $this->assertDatabaseCount('amd_rent_enquiries', 0);
        $this->assertDatabaseCount('public_bookings', 0);
    }

    public function test_validation_errors_preserve_custom_return_and_returning_to_the_car(): void
    {
        $form = $this->form($this->filters());
        $response = $this->post(route('public-cars.booking.store', 1), array_replace($form, ['email' => 'invalid']));
        $response->assertRedirect()->assertSessionHasErrors('email');
        $page = $this->get($response->headers->get('Location'))->assertOk()->assertSee(self::RETURN)->assertSee(self::PICKUP);
        $this->get(route('public-cars.show', ['pricelist' => 1] + $page->viewData('filters')))->assertOk()->assertSee(self::RETURN);
    }

    public function test_invalid_return_addresses_are_rejected_and_unused_values_are_excluded(): void
    {
        $filters = $this->filters();
        foreach (['', 'airport', str_repeat('x', 501), ['unexpected']] as $address) {
            $this->get(route('public-cars.booking.create', ['pricelist' => 1] + array_replace($filters, ['return_address' => $address])))
                ->assertRedirect()->assertSessionHasErrors('return_address');
        }
        $this->flushSession();
        $normal = $this->period(['place_id' => PublicDeliveryLocation::firstOrFail()->public_pickup_place_id,
            'request_delivery' => 0, 'request_custom_return' => 1, 'return_address' => self::RETURN]);
        $page = $this->get(route('public-cars.booking.create', ['pricelist' => 1] + $normal))->assertOk()->assertDontSee(self::RETURN);
        $this->assertArrayNotHasKey('return_address', $page->viewData('car'));
    }

    public function test_return_address_is_escaped_on_customer_and_supplier_pages(): void
    {
        $address = 'Aeroporto <script>alert("fixture")</script>, Comune di prova';
        $form = $this->form($this->filters(['return_address' => $address]));
        $this->post(route('public-cars.booking.store', 1), $form)->assertStatus(303);
        $case = AmdRentEnquiry::firstOrFail();
        $this->get($case->publicUrl())->assertOk()->assertSee($address)->assertDontSee('<script>alert("fixture")</script>', false);
        $this->actingAs($this->publisher())->get(route('amd-rent.enquiries.show', $case))->assertOk()
            ->assertSee($address)->assertDontSee('<script>alert("fixture")</script>', false);
    }

    public function test_supplier_must_confirm_custom_return_before_publishing_and_other_suppliers_cannot(): void
    {
        $case = $this->enquiry();
        $quote = ['revision' => $case->revision, 'status' => 'quoted', 'delivery_fee' => '35.00', 'quote_valid_until' => now()->addDay()->toDateString()];
        $this->actingAs($this->publisher())->put(route('amd-rent.enquiries.update', $case), $quote)
            ->assertRedirect()->assertSessionHasErrors('confirm_custom_return');
        $this->assertSame('new', $case->fresh()->status);
        $this->get(route('amd-rent.enquiries.show', $case))->assertOk()
            ->assertSee('value="quoted" selected', false)->assertSee('value="35.00"', false);
        $this->put(route('amd-rent.enquiries.update', $case), $quote + ['confirm_custom_return' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('quoted', $case->fresh()->status);
        $this->assertSame(3500, $case->fresh()->delivery_fee_cents);
        $this->flushSession();
        $this->actingAs($this->publisher(2, 'renter'))->put(route('amd-rent.enquiries.update', $case), array_replace($quote, ['revision' => $case->fresh()->revision, 'confirm_custom_return' => 1]))->assertNotFound();
    }

    public function test_confirmed_return_survives_expired_search_session_booking_and_documents(): void
    {
        $case = $this->enquiry();
        $case->update(['status' => 'quoted', 'delivery_fee_cents' => 3500, 'quote_expires_at' => now()->addDay(), 'revision' => 2]);
        DB::table('amd_rent_settings')->insert(['id' => 1, 'delivery_commission_bps' => 0]);
        $this->flushSession();
        $page = $this->post(URL::signedRoute('public-enquiries.accept', ['reference' => $case->reference]), ['accept_quote' => 1])->assertOk()
            ->assertSee(self::PICKUP)->assertSee(self::RETURN);
        $form = $this->contact() + $page->viewData('filters') + ['checkout_token' => $page->viewData('checkoutToken')];
        $this->post(route('public-cars.booking.store', 1), $form)->assertStatus(303)->assertSessionHasNoErrors();
        $booking = PublicBooking::firstOrFail();
        $this->assertSame(self::RETURN, $booking->quote_snapshot['return_address']);
        $this->assertSame(41.1387594, $booking->quote_snapshot['return_destination']['lat']);
        $this->assertSame($booking->quote_snapshot['return_destination'], $booking->rental->contractSnapshot->pricing_snapshot['return_destination']);
        $this->assertSame(self::PICKUP, $booking->quote_snapshot['delivery_address']);
        $this->assertSame(33500, $booking->total_cents);
        $this->assertNull($booking->rental->return_location_id);
        $this->assertSame(self::RETURN, $booking->rental->returnLocationLabel());
        $this->assertStringContainsString(self::RETURN, $booking->rental->notes);
        $this->get($booking->confirmationUrl())->assertOk()->assertSee(self::RETURN);
        $this->assertStringContainsString(self::RETURN, view('pdfs.public-booking', ['booking' => $booking, 'car' => $booking->quote_snapshot])->render());
        $booking->rental->update(['return_location_id' => 1]);
        $this->assertSame('Sede di prova 1', $booking->rental->fresh()->returnLocationLabel());
        Http::assertNothingSent();
    }

    public function test_changed_return_in_a_proposal_invalidates_a_previous_checkout(): void
    {
        $case = $this->enquiry();
        $case->update(['status' => 'quoted', 'delivery_fee_cents' => 3500, 'quote_expires_at' => now()->addDay(), 'revision' => 2]);
        DB::table('amd_rent_settings')->insert(['id' => 1, 'delivery_commission_bps' => 0]);
        $page = $this->post(URL::signedRoute('public-enquiries.accept', ['reference' => $case->reference]), ['accept_quote' => 1])->assertOk();
        $case->update(['booking_context' => array_replace($case->booking_context, ['return_address' => 'Altro aeroporto dimostrativo, Roma'])]);
        $this->post(route('public-cars.booking.store', 1), $this->contact() + $page->viewData('filters') + ['checkout_token' => $page->viewData('checkoutToken')])
            ->assertRedirect($case->publicUrl())->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('public_bookings', 0);
    }

    public function test_back_to_search_preserves_custom_return_until_a_listed_point_replaces_it(): void
    {
        $form = $this->form($this->filters());
        $filters = array_intersect_key($form, array_flip(['pickup_at', 'return_at', 'place_id', 'request_delivery', 'delivery_address', 'delivery_place', 'request_custom_return', 'return_address', 'return_place']));
        $page = $this->get(route('public-cars.index', $filters))->assertOk()->assertSee(self::RETURN);
        $this->assertArrayNotHasKey('place_id', $page->viewData('filters'));
        $this->assertSame(self::RETURN, $page->viewData('filters')['return_address']);
        $page = $this->get(route('public-cars.index', $filters + ['destination' => (string) $form['place_id']]))->assertOk()->assertDontSee(self::RETURN);
        $this->assertArrayNotHasKey('return_address', $page->viewData('filters'));
        $this->assertSame($form['place_id'], $page->viewData('filters')['place_id']);
    }

    public function test_return_requires_a_current_selection_from_the_same_session_and_its_own_context(): void
    {
        $filters = $this->filters();
        foreach ([null, 'not-a-token', (string) \Illuminate\Support\Str::uuid(), $filters['delivery_place']] as $invalid) {
            $this->get(route('public-cars.booking.create', ['pricelist' => 1] + array_replace($filters, ['return_place' => $invalid])))
                ->assertRedirect()->assertSessionHasErrors('return_place');
        }
        $selections = session('amd_place_selections');
        $this->flushSession();
        $this->get(route('public-cars.booking.create', ['pricelist' => 1] + $filters))->assertRedirect()->assertSessionHasErrors('return_place');
        $this->withSession(['amd_place_selections' => $selections]);
        $this->travel(3)->hours();
        $this->get(route('public-cars.booking.create', ['pricelist' => 1] + $filters))->assertRedirect()->assertSessionHasErrors('return_place');
        $this->assertDatabaseCount('amd_rent_enquiries', 0);
    }

    public function test_return_coordinates_are_bound_to_the_summary_even_when_the_label_is_unchanged(): void
    {
        $form = $this->form($this->filters());
        $different = app(PlaceSelection::class)->issue([['label' => self::RETURN, 'lat' => 42.0, 'lng' => 17.0, 'source' => 'map']], 'public-return')[0]['token'];
        $response = $this->post(route('public-cars.booking.store', 1), array_replace($form, ['return_place' => $different]))
            ->assertRedirect()->assertSessionHasErrors('return_place');
        $this->assertDatabaseCount('amd_rent_enquiries', 0);
        $restored = $this->get($response->headers->get('Location'))->assertOk();
        $this->assertSame(41.1387594, $restored->viewData('car')['return_destination']['lat']);
        $this->post(route('public-cars.booking.store', 1), $form + ['map_lat' => 42, 'map_lng' => 17, 'return_lat' => 42])
            ->assertStatus(303)->assertSessionHasNoErrors();
        $case = AmdRentEnquiry::firstOrFail();
        $this->assertSame(41.1387594, $case->booking_context['return_destination']['lat']);
        $this->assertSame(41.12, $case->booking_context['delivery_destination']['lat']);
        $this->actingAs($this->publisher())->get(route('amd-rent.enquiries.show', $case))->assertOk()
            ->assertSee('Vedi il punto di riconsegna indicato sulla mappa')->assertSee('mlat=41.1387594', false);
    }

    public function test_return_point_fingerprint_handles_database_json_order_but_rejects_coordinate_changes(): void
    {
        $case = $this->enquiry();
        $case->update(['status' => 'quoted', 'delivery_fee_cents' => 3500, 'quote_expires_at' => now()->addDay(), 'revision' => 2]);
        DB::table('amd_rent_settings')->insert(['id' => 1, 'delivery_commission_bps' => 0]);
        $page = $this->post(URL::signedRoute('public-enquiries.accept', ['reference' => $case->reference]), ['accept_quote' => 1])->assertOk();
        $car = $page->viewData('car');
        $reordered = array_replace($car, ['return_destination' => array_reverse($car['return_destination'], true)]);
        $this->assertSame(\App\Domain\Rentals\PublicBookingService::fingerprint($car), \App\Domain\Rentals\PublicBookingService::fingerprint($reordered));
        $context = $case->booking_context;
        $context['return_destination']['lat'] = 41.1389;
        $case->update(['booking_context' => $context]);
        $this->post(route('public-cars.booking.store', 1), $this->contact() + $page->viewData('filters') + ['checkout_token' => $page->viewData('checkoutToken')])
            ->assertRedirect($case->publicUrl())->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('public_bookings', 0);
    }

    public function test_legacy_approved_return_without_coordinates_remains_usable(): void
    {
        $case = $this->enquiry();
        $context = $case->booking_context;
        unset($context['return_destination'], $context['car']['return_destination']);
        $case->update(['booking_context' => $context, 'status' => 'quoted', 'delivery_fee_cents' => 3500, 'quote_expires_at' => now()->addDay(), 'revision' => 2]);
        DB::table('amd_rent_settings')->insert(['id' => 1, 'delivery_commission_bps' => 0]);
        $this->flushSession();
        $page = $this->post(URL::signedRoute('public-enquiries.accept', ['reference' => $case->reference]), ['accept_quote' => 1])->assertOk()->assertSee(self::RETURN);
        $this->post(route('public-cars.booking.store', 1), $this->contact() + $page->viewData('filters') + ['checkout_token' => $page->viewData('checkoutToken')])
            ->assertStatus(303)->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('return_destination', PublicBooking::firstOrFail()->quote_snapshot);
    }
}
