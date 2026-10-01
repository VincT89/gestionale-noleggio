<?php

namespace Tests\Feature;

use App\Models\{AmdRentEnquiry, PublicBooking, PublicCustomer};
use App\Notifications\PublicCustomerAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Auth, DB, Hash, Notification, Password, Storage, URL};
use Tests\Support\PublicBookingTestCase;

class PublicCustomerAccountTest extends PublicBookingTestCase
{
    private const SECRET = 'PasswordTest2026';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('amd_rent_private');
    }

    private function customer(string $email = 'cliente@example.test', bool $verified = true): PublicCustomer
    {
        $customer = PublicCustomer::create(['first_name' => 'Cliente', 'last_name' => 'Dimostrativo', 'email' => $email, 'phone' => '+393200000000', 'password' => self::SECRET]);
        if ($verified) $customer->markEmailAsVerified();
        return $customer;
    }

    private function signIn(PublicCustomer $customer): void
    {
        $this->actingAs($customer, 'public_customer');
        Auth::shouldUse('web');
        $this->withSession(['public_customer_password_hash' => $customer->getAuthPassword()]);
    }

    private function registration(array $extra = []): array
    {
        return array_replace(['first_name' => 'Cliente', 'last_name' => 'Dimostrativo', 'email' => 'cliente@example.test', 'phone' => '+393200000000', 'password' => self::SECRET, 'password_confirmation' => self::SECRET], $extra);
    }

    private function enquiry(array $extra = []): AmdRentEnquiry
    {
        return AmdRentEnquiry::create($extra + ['reference' => 'LT-'.strtoupper(bin2hex(random_bytes(5))), 'type' => 'long_term', 'organization_id' => 2,
            'customer_name' => 'Cliente dimostrativo', 'email' => 'cliente@example.test', 'phone' => '+393200000000',
            'vehicle_request' => 'Auto dimostrativa', 'duration_months' => 36, 'annual_km' => 15000]);
    }

    private function booking(): PublicBooking
    {
        $this->offer();
        $page = $this->get(route('public-cars.booking.create', ['pricelist' => 1] + $this->period()))->assertOk();
        $data = $this->period() + ['checkout_token' => $page->viewData('checkoutToken'), 'first_name' => 'Cliente', 'last_name' => 'Dimostrativo',
            'email' => 'cliente@example.test', 'phone' => '+393200000000', 'accept_summary' => 1];
        $this->post(route('public-cars.booking.store', 1), $data)->assertStatus(303);
        return PublicBooking::firstOrFail();
    }

    private function verifyUrl(PublicCustomer $customer, int $minutes = 60): string
    {
        return URL::temporarySignedRoute('public-account.verification.verify', now()->addMinutes($minutes), ['id' => $customer->id, 'hash' => sha1($customer->email)]);
    }

    public function test_guest_pages_render_and_all_password_fields_have_accessible_toggles(): void
    {
        foreach (['login', 'register', 'password.request'] as $route) $this->get(route('public-account.'.$route))->assertOk()->assertSee('AMD Rent')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get(route('public-account.login'))->assertSee('aria-label="Mostra password"', false)->assertSee('autocomplete="current-password"', false);
        $this->get(route('public-account.password.reset', ['token' => 'test', 'email' => 'cliente@example.test']))->assertOk()->assertSee('password_confirmation');
        $this->get('/login')->assertOk()->assertSee('data-password-toggle', false);
    }

    public function test_registration_creates_only_a_separate_customer_and_requires_email_verification(): void
    {
        $this->post(route('public-account.register.store'), $this->registration(['email' => ' CLIENTE@Example.test ', 'email_verified_at' => now(), 'role' => 'admin']))->assertRedirect(route('public-account.verification.notice'));
        $customer = PublicCustomer::firstOrFail();
        $this->assertSame('cliente@example.test', $customer->email);
        $this->assertNull($customer->email_verified_at);
        $this->assertTrue(Hash::check(self::SECRET, $customer->password));
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertGuest('web');
        $this->assertAuthenticatedAs($customer, 'public_customer');
        Notification::assertSentTo($customer, PublicCustomerAccess::class, fn ($notice) => $notice->purpose === 'verify');
        $this->get(route('public-account.bookings'))->assertRedirect(route('public-account.verification.notice'));
    }

    public function test_registration_rejects_short_mismatched_and_duplicate_password_accounts(): void
    {
        $this->post(route('public-account.register.store'), $this->registration(['password' => 'short', 'password_confirmation' => 'different']))->assertSessionHasErrors('password');
        $this->assertDatabaseCount('public_customers', 0);
        $this->customer();
        $this->post(route('public-account.register.store'), $this->registration(['email' => 'CLIENTE@example.test']))->assertSessionHasErrors('email');
    }

    public function test_invalid_input_is_reported_without_breaking_the_forms_or_flashing_passwords(): void
    {
        $this->from(route('public-account.register'))->post(route('public-account.register.store'), $this->registration(['email' => ['invalid'], 'first_name' => ['invalid']]))->assertSessionHasErrors(['email', 'first_name']);
        $this->get(route('public-account.register'))->assertOk()->assertSee('Controlla i dati inseriti')->assertDontSee(self::SECRET);
        $this->from(route('public-account.login'))->post(route('public-account.login.store'), ['email' => ['invalid'], 'password' => self::SECRET])->assertSessionHasErrors('email');
        $this->get(route('public-account.login'))->assertOk()->assertDontSee(self::SECRET);
    }

    public function test_unverified_accounts_cannot_claim_or_read_records_and_profile_is_available(): void
    {
        $case = $this->enquiry();
        $customer = $this->customer(verified: false);
        $this->signIn($customer);
        $this->get(route('public-account.enquiry', $case->reference))->assertRedirect(route('public-account.verification.notice'));
        $this->get(route('public-account.profile'))->assertOk();
        $this->assertNull($case->fresh()->public_customer_id);
    }

    public function test_valid_email_verification_claims_guest_records_and_wrong_account_or_expiry_is_rejected(): void
    {
        $case = $this->enquiry(['email' => ' CLIENTE@EXAMPLE.TEST ']);
        $customer = $this->customer(verified: false);
        $other = $this->customer('other@example.test', false);
        $this->signIn($customer);
        $this->get($this->verifyUrl($other))->assertForbidden();
        $this->get($this->verifyUrl($customer, -1))->assertForbidden();
        $this->get($this->verifyUrl($customer).'broken')->assertForbidden();
        $this->get($this->verifyUrl($customer))->assertRedirect(route('public-account.bookings'));
        $this->assertTrue($customer->fresh()->hasVerifiedEmail());
        $this->assertSame($customer->id, $case->fresh()->public_customer_id);
    }

    public function test_login_credentials_and_management_permissions_are_separate(): void
    {
        $staff = $this->publisher(2, 'renter');
        $staff->forceFill(['password' => Hash::make(self::SECRET)])->save();
        $this->post(route('public-account.login.store'), ['email' => $staff->email, 'password' => self::SECRET])->assertSessionHasErrors('email');
        $customer = $this->customer();
        $this->post(route('public-account.login.store'), ['email' => 'CLIENTE@example.test', 'password' => self::SECRET])->assertRedirect(route('public-account.bookings'));
        $this->assertAuthenticatedAs($customer, 'public_customer');
        $this->assertGuest('web');
        $this->get(route('amd-rent.enquiries.index'))->assertRedirect(route('login'));
    }

    public function test_wrong_password_attempts_are_limited_without_disclosing_existing_accounts(): void
    {
        $this->customer();
        for ($i = 0; $i < 5; $i++) $this->post(route('public-account.login.store'), ['email' => 'cliente@example.test', 'password' => 'wrong'])->assertSessionHasErrors(['email' => 'Email o password non corrette.']);
        $this->post(route('public-account.login.store'), ['email' => 'cliente@example.test', 'password' => self::SECRET])->assertSessionHasErrors('email');
        $this->assertGuest('public_customer');
    }

    public function test_customer_sees_only_own_bookings_and_can_download_own_confirmation(): void
    {
        $booking = $this->booking();
        $booking->update(['payment_method' => 'stripe', 'payment_status' => 'paid', 'online_paid_cents' => 6000]);
        $owner = $this->customer();
        $other = $this->customer('other@example.test');
        $this->signIn($other);
        $this->get(route('public-account.bookings'))->assertOk()->assertDontSee($booking->reference);
        $this->get(route('public-account.booking', $booking->reference))->assertNotFound();
        $this->get(route('public-account.booking.pdf', $booking->reference))->assertNotFound();
        $this->signIn($owner);
        $this->get(route('public-account.bookings'))->assertOk()->assertSee($booking->reference)->assertSee('60,00')->assertSee('240,00');
        $this->get(route('public-account.booking', $booking->reference))->assertOk()->assertSee($booking->reference);
        $this->get(route('public-account.booking.pdf', $booking->reference))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame($owner->id, $booking->fresh()->public_customer_id);
        $this->get(route('public-account.bookings', ['period' => 'past']))->assertOk()->assertDontSee($booking->reference);
    }

    public function test_already_owned_records_are_never_reassigned_by_email(): void
    {
        $owner = $this->customer('original@example.test');
        $other = $this->customer();
        $case = $this->enquiry(['public_customer_id' => $owner->id]);
        $this->signIn($other);
        $this->get(route('public-account.enquiries'))->assertOk()->assertDontSee($case->reference);
        $this->assertSame($owner->id, $case->fresh()->public_customer_id);
    }

    public function test_profile_email_change_requires_current_password_and_new_verification_keeps_existing_records(): void
    {
        $customer = $this->customer();
        $case = $this->enquiry(['public_customer_id' => $customer->id]);
        $next = $this->enquiry(['email' => 'new@example.test']);
        $this->signIn($customer);
        $oldLink = $this->verifyUrl($customer);
        $data = ['first_name' => 'Nome nuovo', 'last_name' => 'Dimostrativo', 'email' => 'NEW@example.test', 'phone' => '+393200000000'];
        $this->put(route('public-account.profile.update'), $data)->assertSessionHasErrors('current_password');
        $this->put(route('public-account.profile.update'), $data + ['current_password' => self::SECRET])->assertRedirect(route('public-account.verification.notice'));
        $this->assertNull($customer->fresh()->email_verified_at);
        $this->assertNull($next->fresh()->public_customer_id);
        $this->get($oldLink)->assertForbidden();
        $this->get($this->verifyUrl($customer->fresh()))->assertRedirect();
        $this->assertSame($customer->id, $next->fresh()->public_customer_id);
        $this->assertSame($customer->id, $case->fresh()->public_customer_id);
    }

    public function test_password_change_checks_current_password_and_revokes_an_old_session(): void
    {
        $customer = $this->customer();
        $hash = $customer->password;
        $this->signIn($customer);
        $this->put(route('public-account.password.change'), ['current_password' => 'wrong', 'password' => 'NewPassword2026', 'password_confirmation' => 'NewPassword2026'])->assertSessionHasErrors('current_password');
        $this->put(route('public-account.password.change'), ['current_password' => self::SECRET, 'password' => 'NewPassword2026', 'password_confirmation' => 'NewPassword2026'])->assertRedirect();
        $this->assertTrue(Hash::check('NewPassword2026', $customer->fresh()->password));
        $this->get(route('public-account.profile'))->assertOk();
        $this->withSession(['public_customer_password_hash' => $hash]);
        $this->get(route('public-account.profile'))->assertRedirect(route('public-account.login'));
        $this->assertGuest('public_customer');
    }

    public function test_password_reset_uses_public_broker_and_cannot_reset_staff_or_use_invalid_token(): void
    {
        $customer = $this->customer();
        $staff = $this->publisher(2, 'renter');
        $staffHash = $staff->password;
        $this->post(route('public-account.password.email'), ['email' => $customer->email])->assertSessionHas('status');
        Notification::assertSentTo($customer, PublicCustomerAccess::class, fn ($notice) => $notice->purpose === 'reset');
        Notification::assertNotSentTo($staff, PublicCustomerAccess::class);
        $message = session('status');
        $this->post(route('public-account.password.email'), ['email' => 'missing@example.test'])->assertSessionHas('status', $message);
        $data = ['email' => $customer->email, 'token' => 'invalid', 'password' => 'ResetPassword2026', 'password_confirmation' => 'ResetPassword2026'];
        $this->post(route('public-account.password.update'), $data)->assertSessionHasErrors('email');
        $token = Password::broker('public_customers')->createToken($customer);
        $this->post(route('public-account.password.update'), array_replace($data, ['token' => $token]))->assertRedirect(route('public-account.login'));
        $this->assertTrue(Hash::check('ResetPassword2026', $customer->fresh()->password));
        $this->assertSame($staffHash, $staff->fresh()->password);
        $this->post(route('public-account.password.update'), array_replace($data, ['token' => $token]))->assertSessionHasErrors('email');
    }

    public function test_reset_email_and_verification_email_point_to_customer_routes_and_use_amd_rent_brand(): void
    {
        $customer = $this->customer();
        foreach (['verify', 'reset'] as $purpose) {
            $mail = (new PublicCustomerAccess($purpose, 'demo-token'))->toMail($customer);
            $this->assertSame('emails.public-customer-access', $mail->view);
            $this->assertStringContainsString('/area-cliente/', $mail->viewData['url']);
            $this->assertStringContainsString('AMD Rent', view($mail->view, $mail->viewData)->render());
        }
    }

    public function test_long_term_quotes_are_visible_without_internal_commissions_or_unshared_documents(): void
    {
        $case = $this->enquiry(['status' => 'quoted', 'platform_commission_cents' => 987654, 'renter_commission_cents' => 876543]);
        $case->quotes()->create(['supplier' => 'Società dimostrativa', 'vehicle' => 'Auto di prova', 'months' => 36, 'annual_km' => 15000, 'monthly_cents' => 30000, 'upfront_cents' => 0, 'vat' => 'included', 'valid_until' => '2026-10-01', 'conditions' => 'Condizioni di prova.']);
        $staff = $this->publisher(2, 'renter');
        foreach ([false => 'interno-segreto.pdf', true => 'preventivo-cliente.pdf'] as $visible => $name) {
            $case->documents()->create(['kind' => 'quote', 'name' => $name, 'path' => 'case/'.$name, 'size' => 50, 'uploaded_by' => $staff->id, 'customer_visible' => $visible]);
        }
        $this->signIn($this->customer());
        $this->get(route('public-account.enquiry', $case->reference))->assertOk()->assertSee('300,00')->assertSee('preventivo-cliente.pdf')->assertDontSee('interno-segreto.pdf')->assertDontSee('9.876,54')->assertDontSee('8.765,43');
        $this->get($case->publicUrl())->assertOk()->assertDontSee('preventivo-cliente.pdf');
    }

    public function test_document_upload_and_download_are_private_scoped_and_not_available_to_other_customers(): void
    {
        $case = $this->enquiry();
        $customer = $this->customer();
        $this->signIn($customer);
        $this->post(route('public-account.documents.upload', $case->reference), ['kind' => 'customer', 'document' => UploadedFile::fake()->createWithContent('documento.pdf', '%PDF-1.4 documento di prova')])->assertRedirect();
        $document = $case->documents()->firstOrFail();
        $this->assertNull($document->uploaded_by);
        $this->assertSame($customer->id, $document->public_customer_id);
        $this->assertTrue($document->customer_visible);
        Storage::disk('amd_rent_private')->assertExists($document->path);
        $route = route('public-account.documents.download', [$case->reference, $document]);
        $this->get($route)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->signIn($this->customer('other@example.test'));
        $this->get($route)->assertNotFound();
        $this->post(route('public-account.documents.upload', $case->reference), ['kind' => 'customer', 'document' => UploadedFile::fake()->image('test.png')])->assertNotFound();
        $this->signIn($customer);
        $document->update(['customer_visible' => false]);
        $this->get($route)->assertNotFound();
    }

    public function test_upload_rejects_executables_oversized_files_staff_document_types_and_closed_cases(): void
    {
        $case = $this->enquiry();
        $this->signIn($this->customer());
        $route = route('public-account.documents.upload', $case->reference);
        $this->post($route, ['kind' => 'contract', 'document' => UploadedFile::fake()->image('test.png')])->assertSessionHasErrors('kind');
        $this->post($route, ['kind' => 'customer', 'document' => UploadedFile::fake()->createWithContent('fake.pdf', '<?php echo 1;')->mimeType('text/x-php')])->assertSessionHasErrors('document');
        $this->post($route, ['kind' => 'customer', 'document' => UploadedFile::fake()->image('test.png')->size(10241)])->assertSessionHasErrors('document');
        $case->update(['status' => 'signed']);
        $this->post($route, ['kind' => 'customer', 'document' => UploadedFile::fake()->image('test.png')])->assertStatus(409);
        $this->assertDatabaseCount('amd_rent_documents', 0);
    }

    public function test_upload_returns_to_the_case_even_after_a_pdf_download(): void
    {
        $case = $this->enquiry();
        $this->signIn($this->customer());
        $download = route('public-account.documents.download', [$case->reference, 123]);
        $this->withSession(['_previous' => ['url' => $download]])
            ->post(route('public-account.documents.upload', $case->reference), ['kind' => 'customer', 'document' => UploadedFile::fake()->image('documento.png')])
            ->assertRedirect(route('public-account.enquiry', $case->reference));
        $this->withSession(['_previous' => ['url' => $download]])
            ->post(route('public-account.documents.upload', $case->reference), ['kind' => 'invalid'])
            ->assertRedirect(route('public-account.enquiry', $case->reference))->assertSessionHasErrors('document');
    }

    public function test_staff_may_share_only_documents_in_their_own_cases(): void
    {
        $owner = $this->publisher(2, 'renter');
        $other = $this->publisher(3, 'renter');
        $case = $this->enquiry();
        $this->actingAs($owner)->post(route('amd-rent.enquiries.upload', $case), ['kind' => 'quote', 'document' => UploadedFile::fake()->image('test.png')])->assertRedirect();
        $document = $case->documents()->firstOrFail();
        $this->assertFalse($document->customer_visible);
        $route = route('amd-rent.enquiries.document-visibility', [$case, $document]);
        Auth::forgetGuards();
        $this->withSession(['password_hash_sanctum' => $other->password, 'password_hash_web' => $other->password])->actingAs($other, 'web')->put($route, ['customer_visible' => 1])->assertNotFound();
        Auth::forgetGuards();
        $this->withSession(['password_hash_sanctum' => $owner->password, 'password_hash_web' => $owner->password])->actingAs($owner, 'web')->put($route, ['customer_visible' => 1])->assertRedirect();
        $this->assertTrue($document->fresh()->customer_visible);
        $this->get(route('amd-rent.enquiries.show', $case))->assertOk()->assertSee('Nascondi al cliente');
        $this->put($route, ['customer_visible' => 0])->assertRedirect();
        $this->assertFalse($document->fresh()->customer_visible);
    }

    public function test_logout_removes_customer_session_without_logging_out_a_separate_staff_session(): void
    {
        $staff = $this->publisher(2, 'renter');
        $customer = $this->customer();
        $this->actingAs($staff);
        $this->signIn($customer);
        $this->post(route('public-account.logout'))->assertRedirect(route('public-account.login'));
        $this->assertGuest('public_customer');
        $this->assertAuthenticatedAs($staff, 'web');
    }
}
