<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\Support\PublicBookingTestCase;

class PublicPortalRateLimitTest extends PublicBookingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_browsing_and_map_validation_do_not_block_a_corrected_enquiry(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->get(route('public-site.how-it-works'))->assertOk();
        }
        for ($i = 0; $i < 3; $i++) {
            $this->postJson(route('public-cars.map.search'), [])->assertUnprocessable();
        }
        $page = $this->get(route('public-site.long-term'))->assertOk();
        $data = [
            'customer_name' => 'Cliente di collaudo', 'email' => 'audit@example.test',
            'phone' => '+393200000000', 'customer_type' => 'business',
            'vehicle_request' => 'Auto dimostrativa', 'duration_months' => 36, 'annual_km' => 15000,
            'request_token' => $page->viewData('token'), 'accept_contact' => 1,
        ];
        $this->from(route('public-site.long-term'))->post(route('public-site.long-term.store'), $data)
            ->assertRedirect(route('public-site.long-term'))
            ->assertSessionHasErrors(['company_name' => 'Inserisci la ragione sociale per una richiesta aziendale.']);
        $this->get(route('public-site.long-term'))->assertOk()
            ->assertSee('Inserisci la ragione sociale per una richiesta aziendale.')
            ->assertSee('value="Cliente di collaudo"', false)
            ->assertSee('name="accept_contact" value="1" checked', false);
        $this->post(route('public-site.long-term.store'), $data + ['company_name' => 'Azienda dimostrativa'])
            ->assertStatus(303)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('amd_rent_enquiries', 1);
        $this->assertDatabaseCount('rentals', 0);
        Http::assertNothingSent();
    }

    public function test_excessive_enquiry_submissions_are_still_limited_without_blocking_browsing(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('public-site.long-term.store'), [])->assertUnprocessable();
        }
        $this->postJson(route('public-site.long-term.store'), [])->assertStatus(429)->assertHeader('Retry-After');
        $this->get(route('public-site.long-term'))->assertOk();
        $this->assertDatabaseCount('amd_rent_enquiries', 0);
        $this->travel(61)->seconds();
        $this->postJson(route('public-site.long-term.store'), [])->assertUnprocessable();
    }

    public function test_map_lookups_share_a_limit_but_cannot_consume_confirmation_or_enquiry_limits(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson(route('public-cars.map.search'), [])->assertUnprocessable();
            $this->postJson(route('public-cars.map.address'), [])->assertUnprocessable();
        }
        $this->postJson(route('public-cars.map.search'), [])->assertStatus(429);
        $this->postJson(route('public-cars.return-map.store'), [])->assertUnprocessable();
        $this->postJson(route('public-site.long-term.store'), [])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_login_validation_does_not_consume_registration_or_recovery_limits(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson(route('public-account.login.store'), [])->assertUnprocessable();
        }
        $this->postJson(route('public-account.register.store'), [])->assertUnprocessable();
        $this->postJson(route('public-account.password.email'), [])->assertUnprocessable();
        $this->assertDatabaseCount('public_customers', 0);
    }
}
