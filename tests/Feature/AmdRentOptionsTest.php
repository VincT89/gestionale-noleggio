<?php

namespace Tests\Feature;

use App\Domain\Fees\AdminFeeResolver;
use App\Models\{AmdRentEnquiry, AmdRentOption, Customer, Location, PublicBooking, PublicDeliveryLocation, Vehicle, VehicleProduct};
use App\Services\AmdRent\{BookingPayments, DeliveryQuotes};
use App\Services\Contracts\GenerateRentalContract;
use App\Services\Geocoding\PlaceSelection;
use Illuminate\Support\Facades\{DB, Http, Storage, URL, View};
use Illuminate\Testing\TestResponse;
use Tests\Support\PublicBookingTestCase;

class AmdRentOptionsTest extends PublicBookingTestCase
{
    public function test_a_renter_cannot_access_or_publish_platform_options(): void
    {
        $this->actingAs($this->publisher(2, 'renter'));
        $this->get(route('amd-rent.options.index'))->assertForbidden();
        $this->get(route('amd-rent.options.create', [2, 'standard']))->assertForbidden();
        $this->post(route('amd-rent.options.store', [2, 'plus']), ['scope' => 'all'])->assertForbidden();
        $this->assertDatabaseCount('amd_rent_options', 0);
    }

    public function test_admin_can_prepare_and_publish_terms_for_the_correct_renter_and_group(): void
    {
        $product = VehicleProduct::create(['name' => 'Panda dimostrativa']);
        $this->offer(1, 2, vehicle: ['vehicle_product_id' => $product->id]);
        $this->offer(2, 3);
        $store = route('amd-rent.options.store', [2, 'plus']);
        $this->actingAs($this->publisher());
        $this->get(route('amd-rent.options.create', [2, 'plus']))->assertOk()
            ->assertSee('Panda dimostrativa')->assertDontSee('TESTPLATE2');
        $this->post($store, ['scope' => 'vehicle:2'])->assertSessionHasErrors('scope');
        $this->post($store, ['scope' => 'product:'.$product->id])->assertRedirect()->assertSessionHasNoErrors();
        $draft = AmdRentOption::sole();
        $this->assertFalse($draft->published);
        $this->assertNull($draft->price_cents);
        $edit = route('amd-rent.options.edit', $draft);
        $update = route('amd-rent.options.update', $draft);
        $this->get($edit)->assertOk()->assertSee('Bozza');

        $fields = ['scope' => 'product:'.$product->id, 'revision' => 1, 'published' => 1];
        $this->put($update, $fields)->assertSessionHasErrors(['description', 'exclusions', 'protection_type', 'price']);
        $this->put($update, $fields + ['protection_type' => 'policy', 'terms_url' => 'javascript:alert(1)'])
            ->assertSessionHasErrors(['provider', 'terms_url']);
        $terms = $fields + ['description' => 'Condizioni dimostrative', 'exclusions' => 'Esclusioni dimostrative',
            'protection_type' => 'reduction', 'price' => '10.50', 'price_unit' => 'day',
            'coverage' => ['rca' => ['included' => 1, 'franchise' => '0']], 'depositable' => 1];
        $this->put($update, $terms)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($draft->fresh()->published);
        $this->assertSame(1050, $draft->fresh()->price_cents);
        $this->assertSame(['rca' => 0], $draft->fresh()->coverage);
        $this->put($update, $terms)->assertConflict();
        $this->post($store, ['scope' => 'product:'.$product->id])->assertSessionHasErrors('scope');
    }

