<?php

namespace Tests\Feature;

use App\Models\PublicBooking;
use Illuminate\Support\Facades\URL;
use Tests\Support\PublicBookingTestCase;

class PublicPortalDomainTest extends PublicBookingTestCase
{
    private array $previousEnvironment = [];
    private const PUBLIC_URL = 'https://rent.example.test';
    private const MANAGEMENT_URL = 'https://management.example.test';

    protected function setUp(): void
    {
        foreach (['AMD_RENT_DOMAIN' => 'rent.example.test', 'AMD_RENT_MANAGEMENT_URL' => self::MANAGEMENT_URL] as $key => $value) {
            $this->previousEnvironment[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        parent::setUp();
        URL::forceScheme('https');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->previousEnvironment as $key => [$value, $envValue, $serverValue]) {
            putenv($value === false ? $key : $key.'='.$value);
            if ($envValue === null) unset($_ENV[$key]); else $_ENV[$key] = $envValue;
            if ($serverValue === null) unset($_SERVER[$key]); else $_SERVER[$key] = $serverValue;
        }
    }

    public function test_public_domain_has_its_own_home_navigation_and_brand(): void
    {
        $this->assertSame(self::PUBLIC_URL, rtrim(route('public-cars.index'), '/'));
        $this->get(self::PUBLIC_URL.'/')->assertOk()->assertViewIs('public-cars.index')
            ->assertSee('AMD Rent')->assertSee('amd-rent-logo-v2.png')
            ->assertDontSee('href="'.self::MANAGEMENT_URL.'/login"', false)
            ->assertDontSee('AMD Mobility')->assertDontSee('amdmobility.it')
            ->assertDontSee('Diventa Segnalatore')->assertDontSee('08952480724');
        $this->get(route('public-site.how-it-works'))->assertOk()->assertSee('Prenotare con AMD Rent');
        $this->get(route('public-site.support'))->assertOk()->assertSee('recapiti dell’assistenza AMD Rent non sono ancora disponibili');
    }

    public function test_management_auth_api_and_preview_routes_are_blocked_on_public_domain(): void
    {
        foreach (['/login', '/register', '/dashboard', '/user/profile', '/organization-blocked',
            '/prenotazioni-sito', '/catalogo-pubblico', '/catalogo-pubblico/anteprima',
            '/catalogo-pubblico/anteprima/1/prenota', '/api/user'] as $path) {
            $this->get(self::PUBLIC_URL.$path)->assertNotFound();
        }
        $this->post(self::PUBLIC_URL.'/login', [])->assertNotFound();
        $this->post(self::PUBLIC_URL.'/livewire/update', [])->assertNotFound();

        $this->actingAs($this->publisher());
        $this->get(self::PUBLIC_URL.'/dashboard')->assertNotFound();
        $this->get(self::PUBLIC_URL.'/catalogo-pubblico/anteprima')->assertNotFound();
        $this->getJson(self::PUBLIC_URL.'/api/user')->assertNotFound();
    }

    public function test_customer_account_is_available_only_on_public_host_without_unlocking_management(): void
    {
        $this->assertSame(self::PUBLIC_URL.'/area-cliente/accedi', route('public-account.login'));
        $this->get(route('public-account.login'))->assertOk()->assertSee('Accedi alla tua area');
        $this->get(self::MANAGEMENT_URL.'/area-cliente/accedi')->assertNotFound();
        $customer = \App\Models\PublicCustomer::create(['first_name' => 'Cliente', 'last_name' => 'Dimostrativo', 'email' => 'client@example.test', 'password' => 'TestPassword2026']);
        $customer->markEmailAsVerified();
        $this->post(route('public-account.login.store'), ['email' => $customer->email, 'password' => 'TestPassword2026'])->assertRedirect(route('public-account.bookings'));
        $this->get(route('public-account.bookings'))->assertOk();
        $this->assertGuest('web');
        $this->get(self::PUBLIC_URL.'/dashboard')->assertNotFound();
        $this->get(self::MANAGEMENT_URL.'/dashboard')->assertRedirect();
        $mail = (new \App\Notifications\PublicCustomerAccess('verify'))->toMail($customer);
        $this->assertStringStartsWith(self::PUBLIC_URL.'/area-cliente/', $mail->viewData['url']);
    }

    public function test_management_host_retains_login_preview_and_its_existing_access_controls(): void
    {
        $this->get(self::MANAGEMENT_URL.'/')->assertOk()->assertViewIs('auth.login');
        $this->get(self::MANAGEMENT_URL.'/login')->assertOk()->assertViewIs('auth.login');
        $this->get(self::MANAGEMENT_URL.'/dashboard')->assertRedirect();
        $this->actingAs($this->publisher());
        $this->get(self::MANAGEMENT_URL.'/catalogo-pubblico/anteprima')->assertOk()->assertSee('AMD Rent');
        $this->get(self::MANAGEMENT_URL.'/cerca-auto')->assertNotFound();
        $this->get(self::MANAGEMENT_URL.'/come-funziona')->assertNotFound();
    }

    private function bookOnPublicDomain(): PublicBooking
    {
        $this->offer();
        $page = $this->get(route('public-cars.booking.create', ['pricelist' => 1] + $this->period()))->assertOk();
        $this->post(route('public-cars.booking.store', 1), $this->period() + [
            'checkout_token' => $page->viewData('checkoutToken'),
            'first_name' => 'Cliente', 'last_name' => 'Dimostrativo',
            'email' => 'cliente@example.test', 'phone' => '+39 320 0000000', 'accept_summary' => '1',
        ])->assertStatus(303);
        return PublicBooking::firstOrFail();
    }

    public function test_booking_confirmation_and_pdf_stay_on_public_host_and_require_valid_signatures(): void
    {
        $booking = $this->bookOnPublicDomain();
        $this->assertSame('reserved', $booking->rental->status);
        $this->assertStringStartsWith(self::PUBLIC_URL.'/prenotazione/', $booking->confirmationUrl());
        $this->get($booking->confirmationUrl())->assertOk()->assertSee('AMD Rent')->assertSee('Assistenza AMD Rent');
        $this->get($booking->pdfUrl(true))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('public-bookings.confirmation', $booking->reference))->assertForbidden();
        $this->get($booking->pdfUrl().'&download=1')->assertForbidden();
        $this->get(str_replace(self::PUBLIC_URL, self::MANAGEMENT_URL, $booking->confirmationUrl()))->assertForbidden();

        $html = view('pdfs.public-booking', ['booking' => $booking, 'car' => $booking->quote_snapshot])->render();
        $this->assertStringContainsString('AMD Rent', $html);
        $this->assertStringNotContainsString('AMD Mobility', $html);
    }

