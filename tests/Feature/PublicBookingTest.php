<?php

namespace Tests\Feature;

use App\Domain\Rentals\{PublicBookingService, PublicVehicleSearch};
use App\Livewire\Rentals\CreateWizard;
use App\Models\{Customer, PublicBooking, PublicRentalOffer, Rental, RentalContractSnapshot};
use App\Services\Rentals\RentalNumberAllocator;
use Illuminate\Support\Facades\{Crypt, DB, Mail, URL};
use Illuminate\Validation\ValidationException;
use Tests\Support\PublicBookingTestCase;

class PublicBookingTest extends PublicBookingTestCase
{
    private function form(int $offer = 1, bool $preview = false): array
    {
        $prefix = $preview ? 'public-cars.preview' : 'public-cars';
        $url = route($prefix.'.booking.create', ['offer' => $offer] + $this->period());
        $page = $this->get($url)->assertOk();
        $this->from($url);
        return $this->period() + ['checkout_token' => $page->viewData('checkoutToken'), 'first_name' => 'Cliente',
            'last_name' => 'Di Prova', 'email' => 'cliente@example.test', 'phone' => '+39 320 0000000', 'accept_summary' => '1'];
    }

    private function submit(array $form, int $offer = 1, bool $preview = false)
    {
        return $this->post(route(($preview ? 'public-cars.preview' : 'public-cars').'.booking.store', $offer), $form);
    }

    public function test_booking_is_confirmed_unpaid_and_immediately_removes_the_vehicle(): void
    {
        Mail::fake();
        $this->offer();
        $response = $this->submit($this->form())->assertStatus(303);
        $booking = PublicBooking::firstOrFail();
        $this->assertSame('reserved', $booking->rental->status);
        $this->assertSame('pay_at_pickup', $booking->payment_method);
        $this->assertSame(30000, $booking->total_cents);
        $this->assertSame('300.00', $booking->rental->final_amount_override);
        $this->assertSame(1, (int) $booking->rental->number_id);
        $this->assertDatabaseCount('renter_contract_number_ledger', 1);
        $this->assertSame(30000, RentalContractSnapshot::first()->pricing_snapshot['tariff_total_cents']);
        $this->assertSame(0, app(PublicVehicleSearch::class)->search(PublicRentalOffer::published(), $this->period())->count());
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('Prenotazione confermata')->assertSee($booking->reference)
            ->assertDontSee('cliente@example.test')->assertDontSee('TESTPLATE');
        Mail::assertNothingSent();
    }

    public function test_booking_goes_to_the_operating_renter_instead_of_the_vehicle_owner(): void
    {
        $this->offer(1, 2);
        DB::table('vehicle_assignments')->insert(['vehicle_id' => 1, 'renter_org_id' => 2, 'start_at' => '2026-09-08 00:00:00', 'end_at' => null]);
        $this->submit($this->form() + ['organization_id' => 3, 'total_cents' => 1])->assertStatus(303);
        $booking = PublicBooking::first();
        $this->assertSame(2, (int) $booking->organization_id);
        $this->assertSame(2, (int) $booking->rental->organization_id);
        $this->assertSame(2, (int) $booking->rental->customer->organization_id);
        $this->assertSame(2, (int) $booking->rental->pickup_location_id);
        $this->assertSame(1, (int) $booking->rental->vehicle->admin_organization_id);
        $this->assertSame(30000, $booking->total_cents);
    }

    public function test_double_submit_returns_the_same_booking_without_a_second_customer_or_ledger_entry(): void
    {
        $this->offer(); $form = $this->form();
        $first = $this->submit($form)->assertStatus(303);
        $again = $this->submit($form)->assertStatus(303);
        $this->assertSame($first->headers->get('Location'), $again->headers->get('Location'));
        foreach (['public_bookings', 'rentals', 'customers', 'renter_contract_number_ledger'] as $table) $this->assertDatabaseCount($table, 1);
    }

    public function test_second_checkout_cannot_confirm_an_already_reserved_vehicle(): void
    {
        $this->offer(); $a = $this->form(); $b = $this->form();
        $this->submit($a)->assertStatus(303);
        $this->submit($b)->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('public_bookings', 1);
    }