    public function test_protection_preview_contains_only_the_renters_eligible_vehicles_and_their_franchises(): void
    {
        $group = VehicleProduct::create(['name' => 'Gruppo prova anteprima']);
        $member = ['vehicle_product_id' => $group->id];
        $this->offer(1, 2, vehicle: $member + ['insurance_rca_cents' => 50000, 'insurance_furto_cents' => 0]);
        $this->offer(2, 2, price: ['status' => 'draft'], vehicle: $member + ['insurance_rca_cents' => 75000]);
        $this->offer(3, 3, vehicle: $member + ['insurance_rca_cents' => 100000]);
        $this->offer(4, 2, vehicle: $member + ['is_active' => false]);

        $this->actingAs($this->publisher());
        $form = $this->get(route('amd-rent.options.create', [2, 'standard']))->assertOk()
            ->assertSee('Veicolo di anteprima')->assertDontSee('TESTPLATE3')->assertDontSee('TESTPLATE4')
            ->assertDontSee('PRIVATE-VIN-1');
        $vehicles = $form->viewData('previewVehicles');
        $this->assertSame(['1', '2'], array_column($vehicles, 'id'));
        $this->assertSame('product:'.$group->id, $vehicles[0]['group']);
        $this->assertSame(['rca' => '50000', 'kasko' => null, 'furto_incendio' => '0', 'cristalli' => null], $vehicles[0]['franchises']);
        $this->assertSame(['rca' => '75000', 'kasko' => null, 'furto_incendio' => null, 'cristalli' => null], $vehicles[1]['franchises']);
    }

