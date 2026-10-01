<?php

namespace Tests\Feature;

use App\Models\{AmdRentEnquiry, PublicBooking, PublicDeliveryLocation, RentalCharge, VehiclePricelist};
use App\Domain\Rentals\PublicVehicleSearch;
use App\Services\AmdRent\{BookingPayments, DeliveryQuotes};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Gate, Http, Schema, Storage, URL};
use Tests\Support\PublicBookingTestCase;

class AmdRentOperationsTest extends PublicBookingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['amd_rent.payment_mode' => 'stripe', 'amd_rent.stripe_secret' => 'sk_test_fixture', 'amd_rent.stripe_webhook_secret' => 'whsec_fixture', 'amd_rent.stripe_live' => false]);
        Schema::create('rental_charges', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('rental_id'); $t->string('kind'); $t->boolean('is_commissionable');
            $t->decimal('amount', 12, 2); $t->boolean('payment_recorded'); $t->dateTime('payment_recorded_at');
            $t->string('payment_method'); $t->string('payment_reference')->nullable(); $t->uuid('request_key')->nullable();
            $t->string('description')->nullable(); $t->unsignedBigInteger('created_by')->nullable(); $t->softDeletes(); $t->timestamps();
            $t->unique(['rental_id', 'request_key']);
        });
        Http::preventStrayRequests();
        Http::fake(['https://api.stripe.com/v1/checkout/sessions' => function ($request) {
            $booking = PublicBooking::where('reference', $request['client_reference_id'])->firstOrFail();
            return Http::response($this->stripeSession($booking));
        }]);
    }
    private function form(int $pricelist = 1): array
    {
        $page = $this->get(route('public-cars.booking.create', ['pricelist' => $pricelist] + $this->period()))->assertOk();
        return $this->period() + ['checkout_token' => $page->viewData('checkoutToken'), 'first_name' => 'Cliente', 'last_name' => 'Dimostrativo',
            'email' => 'cliente@example.test', 'phone' => '+393200000000', 'accept_summary' => 1];
    }
    private function book(): PublicBooking
    {
        $this->offer();
        $this->post(route('public-cars.booking.store', 1), $this->form())->assertStatus(303);
        return PublicBooking::firstOrFail();
    }
    private function stripeSession(PublicBooking $booking, array $changes = []): array
    {
        return array_replace(['id' => 'cs_test_'.$booking->id, 'object' => 'checkout.session', 'client_reference_id' => $booking->reference,
            'metadata' => ['booking_reference' => $booking->reference], 'livemode' => false, 'mode' => 'payment', 'currency' => 'eur',
            'amount_total' => $booking->online_due_cents, 'payment_status' => 'unpaid', 'status' => 'open',
            'payment_intent' => 'pi_test_'.$booking->id, 'url' => 'https://checkout.stripe.com/c/pay/cs_test_'.$booking->id], $changes);
    }
    private function webhook(string $type, array $object, ?string $secret = null)
    {
        $body = json_encode(['id' => 'evt_fixture', 'object' => 'event', 'type' => $type, 'livemode' => false, 'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
        $time = time(); $signature = hash_hmac('sha256', $time.'.'.$body, $secret ?? 'whsec_fixture');
        return $this->call('POST', route('amd-rent.stripe.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.$signature], $body);
    }
    private function enquiry(int $organization = 2, array $extra = []): AmdRentEnquiry
    {
        return AmdRentEnquiry::create($extra + ['reference' => 'LT-'.uniqid(), 'type' => 'long_term', 'organization_id' => $organization,
            'customer_name' => 'Cliente privato '.$organization, 'email' => 'cliente'.$organization.'@example.test', 'phone' => '+393200000000',
            'vehicle_request' => 'Auto dimostrativa', 'duration_months' => 36, 'annual_km' => 15000]);
    }
    private function quoteData(): array { return ['supplier' => 'Società dimostrativa', 'vehicle' => 'Auto di prova', 'months' => 36, 'annual_km' => 15000,
        'monthly' => '300.00', 'upfront' => '0', 'vat' => 'included', 'valid_until' => '2026-10-01', 'conditions' => 'Condizioni dimostrative per collaudo.']; }

    public function test_checkout_charges_only_twenty_percent_and_holds_car_without_recording_payment(): void
    {
        $booking = $this->book();
        $this->assertSame(6000, $booking->online_due_cents);
        $this->assertSame(30000, $booking->total_cents);
        $this->assertSame(0, $booking->online_paid_cents);
        $this->assertSame('pending', $booking->payment_status);
        $this->assertDatabaseCount('rental_charges', 0);
        $this->assertCount(0, app(PublicVehicleSearch::class)->search(VehiclePricelist::forPublicRental(), $this->period()));
        $this->get($booking->confirmationUrl())->assertOk()->assertSee('In attesa', false);
        Http::assertSent(fn ($request) => $request['line_items'][0]['price_data']['unit_amount'] === 6000 && $request->hasHeader('Idempotency-Key', 'amd-rent-checkout-'.$booking->reference));
    }
    public function test_rejected_checkout_creation_releases_car_but_ambiguous_failure_keeps_it_reserved(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(['https://api.stripe.com/v1/checkout/sessions' => Http::response(['error' => ['message' => 'Test error']], 400)]);
        $booking = $this->book();
        $this->assertSame('failed', $booking->payment_status);
        $this->assertSame('cancelled', $booking->rental->status);
        $this->assertDatabaseCount('rental_charges', 0);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(['https://api.stripe.com/v1/checkout/sessions' => Http::response([], 503)]);
        $this->post(route('public-cars.booking.store', 1), $this->form())->assertStatus(303);
        $uncertain = PublicBooking::latest('id')->firstOrFail();
        $this->assertSame('pending', $uncertain->payment_status);
        $this->assertSame('reserved', $uncertain->rental->status);
        $this->assertDatabaseCount('rental_charges', 0);
    }
    public function test_paid_webhook_is_idempotent_and_commission_not_charged_twice(): void
    {
        $booking = $this->book();
        $paid = $this->stripeSession($booking, ['payment_status' => 'paid', 'status' => 'complete']);
        $this->webhook('checkout.session.completed', $paid)->assertOk();
        $this->webhook('checkout.session.completed', $paid)->assertOk();
        $this->webhook('checkout.session.expired', $this->stripeSession($booking, ['status' => 'expired']))->assertOk();
        $this->assertSame('paid', $booking->fresh()->payment_status);
        $this->assertSame('reserved', $booking->rental->fresh()->status);
        $this->assertDatabaseCount('rental_charges', 1);
        $this->assertSame('60.00', RentalCharge::first()->amount);
        $this->assertFalse(RentalCharge::first()->is_commissionable);
        $fee = app(\App\Domain\Fees\AdminFeeResolver::class)->calculateForRental($booking->rental);
        $this->assertEquals(60, $fee['amount']);
        $this->get($booking->confirmationUrl())->assertOk()->assertSee('Prenotazione confermata');
    }
    public function test_bad_signature_cannot_confirm_a_booking(): void
    {
        $booking = $this->book();
        $this->webhook('checkout.session.completed', $this->stripeSession($booking, ['payment_status' => 'paid']), 'whsec_attacker')->assertStatus(400);
        $this->assertSame('pending', $booking->fresh()->payment_status);
        $this->assertDatabaseCount('rental_charges', 0);
    }
    public function test_wrong_amount_or_currency_is_rejected_even_with_signed_event(): void
    {
        $booking = $this->book();
        $this->webhook('checkout.session.completed', $this->stripeSession($booking, ['payment_status' => 'paid', 'amount_total' => 1]))->assertStatus(500);
        $this->webhook('checkout.session.completed', $this->stripeSession($booking, ['payment_status' => 'paid', 'currency' => 'usd']))->assertStatus(500);
        $this->assertDatabaseCount('rental_charges', 0);
    }
    public function test_expired_payment_releases_vehicle_and_late_payment_does_not_reopen_it(): void
    {
        $booking = $this->book();
        $this->webhook('checkout.session.expired', $this->stripeSession($booking, ['status' => 'expired']))->assertOk();
        $this->assertSame('cancelled', $booking->rental->fresh()->status);
        $this->assertCount(1, app(PublicVehicleSearch::class)->search(VehiclePricelist::forPublicRental(), $this->period()));
        $this->webhook('checkout.session.completed', $this->stripeSession($booking, ['payment_status' => 'paid', 'status' => 'complete']))->assertOk();
        $this->assertSame('review', $booking->fresh()->payment_status);
        $this->assertSame('cancelled', $booking->rental->fresh()->status);
        $this->assertDatabaseCount('rental_charges', 0);
    }
    public function test_refunds_are_audited_once_and_not_deleted(): void
    {
        $booking = $this->book();
        $this->webhook('checkout.session.completed', $this->stripeSession($booking, ['payment_status' => 'paid', 'status' => 'complete']))->assertOk();
        $charge = ['id' => 'ch_fixture', 'object' => 'charge', 'payment_intent' => 'pi_test_'.$booking->id, 'amount' => 6000, 'amount_refunded' => 1000, 'livemode' => false, 'currency' => 'eur'];
        $this->webhook('charge.refunded', $charge)->assertOk(); $this->webhook('charge.refunded', $charge)->assertOk();
        $this->assertDatabaseCount('rental_charges', 2);
        $this->assertEquals(50, RentalCharge::sum('amount'));
        $this->assertSame(1000, $booking->fresh()->refunded_cents);
        $this->assertSame('review', $booking->fresh()->payment_status);
    }
    public function test_admin_cannot_modify_a_rental_while_checkout_is_open(): void
    {
        $booking = $this->book(); $admin = $this->publisher();
        $this->assertFalse(Gate::forUser($admin)->allows('update', $booking->rental));
        $this->assertFalse(Gate::forUser($admin)->allows('cancel', $booking->rental));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $booking->rental));
    }
    public function test_refund_delivered_before_checkout_completion_is_recovered_and_never_reconfirms(): void
    {
        $booking = $this->book();
        $session = $this->stripeSession($booking, ['payment_status' => 'paid', 'status' => 'complete']);
        Http::fake(['https://api.stripe.com/v1/checkout/sessions/'.$session['id'] => Http::response($session)]);
        $charge = ['id' => 'ch_early', 'object' => 'charge', 'metadata' => ['booking_reference' => $booking->reference],
            'payment_intent' => $session['payment_intent'], 'amount' => 6000, 'amount_refunded' => 6000, 'livemode' => false, 'currency' => 'eur'];
        $this->webhook('charge.refunded', $charge)->assertOk();
        $this->webhook('checkout.session.completed', $session)->assertOk();
        $this->webhook('charge.refunded', $charge)->assertOk();
        $this->assertSame('review', $booking->fresh()->payment_status);
        $this->assertSame(6000, $booking->fresh()->refunded_cents);
        $this->assertDatabaseCount('rental_charges', 2);
        $this->assertEquals(0, RentalCharge::sum('amount'));
    }
    public function test_refund_of_late_payment_does_not_create_a_negative_only_rental_ledger(): void
    {
        $booking = $this->book();
        $this->webhook('checkout.session.expired', $this->stripeSession($booking, ['status' => 'expired']))->assertOk();
        $this->webhook('checkout.session.completed', $this->stripeSession($booking, ['payment_status' => 'paid', 'status' => 'complete']))->assertOk();
        $this->webhook('charge.refunded', ['id' => 'ch_late', 'object' => 'charge', 'payment_intent' => 'pi_test_'.$booking->id,
            'amount' => 6000, 'amount_refunded' => 6000, 'livemode' => false, 'currency' => 'eur'])->assertOk();
        $this->assertDatabaseCount('rental_charges', 0);
        $this->assertSame('cancelled', $booking->rental->fresh()->status);
        $this->assertEquals(0, $booking->rental->fresh()->admin_fee_collected_amount);
    }
    public function test_online_commission_is_not_repeated_on_balance_and_existing_extra_rules_are_preserved(): void
    {
        $booking = $this->book();
        $this->webhook('checkout.session.completed', $this->stripeSession($booking, ['payment_status' => 'paid', 'status' => 'complete']))->assertOk();
        $rental = $booking->rental;
        $rental->assignment_id = 1;
        $payments = app(\App\Services\Rentals\RentalPaymentService::class);
        $this->assertFalse($payments->isCommissionable($rental, RentalCharge::KIND_BASE));
        $this->assertFalse($payments->isCommissionable($rental, RentalCharge::KIND_ACCONTO));
        $this->assertTrue($payments->isCommissionable($rental, RentalCharge::KIND_OTHER));
        $this->assertTrue($payments->isCommissionable($rental, RentalCharge::KIND_DISTANCE_OVERAGE));
        $rental->closed_at = now(); $rental->amd_extra_fee_percent = 10;
        $rental->charges()->create(['kind' => RentalCharge::KIND_OTHER, 'amount' => '50.00', 'is_commissionable' => true,
            'payment_method' => 'cash', 'payment_recorded' => true, 'payment_recorded_at' => now()]);
        $fee = app(\App\Domain\Fees\AdminFeeResolver::class)->calculateForRental($rental);
        $this->assertEquals(65, $fee['amount']);
        $this->assertEquals(60, $rental->admin_fee_collected_amount);
    }
    public function test_missing_stripe_configuration_does_not_create_a_reservation(): void
    {
        config(['amd_rent.stripe_secret' => null]); $this->offer();
        $this->post(route('public-cars.booking.store', 1), $this->form())->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('public_bookings', 0); $this->assertDatabaseCount('rentals', 0);
    }
    public function test_refund_review_requires_admin_and_updates_remaining_balance_without_second_charge(): void
    {
        $booking = $this->book();
        $this->webhook('checkout.session.completed', $this->stripeSession($booking, ['payment_status' => 'paid', 'status' => 'complete']))->assertOk();
        $this->webhook('charge.refunded', ['id' => 'ch_review', 'payment_intent' => 'pi_test_'.$booking->id,
            'amount' => 6000, 'amount_refunded' => 1000, 'livemode' => false, 'currency' => 'eur'])->assertOk();
        $data = ['action' => 'keep', 'verified_in_stripe' => 1, 'refund_snapshot' => 1000, 'note' => 'Verificato rimborso parziale e saldo concordato con cliente dimostrativo.'];
        $this->actingAs($this->publisher(2, 'renter'))->put(route('amd-rent.bookings.review', $booking), $data)->assertForbidden();
        $this->flushSession(); $this->actingAs($this->publisher());
        $this->put(route('amd-rent.bookings.review', $booking), array_replace($data, ['refund_snapshot' => 0]))->assertStatus(409);
        $this->put(route('amd-rent.bookings.review', $booking), $data)->assertRedirect()->assertSessionHasNoErrors();
        $booking->refresh();
        $this->assertSame('paid', $booking->payment_status); $this->assertSame(25000, $booking->pickup_due_cents);
        $this->assertCount(1, $booking->payment_review_history);
        $this->get($booking->confirmationUrl())->assertOk()->assertSee('250,00')->assertDontSee($data['note']);
        $this->assertDatabaseCount('rental_charges', 2);
    }
    public function test_cancellation_after_refund_requires_full_refund_and_releases_reserved_car(): void
    {
        $booking = $this->book();
        $this->webhook('checkout.session.completed', $this->stripeSession($booking, ['payment_status' => 'paid', 'status' => 'complete']))->assertOk();
        $charge = ['id' => 'ch_cancel', 'payment_intent' => 'pi_test_'.$booking->id, 'amount' => 6000, 'amount_refunded' => 1000, 'livemode' => false, 'currency' => 'eur'];
        $this->webhook('charge.refunded', $charge)->assertOk();
        $this->actingAs($this->publisher());
        $data = ['action' => 'cancel', 'verified_in_stripe' => 1, 'refund_snapshot' => 1000, 'note' => 'Cliente dimostrativo ha richiesto annullamento dopo rimborso.'];
        $this->put(route('amd-rent.bookings.review', $booking), $data)->assertSessionHasErrors('payment');
        $this->webhook('charge.refunded', array_replace($charge, ['amount_refunded' => 6000]))->assertOk();
        $this->put(route('amd-rent.bookings.review', $booking), array_replace($data, ['refund_snapshot' => 6000]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('refunded', $booking->fresh()->payment_status);
        $this->assertSame('cancelled', $booking->rental->fresh()->status);
        $this->assertSame(0, $booking->fresh()->pickup_due_cents);
        $this->assertCount(1, app(PublicVehicleSearch::class)->search(VehiclePricelist::forPublicRental(), $this->period()));
    }
    public function test_expiration_checks_provider_before_releasing_and_keeps_hold_on_network_error(): void
    {
        $booking = $this->book(); $booking->update(['payment_expires_at' => now()->subMinute()]);
        Http::fake(['https://api.stripe.com/v1/checkout/sessions/'.$booking->stripe_session_id => Http::sequence()->push([], 503)->push($this->stripeSession($booking, ['status' => 'expired']))]);
        try { app(BookingPayments::class)->expireDue($booking); $this->fail('Provider error must preserve hold.'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('503', $e->getMessage()); }
        $this->assertSame('pending', $booking->fresh()->payment_status);
        $this->assertSame('reserved', $booking->rental->fresh()->status);
        app(BookingPayments::class)->expireDue($booking);
        $this->assertSame('expired', $booking->fresh()->payment_status);
        $this->assertSame('cancelled', $booking->rental->fresh()->status);
    }
    public function test_product_uses_next_cheapest_available_car_while_first_is_in_checkout(): void
    {
        $booking = $this->book(); $this->offer(2, 1, ['base_daily_cents' => 11000]);
        $product = \App\Models\VehicleProduct::create(['name' => 'Panda di prova', 'code' => 'PANDA-QA']);
        DB::table('vehicles')->update(['vehicle_product_id' => $product->id]);
        $search = app(PublicVehicleSearch::class);
        $result = $search->cheapestPerProduct($search->search(VehiclePricelist::forPublicRental(), $this->period()));
        $this->assertCount(1, $result); $this->assertSame(2, $result->first()['vehicle_id']);
    }
    public function test_renter_cannot_see_or_mutate_other_organizations_cases_or_admin_settings(): void
    {
        $mine = $this->enquiry(2); $other = $this->enquiry(3);
        $this->actingAs($this->publisher(2, 'renter'));
        $this->get(route('amd-rent.enquiries.index'))->assertOk()->assertSee($mine->reference)->assertDontSee($other->reference);
        $this->get(route('amd-rent.enquiries.show', $other))->assertNotFound();
        $this->post(route('amd-rent.enquiries.quote', $other), $this->quoteData())->assertNotFound();
        $this->get(route('amd-rent.settings'))->assertForbidden();
        $this->put(route('amd-rent.settings.save'), ['delivery_percent' => 0])->assertForbidden();
        $this->put(route('amd-rent.enquiries.update', $mine), ['revision' => 1, 'status' => 'working', 'organization_id' => 3])->assertSessionHasErrors('organization_id');
        $this->put(route('amd-rent.enquiries.commissions', $mine), [])->assertForbidden();
    }
    public function test_renter_cannot_see_platform_commission_and_private_documents_are_scoped(): void
    {
        Storage::fake('amd_rent_private');
        $case = $this->enquiry(2, ['platform_commission_cents' => 876543, 'renter_commission_cents' => 12345]);
        $renter = $this->publisher(2, 'renter'); $other = $this->publisher(3, 'renter');
        $this->actingAs($renter)->get(route('amd-rent.enquiries.show', $case))->assertOk()->assertDontSee('8.765,43')->assertSee('123,45');
        $this->post(route('amd-rent.enquiries.upload', $case), ['kind' => 'company', 'document' => UploadedFile::fake()->create('pratica-di-prova.pdf', 10, 'application/pdf')])->assertRedirect();
        $file = $case->documents()->firstOrFail();
        $this->get(route('amd-rent.enquiries.download', [$case, $file]))->assertDownload('pratica-di-prova.pdf');
        $this->flushSession();
        $this->actingAs($other)->get(route('amd-rent.enquiries.download', [$case, $file]))->assertNotFound();
    }
    public function test_long_term_cannot_conclude_without_accepted_quote_and_signed_contract(): void
    {
        Storage::fake('amd_rent_private'); $case = $this->enquiry(2);
        $this->actingAs($this->publisher(2, 'renter'));
        $this->put(route('amd-rent.enquiries.update', $case), ['revision' => 1, 'status' => 'signed'])->assertSessionHasErrors();
        $this->post(route('amd-rent.enquiries.quote', $case), $this->quoteData())->assertRedirect();
        $quote = $case->quotes()->firstOrFail();
        $this->put(route('amd-rent.enquiries.update', $case), ['revision' => $case->fresh()->revision, 'status' => 'accepted', 'selected_quote_id' => $quote->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('amd-rent.enquiries.upload', $case), ['kind' => 'contract', 'document' => UploadedFile::fake()->create('contratto-di-prova.pdf', 10, 'application/pdf')])->assertRedirect();
        $this->put(route('amd-rent.enquiries.update', $case), ['revision' => $case->fresh()->revision, 'status' => 'signed', 'selected_quote_id' => $quote->id,
            'contract_reference' => 'TEST-2026', 'signed_at' => '2026-09-08', 'signed_in_person' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('signed', $case->fresh()->status);
        $this->post(route('amd-rent.enquiries.quote', $case), $this->quoteData())->assertStatus(409);
        $this->assertDatabaseCount('rentals', 0);
    }
    public function test_public_long_term_request_is_unassigned_and_ignores_forged_admin_fields(): void
    {
        $page = $this->get(route('public-site.long-term'))->assertOk();
        $data = ['customer_name' => 'Cliente dimostrativo', 'email' => 'qa@example.test', 'phone' => '+393200000000', 'customer_type' => 'business',
            'company_name' => 'Azienda dimostrativa', 'vehicle_request' => 'Panda', 'duration_months' => 36, 'annual_km' => 15000,
            'accept_contact' => 1, 'request_token' => $page->viewData('token'), 'organization_id' => 3, 'platform_commission_cents' => 1];
        $response = $this->post(route('public-site.long-term.store'), $data)->assertStatus(303);
        $this->post(route('public-site.long-term.store'), $data)->assertStatus(303);
        $case = AmdRentEnquiry::firstOrFail();
        $this->assertNull($case->organization_id); $this->assertNull($case->platform_commission_cents);
        $this->assertDatabaseCount('amd_rent_enquiries', 1); $this->assertDatabaseCount('rentals', 0);
        $this->get($response->headers->get('Location'))->assertOk();
        $this->get(route('public-enquiries.show', $case->reference))->assertForbidden();
        $this->actingAs($this->publisher(2, 'renter'))->get(route('amd-rent.enquiries.show', $case))->assertNotFound();
    }
    public function test_delivery_is_quoted_before_payment_and_undefined_commission_blocks_checkout(): void
    {
        $this->offer(); PublicDeliveryLocation::first()->update(['custom_delivery_enabled' => true, 'delivery_area' => 'Zona di collaudo']);
        $form = $this->form() + ['request_delivery' => 1, 'delivery_address' => 'Hotel dimostrativo, via di prova 10, Bari'];
        $this->post(route('public-cars.booking.store', 1), $form)->assertStatus(303);
        $this->assertDatabaseCount('rentals', 0); $this->assertDatabaseCount('public_bookings', 0);
        $case = AmdRentEnquiry::firstOrFail();
        $this->actingAs($this->publisher())->put(route('amd-rent.enquiries.update', $case), ['revision' => 1, 'status' => 'quoted', 'delivery_fee' => 30, 'quote_valid_until' => '2026-09-09'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(URL::signedRoute('public-enquiries.accept', ['reference' => $case->reference]), ['accept_quote' => 1])->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('rentals', 0);
        $this->put(route('amd-rent.settings.save'), ['delivery_percent' => 0])->assertRedirect();
        $page = $this->post(URL::signedRoute('public-enquiries.accept', ['reference' => $case->reference]), ['accept_quote' => 1])->assertOk();
        $this->assertSame(33000, $page->viewData('car')['total_cents']);
        $this->assertSame(6000, DeliveryQuotes::onlineDue($page->viewData('car')));
        $this->post(route('public-cars.booking.store', 1), array_replace($form, $page->viewData('filters'), ['request_delivery' => 0, 'checkout_token' => $page->viewData('checkoutToken')]))->assertStatus(303);
        $booking = PublicBooking::firstOrFail();
        $this->assertSame(33000, $booking->total_cents); $this->assertSame(6000, $booking->online_due_cents);
        $this->assertSame($booking->id, (int) $case->fresh()->public_booking_id);
    }
    public function test_commissions_remain_editable_by_admin_after_signed_case_and_stale_updates_fail(): void
    {
        $case = $this->enquiry(2, ['status' => 'signed']); $this->actingAs($this->publisher());
        $this->put(route('amd-rent.enquiries.commissions', $case), ['revision' => 1, 'platform_commission' => 100, 'renter_commission' => 30, 'commission_status' => 'paid'])->assertRedirect();
        $this->assertSame('paid', $case->fresh()->commission_status);
        $this->put(route('amd-rent.enquiries.commissions', $case), ['revision' => 1, 'platform_commission' => 0, 'commission_status' => 'expected'])->assertStatus(409);
    }
    public function test_dashboard_and_configuration_are_accessible_only_to_operational_roles(): void
    {
        $this->actingAs($this->publisher(2, 'renter'))->get(route('amd-rent.index'))->assertOk();
        $this->flushSession();
        $this->actingAs($this->publisher(3, 'viewer'))->get(route('amd-rent.index'))->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->publisher())->get(route('amd-rent.settings'))->assertOk()->assertSee('Lascia vuoto');
    }
}