    public function test_links_prepared_from_management_use_the_public_host(): void
    {
        $booking = $this->bookOnPublicDomain();
        $this->actingAs($this->publisher());
        $this->get(self::MANAGEMENT_URL.'/prenotazioni-sito')->assertOk()
            ->assertSee(self::PUBLIC_URL.'/prenotazione/'.$booking->reference);
        $this->assertStringStartsWith(self::PUBLIC_URL.'/prenotazione/', $booking->pdfUrl(true));
        $this->get($booking->confirmationUrl())->assertOk();
    }

    public function test_previously_issued_signed_links_redirect_to_the_new_public_domain(): void
    {
        $booking = $this->bookOnPublicDomain();
        $this->get(self::MANAGEMENT_URL.'/login')->assertOk();
        $legacyConfirmation = URL::signedRoute('public-bookings.legacy.confirmation', ['reference' => $booking->reference]);
        $legacyPdf = URL::signedRoute('public-bookings.legacy.pdf', ['reference' => $booking->reference, 'download' => 1]);
        $this->assertStringStartsWith(self::MANAGEMENT_URL.'/prenotazione/', $legacyConfirmation);
        $redirect = $this->get($legacyConfirmation)->assertRedirect($booking->confirmationUrl());
        $this->get($redirect->headers->get('Location'))->assertOk()->assertSee($booking->reference);
        $redirect = $this->get($legacyPdf)->assertRedirect($booking->pdfUrl(true));
        $this->get($redirect->headers->get('Location'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get($legacyPdf.'&unexpected=1')->assertForbidden();
        $this->get(self::MANAGEMENT_URL.'/prenotazione/'.$booking->reference)->assertForbidden();
    }

    public function test_support_uses_only_explicitly_configured_contacts_and_legal_details(): void
    {
        config()->set([
            'public_cars.contact_email' => 'assistenza@example.test',
            'public_cars.contact_phone' => '+39 000 0000000',
            'public_cars.privacy_url' => 'https://rent.example.test/informativa-di-prova',
            'public_cars.legal_notice' => 'Operatore dimostrativo',
        ]);
        $this->get(route('public-site.support'))->assertOk()
            ->assertSee('mailto:assistenza@example.test', false)
            ->assertSee('tel:+390000000000', false)
            ->assertSee('informativa-di-prova')->assertSee('Operatore dimostrativo')
            ->assertDontSee('recapiti dell’assistenza AMD Rent non sono ancora disponibili');
    }
}