    public function test_new_calendar_block_is_checked_at_submission(): void
    {
        $this->offer(); $form = $this->form();
        DB::table('vehicle_blocks')->insert(['vehicle_id' => 1, 'start_at' => '2026-09-11 10:00:00', 'end_at' => null]);
        $this->submit($form)->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('rentals', 0);
    }

    public function test_price_or_deposit_changes_require_accepting_a_new_quote(): void
    {
        $this->offer(); $form = $this->form();
        DB::table('vehicle_pricelists')->where('id', 1)->update(['deposit_cents' => 60000]);
        $this->submit($form)->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('public_bookings', 0);
        $this->submit($this->form())->assertStatus(303);
        $this->assertSame(60000, PublicBooking::first()->deposit_cents);
    }

    public function test_expired_quote_and_changed_period_cannot_be_submitted(): void
    {
        $this->offer(); $form = $this->form();
        $this->submit(array_replace($form, ['return_at' => '2026-09-14T10:00']))->assertSessionHasErrors('checkout_token');
        $this->travel(31)->minutes();
        $this->submit($form)->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('rentals', 0);
    }

    public function test_malformed_token_and_missing_acceptance_are_rejected(): void
    {
        $this->offer(); $form = $this->form();
        $this->submit(array_replace($form, ['checkout_token' => 'tampered']))->assertSessionHasErrors('checkout_token');
        $this->submit(array_replace($form, ['accept_summary' => '0']))->assertSessionHasErrors('accept_summary');
        $this->submit(array_replace($form, ['email' => 'invalid', 'phone' => 'invalid']))->assertSessionHasErrors(['email', 'phone']);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_drafts_can_be_consulted_but_never_confirmed_or_sent(): void
    {
        Mail::fake(); $this->offer(offer: ['is_published' => false, 'prices_include_vat' => false]);
        $this->get(route('public-cars.booking.create', ['offer' => 1] + $this->period()))->assertNotFound();
        $this->actingAs($this->publisher());
        $form = $this->form(preview: true);
        $this->submit($form, preview: true)->assertSessionHasErrors('booking');
        $this->submit($form)->assertNotFound();
        foreach (['public_bookings', 'rentals', 'customers'] as $table) $this->assertDatabaseCount($table, 0);
        Mail::assertNothingSent();
    }

    public function test_published_offer_can_be_booked_from_the_management_search(): void
    {
        $this->offer(); $this->actingAs($this->publisher());
        $this->submit($this->form(preview: true), preview: true)->assertStatus(303);
        $this->assertSame('reserved', PublicBooking::first()->rental->status);
    }

    public function test_confirmation_pdf_is_protected_and_contains_the_booking_details(): void
    {
        $this->offer(); $this->submit($this->form()); $booking = PublicBooking::first();
        $this->get(route('public-bookings.pdf', $booking->reference))->assertForbidden();
        $pdf = $this->get($booking->pdfUrl())->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $html = view('pdfs.public-booking', ['booking' => $booking, 'car' => $booking->quote_snapshot])->render();
        foreach ([$booking->reference, 'Cliente Di Prova', '300,00 EUR', 'Al ritiro', 'Proprietario di prova'] as $text) $this->assertStringContainsString($text, $html);
        $this->assertStringNotContainsString('TESTPLATE', $html);
        $booking->rental->update(['status' => 'cancelled']);
        $html = view('pdfs.public-booking', ['booking' => $booking->fresh(), 'car' => $booking->quote_snapshot])->render();
        $this->assertStringContainsString('Annullata', $html);
        $this->assertStringNotContainsString('il veicolo è riservato', $html);
    }

    public function test_share_links_are_drafts_for_the_customer_and_do_not_guess_country_codes(): void
    {
        Mail::fake(); $this->offer(); $this->submit($this->form()); $booking = PublicBooking::first();
        $this->assertStringStartsWith('mailto:cliente%40example.test?', $booking->emailComposeUrl());
        $this->assertStringStartsWith('https://wa.me/393200000000?', $booking->whatsappComposeUrl());
        $this->assertStringContainsString($booking->reference, rawurldecode($booking->whatsappComposeUrl()));
        $booking->phone = '3200000000';
        $this->assertNull($booking->whatsappComposeUrl());
        Mail::assertNothingSent();
    }

    public function test_pdf_download_and_print_have_separate_signed_links(): void
    {
        $this->offer(); $this->submit($this->form()); $booking = PublicBooking::firstOrFail();
        $download = $this->get($booking->pdfUrl(true))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('attachment;', $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Prenotazione-'.$booking->reference.'.pdf', $download->headers->get('Content-Disposition'));
        $inline = $this->get($booking->pdfUrl())->assertOk();
        $this->assertStringStartsWith('inline;', $inline->headers->get('Content-Disposition'));
        $this->get($booking->pdfUrl().'&download=1')->assertForbidden();
        $this->get($booking->confirmationUrl())->assertOk()->assertSee('Scarica conferma PDF')->assertSee('Stampa conferma');
        $this->actingAs($this->publisher());
        $this->get(route('public-bookings.index'))->assertOk()->assertSee('Scarica PDF')->assertSee('Prepara WhatsApp')->assertSee('allegato manualmente');
    }

    public function test_messages_omit_local_links_and_include_public_https_confirmation_links(): void
    {
        Mail::fake(); $this->offer(); $this->submit($this->form()); $booking = PublicBooking::firstOrFail();
        try {
            foreach (['http://localhost:8000', 'https://127.0.0.1', 'https://192.168.1.10',
                'https://[::1]', 'https://era.local', 'https://era.test', 'http://prenotazioni.example.org'] as $root) {
                URL::forceRootUrl($root);
                URL::forceScheme(parse_url($root, PHP_URL_SCHEME));
                $this->assertNull($booking->shareableConfirmationUrl(), $root);
                $text = rawurldecode($booking->whatsappComposeUrl());
                $this->assertStringNotContainsString($root, $text);
                $this->assertStringContainsString($booking->reference, $text);
                $this->assertStringContainsString('300,00 EUR', $text);
                $this->assertStringContainsString('500,00 EUR', $text);
            }
            URL::forceRootUrl('https://prenotazioni.example.org');
            URL::forceScheme('https');
            $this->assertSame($booking->confirmationUrl(), $booking->shareableConfirmationUrl());
            $this->assertStringContainsString($booking->confirmationUrl(), rawurldecode($booking->whatsappComposeUrl()));
            $booking->rental->update(['status' => 'cancelled']);
            $text = $booking->fresh()->shareText();
            $this->assertStringContainsString('Annullata', $text);
            $this->assertStringNotContainsString('Pagamento al ritiro', $text);
        } finally {
            URL::forceRootUrl(null);
            URL::forceScheme(null);
        }
        Mail::assertNothingSent();
    }

    public function test_withdrawn_offer_is_not_bookable_even_with_an_existing_quote(): void
    {
        $offer = $this->offer(); $form = $this->form(); $offer->update(['is_published' => false]);
        $this->submit($form)->assertNotFound(); $this->assertDatabaseCount('rentals', 0);
    }

    public function test_confirmation_requires_its_signature_and_uses_the_agreed_price_after_list_changes(): void
    {
        $this->offer(); $response = $this->submit($this->form()); $booking = PublicBooking::first();
        $this->actingAs($this->publisher());
        $this->put(route('public-offers.update', 1), [
            'pricelist_id' => 1, 'location_id' => 1, 'prices_include_vat' => 1, 'is_published' => 1, 'deposit_euros' => '750,25',
        ])->assertRedirect(route('public-offers.index'));
        DB::table('vehicle_pricelists')->where('id', 1)->update(['base_daily_cents' => 99000]);
        $this->assertSame(50000, $booking->fresh()->deposit_cents);
        $this->assertSame(50000, RentalContractSnapshot::first()->pricing_snapshot['deposit_cents']);
        $this->get(route('public-bookings.confirmation', $booking->reference))->assertForbidden();
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('300,00')->assertSee('500,00')->assertDontSee('750,25')->assertDontSee('2.970,00');
        $this->get(str_replace($booking->reference, 'AMD-TAMPERED', $response->headers->get('Location')))->assertForbidden();
    }

    public function test_existing_customer_is_not_claimed_or_modified_by_email(): void
    {
        $this->offer(); $customer = Customer::create(['organization_id' => 1, 'name' => 'Cliente esistente', 'email' => 'cliente@example.test']);
        $this->submit($this->form())->assertStatus(303);
        $this->assertSame('Cliente esistente', $customer->fresh()->name);
        $this->assertNotSame($customer->id, PublicBooking::first()->rental->customer_id);
    }

    public function test_creation_failure_rolls_back_customer_rental_and_booking(): void
    {
        $this->offer(); $form = $this->form();
        $allocator = \Mockery::mock(RentalNumberAllocator::class);
        $allocator->shouldReceive('allocateAndCreate')->once()->andThrow(new \RuntimeException('Test failure'));
        $this->app->instance(RentalNumberAllocator::class, $allocator);
        $this->submit($form)->assertStatus(500);
        foreach (['customers', 'rentals', 'public_bookings', 'renter_contract_number_ledger'] as $table) $this->assertDatabaseCount($table, 0);
    }

    public function test_renter_only_sees_its_own_incoming_bookings(): void
    {
        $this->offer(); $this->submit($this->form()); $own = PublicBooking::first();
        $this->offer(2, 2);
        DB::table('vehicle_assignments')->insert(['vehicle_id' => 2, 'renter_org_id' => 2, 'start_at' => '2026-09-08 00:00:00', 'end_at' => null]);
        $this->submit(array_replace($this->form(2), ['email' => 'altro@example.test']), 2)->assertStatus(303);
        $incoming = PublicBooking::where('organization_id', 2)->first();
        $this->actingAs($this->publisher(2, 'renter'));
        $this->get(route('public-bookings.index'))->assertOk()->assertDontSee($own->reference)->assertDontSee($own->email)
            ->assertSee($incoming->reference)->assertSee('altro@example.test');
    }

    public function test_admin_can_open_incoming_bookings_in_the_rental_workflow(): void
    {
        $this->offer(); $this->submit($this->form()); $own = PublicBooking::first();
        $this->actingAs($this->publisher());
        $this->get(route('public-bookings.index'))->assertOk()->assertSee($own->reference)->assertSee('Apri noleggio');
    }

    public function test_booking_rate_limit_is_separate_from_search_navigation(): void
    {
        $this->offer(); $form = $this->form();
        for ($i = 0; $i < 6; $i++) $this->get(route('public-cars.index'))->assertOk();
        for ($i = 0; $i < 5; $i++) $this->submit($form)->assertStatus(303);
        $this->submit($form)->assertStatus(429);
        $this->assertDatabaseCount('rentals', 1);
    }

    public function test_cancellation_releases_the_vehicle_and_updates_the_confirmation(): void
    {
        $this->offer(); $response = $this->submit($this->form());
        PublicBooking::first()->rental->update(['status' => 'cancelled']);
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('Prenotazione annullata')->assertDontSee('l’auto è riservata');
        $this->assertCount(1, app(PublicVehicleSearch::class)->search(PublicRentalOffer::published(), $this->period()));
    }

    public function test_internal_wizard_rechecks_before_saving_after_a_public_booking(): void
    {
        $this->offer(); $this->submit($this->form());
        $this->actingAs($this->publisher());
        $wizard = new CreateWizard;
        $wizard->rentalData = ['vehicle_id' => 1, 'pickup_location_id' => 1, 'return_location_id' => 1,
            'planned_pickup_at' => $this->period()['pickup_at'], 'planned_return_at' => $this->period()['return_at']];
        try { $wizard->saveDraft(); $this->fail('The conflicting draft was accepted.'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('rentalData.vehicle_id', $e->errors()); }
        $this->assertDatabaseCount('rentals', 1);
    }

    public function test_internal_wizard_can_save_an_available_vehicle_with_the_shared_lock(): void
    {
        $this->offer(); $this->actingAs($this->publisher());
        $wizard = new CreateWizard;
        $wizard->rentalData = ['vehicle_id' => 1, 'pickup_location_id' => 1, 'return_location_id' => 1,
            'planned_pickup_at' => $this->period()['pickup_at'], 'planned_return_at' => $this->period()['return_at']];
        $wizard->saveDraft();
        $this->assertDatabaseCount('rentals', 1);
        $this->assertSame('draft', Rental::first()->status);
    }
}
