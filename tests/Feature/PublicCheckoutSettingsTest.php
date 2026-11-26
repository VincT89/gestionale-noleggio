<?php

namespace Tests\Feature;

use App\Livewire\Vehicles\Pricing;
use App\Models\{AmdRentOption, PublicBooking, RentalContractSnapshot, Vehicle, VehiclePricelist};
use App\Services\AmdRent\{BookingOptions, DeliveryQuotes, DriverAgePricing};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Http, Schema};
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PublicBookingTestCase;

class PublicCheckoutSettingsTest extends PublicBookingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        /** Match MySQL's existing sorting and publication helpers in the disposable SQLite fixture. */
        DB::connection()->getPdo()->sqliteCreateFunction('FIELD', static function ($value, ...$choices) {
            $index = array_search($value, $choices, true);
            return $index === false ? 0 : $index + 1;
        });
        DB::connection()->getPdo()->sqliteCreateFunction('IF', static fn ($condition, $yes, $no) => $condition ? $yes : $no);
        Schema::table('vehicle_pricelists', function (Blueprint $table) {
            $table->string('notes')->nullable();
            $table->unsignedInteger('second_driver_daily_cents')->nullable();
        });
    }

    protected function ageRules(): array
    {
        return ['18_21' => ['amount_cents' => 1000, 'unit' => 'day'], 'over_21' => ['amount_cents' => 500, 'unit' => 'rental']];
    }

    protected function car(array $prices = []): void
    {
        $this->offer(price: $prices, vehicle: ['insurance_rca_cents' => 50000, 'insurance_kasko_cents' => 100000]);
    }

    protected function checkout(array $filters = []): \Illuminate\Testing\TestResponse
    {
        return $this->get(route('public-cars.booking.create', ['pricelist' => 1] + $this->period($filters)))->assertOk();
    }

    protected function form(\Illuminate\Testing\TestResponse $page, array $changes = []): array
    {
        $form = array_replace($page->viewData('filters') + $this->bookingDetailsFixture() + [
            'checkout_token' => $page->viewData('checkoutToken'), 'first_name' => 'Cliente', 'last_name' => 'Dimostrativo',
            'email' => 'cliente@example.test', 'phone' => '+390800000000', 'accept_summary' => 1,
        ], $changes);
        if (!array_key_exists('birthdate', $changes) && $form['driver_age_band'] === '18_21') $form['birthdate'] = '2006-01-01';
        return $form;
    }

    public static function invalidBirthdates(): array
    {
        return ['missing' => [null], 'empty' => [''], 'invalid day' => ['2006-02-30'], 'invalid format' => ['10/09/2006'],
            'future' => ['2026-09-09'], 'under eighteen at pickup' => ['2008-09-11'], 'array' => [['1980-01-01']]];
    }

    #[DataProvider('invalidBirthdates')]
    public function test_birthdate_is_required_and_invalid_future_or_underage_dates_cannot_create_an_order(mixed $invalid): void
    {
        $this->car();
        $page = $this->checkout();
        $page->assertSee('Data di nascita del conducente')->assertSee('name="birthdate"', false);
        $this->post(route('public-cars.booking.store', 1), $this->form($page, ['birthdate' => $invalid]))
            ->assertRedirect()->assertSessionHasErrors('birthdate');
        $this->assertDatabaseCount('public_bookings', 0);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_a_young_driver_cannot_keep_an_older_band_even_when_the_prices_are_identical(): void
    {
        $this->car();
        $page = $this->checkout(['driver_age_band' => 'over_21']);
        $this->post(route('public-cars.booking.store', 1), $this->form($page, ['birthdate' => '2006-01-01']))
            ->assertSessionHasErrors('driver_age_band');
        $this->assertDatabaseCount('public_bookings', 0);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_birthdays_are_counted_at_pickup_including_eighteen_twenty_one_and_twenty_two(): void
    {
        foreach ([['2008-09-10', 18, '18_21'], ['2005-09-10', 21, '18_21'],
            ['2004-09-11', 21, '18_21'], ['2004-09-10', 22, 'over_21']] as [$birthdate, $age, $band]) {
            $this->assertSame($age, DriverAgePricing::ageAtPickup($birthdate, '2026-09-10T10:00'));
            $this->assertSame($band, DriverAgePricing::bandForAge($age));
        }
        $this->assertSame(17, DriverAgePricing::ageAtPickup('2008-09-11', '2026-09-10T10:00'));
        $this->assertNull(DriverAgePricing::bandForAge(17));
        $this->assertSame(20, DriverAgePricing::ageAtPickup('2004-02-29', '2025-02-28T23:59'));
        $this->assertSame(21, DriverAgePricing::ageAtPickup('2004-02-29', '2025-03-01T00:00'));
    }

    public function test_a_driver_turning_eighteen_by_pickup_can_book_and_the_declared_details_are_saved(): void
    {
        $this->car(['driver_age_supplements' => $this->ageRules()]);
        $page = $this->checkout(['driver_age_band' => '18_21']);
        $data = $this->form($page, ['birthdate' => '2008-09-10', 'driver_age_at_pickup' => 99]);
        $this->post(route('public-cars.booking.store', 1), $data)->assertStatus(303);
        $booking = PublicBooking::sole();
        $details = $booking->quote_snapshot['booking_details'];
        $this->assertSame('2008-09-10', $details['birthdate']);
        $this->assertSame(18, $details['driver_age_at_pickup']);
        $this->assertSame('2008-09-10', $booking->rental->customer->birthdate->toDateString());
        $this->assertSame($details, RentalContractSnapshot::sole()->pricing_snapshot['amd_rent_options']['booking_details']);
        $this->get($booking->confirmationUrl())->assertOk()->assertSee('10/09/2008')->assertSee('18 anni');
        $document = view('pdfs.public-booking', ['booking' => $booking, 'car' => $booking->quote_snapshot])->render();
        $this->assertStringContainsString('10/09/2008', $document);
        $this->assertStringContainsString('18 anni', $document);
        $this->actingAs($this->publisher())->get(route('public-bookings.index'))->assertOk()
            ->assertSee('Dati del conducente, fatturazione, protezione ed extra')->assertSee('10/09/2008')->assertSee('18 anni');
    }

    public function test_search_compares_complete_prices_for_the_selected_age_and_budget(): void
    {
        $this->car(['driver_age_supplements' => $this->ageRules()]);
        $filters = $this->period(['driver_age_band' => '18_21', 'budget' => 329]);
        $this->get(route('public-cars.index', $filters))->assertOk()->assertViewHas('results', fn ($cars) => $cars->isEmpty());
        $this->get(route('public-cars.index', array_replace($filters, ['budget' => 330])))->assertOk()
            ->assertViewHas('results', fn ($cars) => $cars->first()['total_cents'] === 33000);
        $older = $this->checkout(['driver_age_band' => 'over_21']);
        $this->assertSame(30500, $older->viewData('car')['total_cents']);
        $this->assertSame(500, $older->viewData('car')['driver_age']['total_cents']);
    }

    public function test_a_priced_age_band_cannot_be_omitted_or_changed_after_quoting(): void
    {
        $this->car(['driver_age_supplements' => $this->ageRules()]);
        $this->get(route('public-cars.booking.create', ['pricelist' => 1] + $this->period()))->assertRedirect()
            ->assertSessionHasErrors('driver_age_band');
        $page = $this->checkout(['driver_age_band' => 'over_21']);
        $this->post(route('public-cars.booking.store', 1), $this->form($page, ['driver_age_band' => '18_21']))
            ->assertSessionHasErrors('checkout_token');
        $this->assertDatabaseCount('public_bookings', 0);
    }

    public function test_company_details_privacy_age_and_mileage_are_frozen_with_the_order(): void
    {
        $this->car(['driver_age_supplements' => $this->ageRules(), 'extra_km_cents' => 75, 'deposit_cents' => 60000]);
        $page = $this->checkout(['driver_age_band' => '18_21']);
        $data = $this->form($page, ['booking_customer_type' => 'company', 'company_name' => 'Azienda dimostrativa',
            'vat_number' => '01234567890', 'sdi_code' => 'ABC1234', 'pec' => 'azienda@example.test']);
        $this->post(route('public-cars.booking.store', 1), $data)->assertStatus(303);
        $booking = PublicBooking::sole();
        $this->assertSame(33000, $booking->total_cents);
        $details = $booking->quote_snapshot['booking_details'];
        $this->assertSame('Azienda dimostrativa', $details['company_name']);
        $this->assertSame('2026-11-26', $details['privacy']['version']);
        $snapshot = RentalContractSnapshot::sole()->pricing_snapshot;
        $this->assertSame(100, $snapshot['km_daily_limit']);
        $this->assertSame(75, $snapshot['extra_km_cents']);
        $this->assertSame(60000, $snapshot['deposit_cents']);
        $this->assertSame($details, $snapshot['amd_rent_options']['booking_details']);
        VehiclePricelist::first()->update(['driver_age_supplements' => ['18_21' => ['amount_cents' => 9999, 'unit' => 'day']], 'extra_km_cents' => 99]);
        $this->get($booking->confirmationUrl())->assertOk()->assertSee('Azienda dimostrativa')->assertSee('330,00 €')->assertSee('0,75 €/km');
    }

    public function test_privacy_and_company_fields_are_validated_and_private_requests_exclude_company_data(): void
    {
        $this->car();
        $page = $this->checkout();
        $this->post(route('public-cars.booking.store', 1), $this->form($page, ['accept_privacy' => 0]))->assertSessionHasErrors('accept_privacy');
        $this->post(route('public-cars.booking.store', 1), $this->form($page, ['booking_customer_type' => 'company']))->assertSessionHasErrors(['company_name', 'vat_number']);
        $this->post(route('public-cars.booking.store', 1), $this->form($page, ['company_name' => 'Da escludere', 'vat_number' => '12345']))->assertStatus(303);
        $this->assertArrayNotHasKey('company_name', PublicBooking::sole()->quote_snapshot['booking_details']);
    }

    public function test_supplier_identity_is_hidden_until_an_order_exists(): void
    {
        $this->car();
        $this->get(route('public-cars.show', ['pricelist' => 1] + $this->period()))->assertOk()
            ->assertDontSee('Proprietario di prova')->assertDontSee('Sede di prova 1')->assertDontSee('Indirizzo di prova');
        $page = $this->checkout()->assertDontSee('Proprietario di prova')->assertDontSee('Sede di prova 1')->assertDontSee('Indirizzo di prova');
        $this->post(route('public-cars.booking.store', 1), $this->form($page))->assertStatus(303);
        $this->get(PublicBooking::sole()->confirmationUrl())->assertOk()->assertSee('Proprietario di prova')->assertSee('Sede di prova 1');
    }

    public function test_unavailable_accessories_cannot_be_selected_and_services_follow_protection(): void
    {
        $this->car();
        $blocked = AmdRentOption::create(['organization_id' => 1, 'kind' => 'service', 'name' => 'Catene dimostrative non disponibili',
            'published' => true, 'is_available' => false, 'price_unit' => 'rental', 'price_cents' => 500]);
        AmdRentOption::create(['organization_id' => 1, 'kind' => 'service', 'name' => 'Seggiolino dimostrativo',
            'published' => true, 'is_available' => true, 'price_unit' => 'day', 'price_cents' => 1000]);
        $page = $this->checkout()->assertSee('Seggiolino dimostrativo')->assertDontSee($blocked->name)
            ->assertSeeInOrder(['Protezione standard inclusa', 'Accessori e servizi aggiuntivi', 'Seggiolino dimostrativo', 'Dati di fatturazione']);
        $this->post(route('public-cars.booking.store', 1), $this->form($page, ['extras' => [$blocked->id], 'displayed_total_cents' => 30500]))->assertSessionHasErrors('extras');
        $this->assertDatabaseCount('public_bookings', 0);
    }

    public function test_plus_multiplier_excludes_the_age_supplement_and_deposit_stays_separate(): void
    {
        $this->car(['driver_age_supplements' => $this->ageRules()]);
        AmdRentOption::create(['organization_id' => 1, 'kind' => 'standard', 'name' => 'Standard', 'published' => true,
            'coverage' => ['kasko' => null], 'franchise_multipliers' => ['kasko' => 30000]]);
        $plus = AmdRentOption::create(['organization_id' => 1, 'kind' => 'plus', 'name' => 'AMD Plus', 'published' => true,
            'protection_type' => 'reduction', 'price_unit' => 'multiplier', 'price_multiplier_bps' => 18800, 'depositable' => true,
            'coverage' => ['kasko' => null], 'franchise_multipliers' => ['kasko' => 15000]]);
        $page = $this->checkout(['return_at' => '2026-09-11T10:00', 'driver_age_band' => '18_21'])
            ->assertSee('3.000,00 €')->assertSee('1.500,00 €')->assertSee('+88,00 € per il noleggio');
        $car = BookingOptions::withAgreedOptions($page->viewData('car'), $page->viewData('bookingOptions') + ['selected_extras' => $page->viewData('bookingOptions')['extras']]);
        $this->assertSame(19800, $car['total_cents']);
        $this->assertSame(2970, DeliveryQuotes::onlineDue(array_replace($car, ['customer_deposit_bps' => 1500])));
        $this->post(route('public-cars.booking.store', 1), $this->form($page, ['extras' => [$plus->id], 'displayed_total_cents' => 19800]))->assertStatus(303);
        $this->assertSame(50000, PublicBooking::sole()->deposit_cents);
        $this->assertSame('1500.00', PublicBooking::sole()->rental->coverage->franchise_kasko);
    }

    public function test_delivery_age_terms_can_be_retained_when_the_current_price_changes(): void
    {
        $car = ['total_cents' => 15000, 'rental_total_cents' => 12000, 'vehicle_rental_total_cents' => 10000,
            'driver_age' => ['band' => '18_21', 'label' => 'Da 18 a 21 anni compresi', 'unit' => 'day', 'unit_cents' => 2000, 'total_cents' => 2000]];
        $agreed = ['driver_age' => array_replace($car['driver_age'], ['unit_cents' => 1000, 'total_cents' => 1000])];
        $kept = DriverAgePricing::retainAgreed($car, $agreed);
        $this->assertSame(14000, $kept['total_cents']);
        $this->assertSame(11000, $kept['rental_total_cents']);
        $this->assertSame(10000, $kept['vehicle_rental_total_cents']);
    }

    public function test_admin_can_save_clear_age_and_mileage_settings_on_a_draft(): void
    {
        $this->car();
        $this->actingAs($this->publisher());
        Livewire::test(Pricing::class, ['vehicle' => Vehicle::first()])->call('duplicateActiveToDraft')
            ->call('setMileageMode', 'unlimited')->assertSet('km_included_per_day', null)
            ->call('setMileageMode', '100')->assertSet('km_included_per_day', 100)
            ->set('driver_age_rules.18_21.enabled', true)->set('driver_age_rules.18_21.amount', '12.50')
            ->set('driver_age_rules.18_21.unit', 'day')->call('saveDraft')->assertHasNoErrors();
        $draft = VehiclePricelist::where('status', 'draft')->sole();
        $this->assertSame(['18_21' => ['amount_cents' => 1250, 'unit' => 'day']], $draft->driver_age_supplements);
        $this->assertSame(100, $draft->km_included_per_day);
    }

    public function test_renter_keeps_the_existing_age_rules_when_saving_a_classic_pricelist(): void
    {
        $this->offer(1, 2, price: ['driver_age_supplements' => $this->ageRules()]);
        DB::table('vehicle_assignments')->insert(['vehicle_id' => 1, 'renter_org_id' => 2, 'start_at' => '2026-01-01']);
        foreach ([91 => 'vehicle_pricing.viewAny', 92 => 'vehicle_pricing.create'] as $id => $name) {
            DB::table('permissions')->insert(['id' => $id, 'name' => $name]);
            DB::table('role_has_permissions')->insert(['role_id' => 2, 'permission_id' => $id]);
        }
        $this->actingAs($this->publisher(2, 'renter'));
        Livewire::test(Pricing::class, ['vehicle' => Vehicle::first()])->call('duplicateActiveToDraft')
            ->assertDontSee('Supplementi per età del conducente')->set('driver_age_rules.18_21.enabled', true)
            ->set('driver_age_rules.18_21.amount', 0)->call('saveDraft')->assertHasNoErrors();
        $this->assertSame($this->ageRules(), VehiclePricelist::where('status', 'draft')->sole()->driver_age_supplements);
    }

    public function test_delivery_proposal_preserves_the_age_supplement_after_a_list_change(): void
    {
        $this->car(['driver_age_supplements' => $this->ageRules()]);
        \App\Models\Location::first()->update(['lat' => 41.12, 'lng' => 16.86]);
        $delivery = \App\Models\PublicDeliveryLocation::first();
        $delivery->update(['custom_delivery_enabled' => true, 'delivery_origin_location_id' => 1, 'delivery_radius_km' => 10, 'custom_delivery_fee_cents' => 3000]);
        $this->startSession();
        $point = app(\App\Services\Geocoding\PlaceSelection::class)->issue([['label' => 'Hotel dimostrativo, Bari', 'lat' => 41.12, 'lng' => 16.86]])[0];
        $page = $this->checkout(['driver_age_band' => '18_21', 'place_id' => $delivery->public_pickup_place_id,
            'request_delivery' => 1, 'delivery_address' => $point['label'], 'delivery_place' => $point['token']]);
        $this->post(route('public-cars.booking.store', 1), $this->form($page))->assertStatus(303);
        $case = \App\Models\AmdRentEnquiry::sole();
        $this->assertSame(36000, $case->booking_context['car']['total_cents']);
        $this->assertSame('2006-01-01', $case->booking_context['car']['booking_details']['birthdate']);
        $this->assertSame(20, $case->booking_context['car']['booking_details']['driver_age_at_pickup']);
        $case->update(['status' => 'quoted', 'delivery_fee_cents' => 4000, 'quote_expires_at' => '2026-09-09', 'revision' => 2]);
        VehiclePricelist::first()->update(['driver_age_supplements' => ['18_21' => ['amount_cents' => 9000, 'unit' => 'day']]]);
        $review = $this->post(\Illuminate\Support\Facades\URL::signedRoute('public-enquiries.accept', ['reference' => $case->reference]), ['accept_quote' => 1])->assertOk();
        $this->assertSame(37000, $review->viewData('car')['total_cents']);
        $this->assertSame(3000, $review->viewData('car')['driver_age']['total_cents']);
        $this->assertSame('2006-01-01', $review->viewData('bookingDetails')['birthdate']);
        $review->assertSee('value="2006-01-01"', false);
        $this->post(route('public-cars.booking.store', 1), $this->form($review))->assertStatus(303);
        $this->assertSame(37000, PublicBooking::sole()->total_cents);
        $this->assertSame(20, PublicBooking::sole()->quote_snapshot['booking_details']['driver_age_at_pickup']);
    }

    public function test_company_data_is_escaped_in_confirmations_and_documents(): void
    {
        $this->car();
        $page = $this->checkout();
        $name = '<script>alert(1)</script>';
        $this->post(route('public-cars.booking.store', 1), $this->form($page, ['booking_customer_type' => 'company',
            'company_name' => $name, 'vat_number' => '01234567890']))->assertStatus(303);
        $booking = PublicBooking::sole();
        $this->get($booking->confirmationUrl())->assertOk()->assertSee($name)->assertDontSee($name, false);
        $document = view('pdfs.public-booking', ['booking' => $booking, 'car' => $booking->quote_snapshot])->render();
        $this->assertStringContainsString(e($name), $document);
        $this->assertStringNotContainsString($name, $document);
    }
}