    public function test_checkout_resolves_the_actual_vehicles_franchise_group_and_supplier(): void
    {
        $product = VehicleProduct::create(['name' => 'Panda dimostrativa']);
        $this->offer(1, vehicle: ['vehicle_product_id' => $product->id, 'insurance_kasko_cents' => 130000]);
        $this->offer(2, 2, vehicle: ['vehicle_product_id' => $product->id, 'insurance_kasko_cents' => 100000]);
        DB::table('vehicle_assignments')->insert(['vehicle_id' => 2, 'renter_org_id' => 2, 'start_at' => '2026-01-01']);
        $this->option('standard', ['coverage' => ['kasko' => null]]);
        $this->option('plus', ['published' => false, 'description' => 'Condizioni in bozza']);
        $one = $this->option('service', ['name' => 'Servizio per tutto il gruppo', 'vehicle_product_id' => $product->id]);
        $two = $this->option('service', ['name' => 'Servizio riservato al veicolo due', 'organization_id' => 2, 'vehicle_id' => 2]);
        $first = $this->checkout()->assertSee('1.300,00 €')->assertSee($one->name)
            ->assertDontSee($two->name)->assertDontSee('Condizioni in bozza');
        $this->assertSame([$one->id], array_column($first->viewData('bookingOptions')['extras'], 'id'));
        $document = new \DOMDocument();
        $document->loadHTML($first->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $this->assertFalse($document->getElementById('booking-extra-'.$one->id)->hasAttribute('checked'));

        $this->checkout(2)->assertSee($two->name)->assertDontSee($one->name)->assertDontSee('1.300,00 €');
        $this->post(route('public-cars.booking.store', 1), $this->form($first) + ['extras' => [$two->id], 'displayed_total_cents' => 31000])
            ->assertSessionHasErrors('extras');
        $this->assertDatabaseCount('public_bookings', 0);
    }

    public function test_admin_can_configure_the_customers_pricing_and_franchise_multipliers(): void
    {
        $this->offer(vehicle: ['insurance_rca_cents' => 50000, 'insurance_furto_cents' => 150000]);
        $this->actingAs($this->publisher());
        $standard = ['scope' => 'all', 'published' => 1, 'coverage' => [
            'rca' => ['multiplier' => '3'], 'furto_incendio' => ['included' => 1, 'multiplier' => '2'],
        ]];
        $standardStore = route('amd-rent.options.store', [1, 'standard']);
        $ambiguous = $standard;
        $ambiguous['coverage']['rca']['franchise'] = 500;
        $this->post($standardStore, $ambiguous)->assertSessionHasErrors('coverage.rca.multiplier');
        $this->post($standardStore, $standard)->assertRedirect()->assertSessionHasNoErrors();

        $plus = ['scope' => 'all', 'published' => 1, 'description' => 'Protezione dimostrativa',
            'exclusions' => 'Condizioni di collaudo', 'price_unit' => 'multiplier', 'protection_type' => 'reduction',
            'coverage' => ['rca' => ['included' => 1, 'multiplier' => '1'], 'furto_incendio' => ['included' => 1, 'multiplier' => '1']]];
        $plusStore = route('amd-rent.options.store', [1, 'plus']);
        $this->post($plusStore, $plus)->assertSessionHasErrors('price_multiplier');
        $this->post($plusStore, $plus + ['price_multiplier' => '0.88'])->assertSessionHasErrors('price_multiplier');
        $this->post($plusStore, $plus + ['price_multiplier' => '1.88'])->assertRedirect()->assertSessionHasNoErrors();

        $this->get(route('amd-rent.options.index', ['organization_id' => 1]))->assertOk()->assertSee('Tariffa auto ×1,88');
        $option = AmdRentOption::where('kind', 'plus')->sole();
        $page = $this->checkout(filters: ['return_at' => '2026-09-11T10:00'])
            ->assertSee('+88,00 € per il noleggio')->assertSee('188,00 €')->assertSee('3.000,00 €')->assertSee('500,00 €');
        $this->post(route('public-cars.booking.store', 1), $this->form($page) + [
            'extras' => [$option->id], 'displayed_total_cents' => 18800,
        ])->assertStatus(303);
        $booking = PublicBooking::sole();
        $this->assertSame(18800, $booking->total_cents);
        $this->assertSame(300000, $booking->quote_snapshot['standard_protection']['coverage']['furto_incendio']['franchise_cents']);
        $this->assertSame(150000, $booking->quote_snapshot['standard_protection']['coverage']['rca']['franchise_cents']);
        $this->assertSame('1500.00', $booking->rental->coverage->franchise_furto_incendio);
        $this->assertSame('500.00', $booking->rental->coverage->franchise_rca);
        $this->assertSame(8800, $booking->quote_snapshot['selected_extras'][0]['total_cents']);
    }

    public function test_group_multipliers_use_the_selected_vehicles_amounts_and_require_the_base_franchise(): void
    {
        $product = VehicleProduct::create(['name' => 'Gruppo per collaudo']);
        $this->offer(1, price: ['base_daily_cents' => 12001],
            vehicle: ['vehicle_product_id' => $product->id, 'insurance_rca_cents' => 60000, 'insurance_furto_cents' => 125000]);
        $this->offer(2, vehicle: ['vehicle_product_id' => $product->id, 'insurance_rca_cents' => 60000]);
        $target = ['vehicle_product_id' => $product->id];
        $this->option('standard', $target + ['coverage' => ['furto_incendio' => null], 'franchise_multipliers' => ['rca' => 30000, 'furto_incendio' => 20000]]);
        $this->option('plus', $target + ['price_unit' => 'multiplier', 'price_multiplier_bps' => 18800, 'price_cents' => null,
            'coverage' => ['rca' => null, 'furto_incendio' => null], 'franchise_multipliers' => ['rca' => 10000, 'furto_incendio' => 10000]]);

        $first = $this->checkout(filters: ['return_at' => '2026-09-11T10:00']);
        $first->assertSee('225,62 €')->assertSee('2.500,00 €')->assertSee('1.250,00 €');
        $second = $this->checkout(2, ['return_at' => '2026-09-11T10:00']);
        $second->assertSee('franchigia da verificare')->assertDontSee('Con AMD Plus');
        $this->assertSame([], $second->viewData('bookingOptions')['extras']);
    }

    public function test_invalidated_prices_and_unreviewed_totals_are_stopped_before_creating_a_booking(): void
    {
        $this->offer();
        $extra = $this->option('service', ['name' => 'Accessorio dimostrativo']);
        $form = $this->form($this->checkout());
        $extra->update(['price_cents' => 2000]);
        $target = route('public-cars.booking.store', 1);
        $first = $this->post($target, $form + ['extras' => [$extra->id], 'displayed_total_cents' => 31000])->assertSessionHasErrors('extras');
        $this->assertDatabaseCount('rentals', 0);
        $refreshed = $this->get($first->headers->get('Location'))->assertOk();
        $this->assertSame(32000, $refreshed->viewData('checkoutQuote')['total_cents']);
        $second = $this->post($target, $this->form($refreshed) + ['extras' => [$extra->id], 'displayed_total_cents' => 30000])
            ->assertSessionHasErrors('extras');
        $this->assertDatabaseCount('rentals', 0);

        $reviewed = $this->get($second->headers->get('Location'))->assertOk();
        $this->post($target, $this->form($reviewed) + ['extras' => [$extra->id], 'displayed_total_cents' => 32000])->assertStatus(303);
        $this->assertSame(32000, PublicBooking::sole()->total_cents);
    }

    public function test_a_separate_policy_is_recorded_and_leaves_the_contractual_franchise_at_its_agreed_value(): void
    {
        $this->offer(vehicle: ['insurance_kasko_cents' => 125000]);
        $this->option('standard', ['coverage' => ['kasko' => null]]);
        $plus = $this->option('plus', ['protection_type' => 'policy', 'provider' => 'Fornitore di collaudo',
            'terms_url' => 'https://example.test/condizioni', 'coverage' => []]);
        $checkout = $this->checkout()->assertSee('Fornitore di collaudo')->assertSee('https://example.test/condizioni');
        $this->post(route('public-cars.booking.store', 1), $this->form($checkout) + ['extras' => [$plus->id], 'displayed_total_cents' => 31000])
            ->assertStatus(303);
        $booking = PublicBooking::sole();
        $this->assertSame('1250.00', $booking->rental->coverage->franchise_kasko);
        $this->get($booking->confirmationUrl())->assertOk()->assertSee('Polizza assicurativa separata')->assertSee('1.250,00 €');
    }

    public function test_payment_confirmation_and_contract_keep_frozen_amounts_and_coverages(): void
    {
        (require database_path('migrations/2025_11_08_192103_create_rental_charges_table.php'))->up();
        (require database_path('migrations/2026_09_09_140000_add_payment_reference_and_request_key_to_rental_charges.php'))->up();
        (require database_path('migrations/2026_01_15_102406_add_second_driver_daily_cents_to_vehicle_pricelists.php'))->up();
        $this->offer(price: ['second_driver_daily_cents' => 900], vehicle: ['insurance_kasko_cents' => 125000]);
        $this->option('standard', ['coverage' => ['kasko' => null]]);
        $plus = $this->option('plus', ['price_cents' => 1000, 'price_unit' => 'day', 'depositable' => true, 'coverage' => ['kasko' => 0]]);
        $driver = $this->option('service', ['name' => 'Seconda guida dimostrativa', 'price_cents' => 2500,
            'commissionable' => true, 'covers_second_driver' => true]);
        [$checkoutSession, $getSession] = $this->fakeStripe();

        $form = $this->form($this->checkout()->assertSee('Con AMD Plus')->assertSee('Franchigia azzerata'));
        $data = $form + ['extras' => [$plus->id, $driver->id], 'displayed_total_cents' => 35500];
        $this->post(route('public-cars.booking.store', 1), $data)->assertStatus(303)->assertRedirect($checkoutSession);
        $booking = PublicBooking::sole();
        $this->assertSame(35500, $booking->total_cents);
        $this->assertSame(4950, $booking->online_due_cents);
        Http::assertSent(fn ($request) => $request['line_items'][0]['price_data']['unit_amount'] === 4950);
        app(BookingPayments::class)->settle($getSession($booking) + ['payment_status' => 'paid', 'status' => 'complete']);
        $booking->refresh();
        $this->assertSame('paid', $booking->payment_status);
        $this->assertSame(30550, $booking->pickup_due_cents);
        $fee = app(AdminFeeResolver::class)->calculateForRental($booking->rental);
        $this->assertEquals(325, $fee['commissionable_total']);
        $this->assertEquals(48.75, $fee['amount']);
        $this->assertSame('0.00', $booking->rental->coverage->franchise_kasko);
        $this->assertSame(0, $booking->rental->contractSnapshot->pricing_snapshot['second_driver_daily_cents']);

        $plus->update(['published' => false, 'price_cents' => 4000]);
        Vehicle::findOrFail(1)->update(['insurance_kasko_cents' => 180000]);
        $this->get($booking->confirmationUrl())->assertOk()->assertSee('355,00 €')->assertSee('Seconda guida dimostrativa')
            ->assertSee('Franchigia azzerata')->assertSee('1.250,00 €')->assertDontSee('1.800,00 €');
        $this->get($booking->pdfUrl())->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertContract($booking);
    }

    public function test_selected_options_follow_a_delivery_proposal_with_a_revised_delivery_fee(): void
    {
        $this->offer(vehicle: ['insurance_rca_cents' => 50000]);
        Location::findOrFail(1)->update(['lat' => 41.12, 'lng' => 16.86]);
        $service = PublicDeliveryLocation::firstOrFail();
        $service->update(['custom_delivery_enabled' => true, 'delivery_origin_location_id' => 1,
            'delivery_radius_km' => 10, 'custom_delivery_fee_cents' => 3000]);
        $extra = $this->option('service', ['name' => 'Seggiolino dimostrativo', 'price_cents' => 1800]);
        $this->option('standard', ['coverage' => ['rca' => null], 'franchise_multipliers' => ['rca' => 30000]]);
        $plus = $this->option('plus', ['price_cents' => null, 'price_unit' => 'multiplier', 'price_multiplier_bps' => 18800,
            'coverage' => ['rca' => null], 'franchise_multipliers' => ['rca' => 10000]]);
        $this->startSession();
        $point = app(PlaceSelection::class)->issue([['label' => 'Hotel dimostrativo, Bari', 'lat' => 41.12, 'lng' => 16.86]])[0];
        $checkout = $this->checkout(filters: ['place_id' => $service->public_pickup_place_id, 'request_delivery' => 1,
            'delivery_address' => $point['label'], 'delivery_place' => $point['token']]);
        $this->post(route('public-cars.booking.store', 1), $this->form($checkout) + [
            'extras' => [$extra->id, $plus->id], 'displayed_total_cents' => 61200,
        ])->assertStatus(303);
        $case = AmdRentEnquiry::sole();
        $this->assertSame(61200, $case->booking_context['car']['total_cents']);
        $extra->update(['price_cents' => 2500, 'published' => false]);
        $plus->update(['price_multiplier_bps' => 20000]);

        $this->actingAs($this->publisher());
        $this->get(route('amd-rent.enquiries.show', $case))->assertOk()->assertSee('Seggiolino dimostrativo');
        $this->put(route('amd-rent.enquiries.update', $case), ['revision' => 1, 'status' => 'quoted', 'delivery_fee' => 40,
            'quote_valid_until' => '2026-09-09'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get($case->publicUrl())->assertOk()->assertSee('622,00 €');
        DB::table('vehicle_pricelists')->where('id', 1)->update(['base_daily_cents' => 11000]);
        $review = $this->post(URL::signedRoute('public-enquiries.accept', ['reference' => $case->reference]), ['accept_quote' => 1])
            ->assertOk()->assertSee('Seggiolino dimostrativo')->assertDontSee('name="extras[]"', false);
        $this->assertSame(67840, $review->viewData('car')['total_cents']);
        $form = $this->form($review);
        $this->post(route('public-cars.booking.store', 1), $form + ['extras' => [$extra->id]])->assertSessionHasErrors('extras');
        $this->post(route('public-cars.booking.store', 1), $form)->assertStatus(303);
        $booking = PublicBooking::sole();
        $this->assertSame(67840, $booking->total_cents);
        $accepted = array_column($booking->quote_snapshot['selected_extras'], null, 'id');
        $this->assertSame(1800, $accepted[$extra->id]['total_cents']);
        $this->assertSame(29040, $accepted[$plus->id]['total_cents']);
        $this->assertSame(18800, $accepted[$plus->id]['price_multiplier_bps']);
    }

    private function option(string $kind, array $fields = []): AmdRentOption
    {
        $defaults = ['organization_id' => 1, 'kind' => $kind, 'name' => AmdRentOption::KINDS[$kind], 'published' => true];
        if ($kind !== 'standard') {
            $defaults += ['price_unit' => 'rental', 'price_cents' => 1000];
        }
        if ($kind === 'plus') {
            $defaults += ['protection_type' => 'reduction', 'description' => 'Protezione dimostrativa', 'exclusions' => 'Condizioni di prova',
                'coverage' => ['rca' => 0]];
        }

        return AmdRentOption::create($fields + $defaults);
    }

    private function checkout(int $pricelist = 1, array $filters = []): TestResponse
    {
        return $this->get(route('public-cars.booking.create', ['pricelist' => $pricelist] + $this->period($filters)))->assertOk();
    }

    private function form(TestResponse $page): array
    {
        return $page->viewData('filters') + [
            'checkout_token' => $page->viewData('checkoutToken'),
            'first_name' => 'Cliente', 'last_name' => 'Di prova', 'email' => 'cliente@example.test',
            'phone' => '+390800000000', 'accept_summary' => 1,
        ];
    }

    private function fakeStripe(): array
    {
        config(['amd_rent.payment_mode' => 'stripe', 'amd_rent.stripe_secret' => 'sk_test_fixture',
            'amd_rent.stripe_webhook_secret' => 'whsec_fixture', 'amd_rent.stripe_live' => false]);
        $checkoutUrl = 'https://checkout.stripe.com/c/pay/cs_test_options';
        $session = fn (PublicBooking $booking) => [
            'id' => 'cs_test_options', 'client_reference_id' => $booking->reference,
            'metadata' => ['booking_reference' => $booking->reference], 'livemode' => false, 'mode' => 'payment',
            'currency' => 'eur', 'amount_total' => $booking->online_due_cents, 'url' => $checkoutUrl,
        ];
        Http::preventStrayRequests();
        Http::fake(['https://api.stripe.com/v1/checkout/sessions' => fn ($request) => Http::response(
            $session(PublicBooking::where('reference', $request['client_reference_id'])->firstOrFail()) + ['payment_status' => 'unpaid', 'status' => 'open']
        )]);

        return [$checkoutUrl, $session];
    }

    private function assertContract(PublicBooking $booking): void
    {
        (require database_path('migrations/2026_09_09_160000_create_rental_extensions_table.php'))->up();
        (require database_path('migrations/2026_01_09_184526_add_second_driver_and_final_amount_override_to_rentals_table.php'))->up();
        $disk = Storage::fake('amd-rent-options-test');
        config(['filesystems.default' => 'amd-rent-options-test',
            'filesystems.disks.amd-rent-options-test' => ['driver' => 'local', 'root' => $disk->path('')]]);
        $second = Customer::create(['name' => 'Secondo conducente di prova', 'organization_id' => $booking->organization_id]);
        $booking->rental->update(['second_driver_id' => $second->id]);
        $captured = [];
        View::composer('contracts.rental', function ($view) use (&$captured) {
            $captured = $view->getData();
        });

        $pdf = app(GenerateRentalContract::class)->handle($booking->rental, forceUnsigned: true);
        $this->assertFileExists($pdf->getPath());
        $this->assertEquals(0, $captured['franchigie']['kasko']);
        $this->assertSame(35500, $captured['pricing_totals']['computed_total_cents']);
        $this->assertSame(0, $captured['pricing_totals']['second_driver_cents']);
    }
}
