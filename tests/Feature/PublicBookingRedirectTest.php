<?php

namespace Tests\Feature;

use App\Models\{AmdRentEnquiry, PublicDeliveryLocation};
use Illuminate\Support\Facades\{DB, Http, Storage, URL};
use Tests\Support\PublicBookingTestCase;

class PublicBookingRedirectTest extends PublicBookingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['amd_rent.payment_mode' => 'stripe', 'amd_rent.stripe_secret' => null, 'amd_rent.stripe_webhook_secret' => null]);
        Http::preventStrayRequests();
        Http::fake();
        Storage::fake('car_models');
        Storage::disk('car_models')->put('toyota-yaris.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jN5kAAAAASUVORK5CYII='));
        $this->offer(vehicle: ['make' => 'Toyota', 'model' => 'Yaris']);
    }

    private function form(bool $preview = false): array
    {
        $prefix = $preview ? 'public-cars.preview' : 'public-cars';
        $page = $this->get(route($prefix.'.booking.create', ['pricelist' => 1] + $this->period()))->assertOk();
        // Real browsers load the image after the form; no-referrer suppresses Referer on submit.
        $this->get(route($prefix.'.photo', 1))->assertOk()->assertHeader('Content-Type', 'image/png');
        return $this->period() + ['checkout_token' => $page->viewData('checkoutToken'), 'first_name' => 'Cliente',
            'last_name' => 'Dimostrativo', 'email' => 'cliente@example.test', 'phone' => '+393200000000', 'accept_summary' => 1];
    }

    private function formUrl(bool $preview = false): string
    {
        return route(($preview ? 'public-cars.preview' : 'public-cars').'.booking.create', ['pricelist' => 1] + $this->period());
    }

    public function test_unconfigured_payment_returns_to_form_after_loading_car_photo(): void
    {
        $data = $this->form();
        $this->post(route('public-cars.booking.store', 1), $data)->assertRedirect($this->formUrl())
            ->assertSessionHasErrors('booking')->assertSessionHasInput('email', $data['email']);
        $this->get($this->formUrl())->assertOk()->assertSee('Il pagamento online non è ancora disponibile.')
            ->assertSee('value="cliente@example.test"', false);
        $this->assertDatabaseCount('public_bookings', 0);
        $this->assertDatabaseCount('rentals', 0);
        $this->assertDatabaseCount('customers', 0);
        Http::assertNothingSent();
    }

    public function test_preview_errors_stay_on_the_management_form(): void
    {
        $this->actingAs($this->publisher());
        $this->post(route('public-cars.preview.booking.store', 1), $this->form(true))
            ->assertRedirect($this->formUrl(true))->assertSessionHasErrors('booking');
    }

    public function test_changed_price_returns_to_form_and_requires_accepting_updated_conditions(): void
    {
        config(['amd_rent.payment_mode' => 'pickup']);
        $data = $this->form();
        DB::table('vehicle_pricelists')->where('id', 1)->update(['deposit_cents' => 60000]);
        $this->post(route('public-cars.booking.store', 1), $data)->assertRedirect($this->formUrl())->assertSessionHasErrors('booking');
        $this->get($this->formUrl())->assertOk()->assertSee('Controlla il riepilogo aggiornato')->assertSee('600,00');
        $this->assertDatabaseCount('public_bookings', 0);
    }

    public function test_invalid_checkout_token_ignores_previous_image_and_forged_return_url(): void
    {
        $data = array_replace($this->form(), ['checkout_token' => 'invalid', 'return_url' => 'https://example.invalid']);
        $this->post(route('public-cars.booking.store', 1), $data)->assertRedirect($this->formUrl())->assertSessionHasErrors('checkout_token');
    }

    public function test_custom_delivery_validation_returns_to_form_and_keeps_requested_address(): void
    {
        PublicDeliveryLocation::first()->update(['custom_delivery_enabled' => true, 'delivery_area' => 'Zona dimostrativa']);
        $data = $this->form() + ['request_delivery' => 1, 'delivery_address' => 'Hotel'];
        $this->post(route('public-cars.booking.store', 1), $data)->assertRedirect($this->formUrl())
            ->assertSessionHasErrors('delivery_address')->assertSessionHasInput('delivery_address', 'Hotel');
        $this->get($this->formUrl())->assertOk()->assertSee('value="Hotel"', false);
        $this->assertDatabaseCount('amd_rent_enquiries', 0);
    }

    public function test_json_clients_keep_validation_response_instead_of_html_redirect(): void
    {
        $this->postJson(route('public-cars.booking.store', 1), $this->form())->assertUnprocessable()->assertJsonValidationErrors('booking');
    }

    public function test_delivery_quote_is_preserved_on_both_form_and_payment_errors(): void
    {
        PublicDeliveryLocation::first()->update(['custom_delivery_enabled' => true, 'delivery_area' => 'Zona dimostrativa']);
        $data = $this->form() + ['request_delivery' => 1, 'delivery_address' => 'Hotel dimostrativo, via di prova 10, Bari'];
        $this->post(route('public-cars.booking.store', 1), $data)->assertStatus(303);
        $case = AmdRentEnquiry::firstOrFail();
        $case->update(['status' => 'quoted', 'delivery_fee_cents' => 3000, 'quote_expires_at' => now()->addDay()]);
        DB::table('amd_rent_settings')->updateOrInsert(['id' => 1], ['delivery_commission_bps' => 0]);
        $accept = URL::signedRoute('public-enquiries.accept', ['reference' => $case->reference]);
        $page = $this->post($accept, ['accept_quote' => 1])->assertOk();
        $data = array_replace($data, $page->viewData('filters'), ['request_delivery' => 0, 'checkout_token' => $page->viewData('checkoutToken')]);
        $this->get(route('public-cars.photo', 1))->assertOk();
        $this->post(route('public-cars.booking.store', 1), array_replace($data, ['email' => 'invalid']))
            ->assertRedirect($case->publicUrl())->assertSessionHasErrors('email');
        $this->post(route('public-cars.booking.store', 1), $data)->assertRedirect($case->publicUrl())->assertSessionHasErrors('booking');
        $this->get($case->publicUrl())->assertOk()->assertSee($case->delivery_address)->assertSee('Il pagamento online non è ancora disponibile.');
        $this->assertNull($case->fresh()->public_booking_id);
        $this->assertDatabaseCount('public_bookings', 0);

        // A token from a different session must not disclose the signed delivery summary.
        $this->flushSession();
        $this->post(route('public-cars.booking.store', 1), $data)->assertRedirect(route('public-cars.booking.create', ['pricelist' => 1] + $page->viewData('filters')))
            ->assertSessionHasErrors('checkout_token');
    }
}
