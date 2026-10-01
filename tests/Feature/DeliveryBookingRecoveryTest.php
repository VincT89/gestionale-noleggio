<?php

namespace Tests\Feature;

use App\Models\{AmdRentEnquiry, PublicBooking, PublicDeliveryLocation, RentalCharge, VehiclePricelist};
use App\Services\AmdRent\BookingPayments;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Http, Schema, URL};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PublicBookingTestCase;

class DeliveryBookingRecoveryTest extends PublicBookingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'amd_rent.payment_mode' => 'stripe',
            'amd_rent.stripe_secret' => 'sk_test_fixture',
            'amd_rent.stripe_webhook_secret' => 'whsec_fixture',
            'amd_rent.stripe_live' => false,
        ]);
        Schema::create('rental_charges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rental_id');
            $table->string('kind');
            $table->boolean('is_commissionable');
            $table->decimal('amount', 12, 2);
            $table->boolean('payment_recorded');
            $table->dateTime('payment_recorded_at');
            $table->string('payment_method');
            $table->string('payment_reference')->nullable();
            $table->uuid('request_key')->nullable();
            $table->string('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['rental_id', 'request_key']);
        });
        $this->stripeResponds();
    }

    private function stripeResponds(?int $error = null): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(['https://api.stripe.com/v1/checkout/sessions' => function ($request) use ($error) {
            if ($error) return Http::response(['error' => ['message' => 'Fixture failure']], $error);
            $booking = PublicBooking::where('reference', $request['client_reference_id'])->firstOrFail();
            return Http::response($this->stripeSession($booking));
        }]);
    }

    private function stripeSession(PublicBooking $booking, array $changes = []): array
    {
        return array_replace([
            'id' => 'cs_test_'.$booking->id, 'object' => 'checkout.session',
            'client_reference_id' => $booking->reference,
            'metadata' => ['booking_reference' => $booking->reference],
            'livemode' => false, 'mode' => 'payment', 'currency' => 'eur',
            'amount_total' => $booking->online_due_cents, 'payment_status' => 'unpaid', 'status' => 'open',
            'payment_intent' => 'pi_test_'.$booking->id,
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_'.$booking->id,
        ], $changes);
    }

    private function quotedDelivery(): AmdRentEnquiry
    {
        $this->offer(organization: 2);
        DB::table('vehicle_assignments')->insert([
            'vehicle_id' => 1, 'renter_org_id' => 2, 'status' => 'active',
            'start_at' => '2026-09-01 00:00:00', 'end_at' => '2026-10-01 00:00:00',
        ]);
        PublicDeliveryLocation::first()->update(['custom_delivery_enabled' => true, 'delivery_area' => 'Zona dimostrativa']);
        $page = $this->get(route('public-cars.booking.create', ['pricelist' => 1] + $this->period()))->assertOk();
        $this->post(route('public-cars.booking.store', 1), $this->contact() + $this->period() + [
            'checkout_token' => $page->viewData('checkoutToken'), 'request_delivery' => 1,
            'delivery_address' => 'Hotel dimostrativo, via di prova 10, Bari',
        ])->assertStatus(303);
        $case = AmdRentEnquiry::firstOrFail();
        $case->update(['status' => 'quoted', 'delivery_fee_cents' => 3000, 'quote_expires_at' => now()->addDay(), 'revision' => 2]);
        DB::table('amd_rent_settings')->insert(['id' => 1, 'delivery_commission_bps' => 0]);
        return $case;
    }

    private function contact(): array
    {
        return ['first_name' => 'Cliente', 'last_name' => 'Dimostrativo', 'email' => 'cliente@example.test',
            'phone' => '+393200000000', 'accept_summary' => 1];
    }

    private function acceptUrl(AmdRentEnquiry $case): string
    {
        return URL::signedRoute('public-enquiries.accept', ['reference' => $case->reference]);
    }

    private function checkoutForm(AmdRentEnquiry $case): array
    {
        $page = $this->post($this->acceptUrl($case), ['accept_quote' => 1])->assertOk();
        return $this->contact() + $page->viewData('filters') + ['checkout_token' => $page->viewData('checkoutToken')];
    }

    private function bookDelivery(AmdRentEnquiry $case): PublicBooking
    {
        $this->post(route('public-cars.booking.store', 1), $this->checkoutForm($case))->assertStatus(303);
        return PublicBooking::latest('id')->firstOrFail();
    }

    private function expire(PublicBooking $booking): void
    {
        app(BookingPayments::class)->settle($this->stripeSession($booking, ['status' => 'expired']));
    }

    private function retryUrl(AmdRentEnquiry $case): string
    {
        return URL::signedRoute('public-enquiries.reopen', ['reference' => $case->reference]);
    }

    private function retryData(AmdRentEnquiry $case): array
    {
        $case->refresh();
        return ['booking_id' => $case->public_booking_id, 'revision' => $case->revision];
    }

    public static function unsuccessfulAttempts(): array
    {
        return ['checkout rejected' => ['failed'], 'checkout expired' => ['expired']];
    }

    #[DataProvider('unsuccessfulAttempts')]
    public function test_customer_can_reopen_an_unpaid_delivery_and_book_again_without_losing_the_proposal(string $status): void
    {
        $case = $this->quotedDelivery();
        $expires = $case->quote_expires_at->toDateTimeString();
        if ($status === 'failed') $this->stripeResponds(400);
        $oldBooking = $this->bookDelivery($case);
        if ($status === 'expired') $this->expire($oldBooking);
        $this->assertSame($status, $oldBooking->fresh()->payment_status);
        $this->get($oldBooking->confirmationUrl())->assertOk()->assertSee('Torna alla richiesta di consegna');
        $data = $this->retryData($case);
        $this->get($case->publicUrl())->assertOk()->assertSee('Riapri richiesta');
        $this->post($this->retryUrl($case), $data)->assertRedirect($case->publicUrl())->assertSessionHasNoErrors();
        $case->refresh();
        $this->assertNull($case->public_booking_id);
        $this->assertSame('quoted', $case->status);
        $this->assertSame(3000, (int) $case->delivery_fee_cents);
        $this->assertSame($expires, $case->quote_expires_at->toDateTimeString());
        $this->assertSame($data['revision'] + 1, $case->revision);
        $this->assertSame(1, $case->events()->count());
        $this->assertStringContainsString($oldBooking->reference, $case->events()->first()->description);
        $this->assertSame('cancelled', $oldBooking->fresh()->rental->status);

        $this->stripeResponds();
        $newBooking = $this->bookDelivery($case);
        $this->assertNotSame($oldBooking->id, $newBooking->id);
        $this->assertSame($newBooking->id, (int) $case->fresh()->public_booking_id);
        $this->assertSame(33000, $newBooking->total_cents);
        $this->assertSame(6000, $newBooking->online_due_cents);
        $this->assertSame('pending', $newBooking->payment_status);
        $this->assertDatabaseCount('public_bookings', 2);
        $this->assertDatabaseCount('rental_charges', 0);
    }

    public function test_double_reopen_is_idempotent_and_an_old_form_cannot_detach_a_new_attempt(): void
    {
        $case = $this->quotedDelivery();
        $old = $this->bookDelivery($case);
        $this->expire($old);
        $data = $this->retryData($case);
        $url = $this->retryUrl($case);
        $this->post($url, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $case->events()->count());
        $next = $this->bookDelivery($case);
        $this->post($url, $data)->assertStatus(409);
        $this->assertSame($next->id, (int) $case->fresh()->public_booking_id);
        $this->assertSame('pending', $next->fresh()->payment_status);
        $this->assertDatabaseCount('public_bookings', 2);
    }

    public function test_reopened_delivery_can_be_booked_when_mysql_reorders_json_period_keys(): void
    {
        $case = $this->quotedDelivery();
        $old = $this->bookDelivery($case);
        $this->expire($old);
        $this->post($this->retryUrl($case), $this->retryData($case))->assertRedirect();

        // MySQL JSON objects return these keys in a different order from validated form fields.
        $context = $case->fresh()->booking_context;
        $period = $context['period'];
        $context['period'] = ['place_id' => $period['place_id'], 'pickup_at' => $period['pickup_at'], 'return_at' => $period['return_at']];
        $case->update(['booking_context' => $context]);

        $new = $this->bookDelivery($case);
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame($new->id, (int) $case->fresh()->public_booking_id);
        $this->assertSame('pending', $new->payment_status);
        $this->assertSame(33000, $new->total_cents);
    }

    public function test_expired_proposal_stays_expired_and_operator_can_requote_after_reopening(): void
    {
        $case = $this->quotedDelivery();
        $old = $this->bookDelivery($case);
        $this->expire($old);
        $case->update(['quote_expires_at' => now()->subMinute()]);
        $this->actingAs($this->publisher(2, 'renter'));
        $this->get(route('amd-rent.enquiries.show', $case))->assertOk()->assertSee('Riapri richiesta');
        $this->post(route('amd-rent.enquiries.reopen', $case), $this->retryData($case))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($case->fresh()->quote_expires_at->isPast());
        $this->post($this->acceptUrl($case), ['accept_quote' => 1])->assertSessionHasErrors('booking');
        $this->put(route('amd-rent.enquiries.update', $case), [
            'revision' => $case->fresh()->revision, 'status' => 'quoted', 'delivery_fee' => '35',
            'quote_valid_until' => '2026-09-09',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(3500, (int) $case->fresh()->delivery_fee_cents);
        $this->assertSame(33500, $this->bookDelivery($case)->total_cents);
    }

    public function test_retry_uses_current_price_and_rechecks_vehicle_availability(): void
    {
        $case = $this->quotedDelivery();
        $old = $this->bookDelivery($case);
        $this->expire($old);
        $this->post($this->retryUrl($case), $this->retryData($case))->assertRedirect();
        VehiclePricelist::findOrFail(1)->update(['base_daily_cents' => 12000]);
        $page = $this->post($this->acceptUrl($case), ['accept_quote' => 1])->assertOk();
        $this->assertSame(39000, $page->viewData('car')['total_cents']);
        $data = $this->contact() + $page->viewData('filters') + ['checkout_token' => $page->viewData('checkoutToken')];
        $old->rental->update(['status' => 'reserved']);
        $this->post(route('public-cars.booking.store', 1), $data)->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('public_bookings', 1);
        $this->assertNull($case->fresh()->public_booking_id);
    }

    public static function protectedAttempts(): array
    {
        return [
            'pending even after local deadline' => [['payment_status' => 'pending'], 'cancelled'],
            'review' => [['payment_status' => 'review'], 'cancelled'],
            'paid' => [['payment_status' => 'paid', 'online_paid_cents' => 6000], 'cancelled'],
            'refunded' => [['payment_status' => 'refunded', 'online_paid_cents' => 6000, 'refunded_cents' => 6000], 'cancelled'],
            'failed with recorded online payment' => [['payment_status' => 'failed', 'online_paid_cents' => 6000], 'cancelled'],
            'expired but vehicle still reserved' => [['payment_status' => 'expired'], 'reserved'],
            'legacy pickup payment' => [['payment_status' => 'failed', 'payment_method' => 'pay_at_pickup'], 'cancelled'],
        ];
    }

    #[DataProvider('protectedAttempts')]
    public function test_reopening_rejects_pending_uncertain_paid_refunded_and_inconsistent_attempts(array $changes, string $rentalStatus): void
    {
        $case = $this->quotedDelivery();
        $booking = $this->bookDelivery($case);
        $booking->update($changes + ['payment_expires_at' => now()->subMinute()]);
        $booking->rental->update(['status' => $rentalStatus]);
        $data = $this->retryData($case);
        $this->get($case->publicUrl())->assertOk()->assertDontSee('Riapri richiesta');
        $this->post($this->retryUrl($case), $data)->assertRedirect($case->publicUrl())->assertSessionHasErrors('booking');
        $this->assertSame($booking->id, (int) $case->fresh()->public_booking_id);
        $this->assertSame($data['revision'], $case->fresh()->revision);
        $this->assertSame(0, $case->events()->count());
    }

    public function test_manual_payment_prevents_reopening_even_if_online_payment_is_expired(): void
    {
        $case = $this->quotedDelivery();
        $booking = $this->bookDelivery($case);
        $this->expire($booking);
        RentalCharge::create([
            'rental_id' => $booking->rental_id, 'kind' => RentalCharge::KIND_ACCONTO,
            'amount' => '10.00', 'is_commissionable' => false, 'payment_recorded' => true,
            'payment_recorded_at' => now(), 'payment_method' => 'cash',
        ]);
        $this->post($this->retryUrl($case), $this->retryData($case))->assertSessionHasErrors('booking');
        $this->assertSame($booking->id, (int) $case->fresh()->public_booking_id);
        $this->assertDatabaseCount('rental_charges', 1);
    }

    public function test_unsigned_or_forged_public_reopening_cannot_change_the_request(): void
    {
        $case = $this->quotedDelivery();
        $booking = $this->bookDelivery($case);
        $this->expire($booking);
        $data = $this->retryData($case);
        $this->post(route('public-enquiries.reopen', $case->reference), $data)->assertForbidden();
        $this->post($this->retryUrl($case), array_replace($data, ['booking_id' => $booking->id + 1]))->assertStatus(409);
        $this->post($this->retryUrl($case), array_replace($data, ['revision' => $data['revision'] - 1]))->assertStatus(409);
        $this->assertSame($booking->id, (int) $case->fresh()->public_booking_id);
        $this->assertSame(0, $case->events()->count());
    }

    public function test_staff_reopening_is_scoped_to_the_assigned_organization_and_write_permission(): void
    {
        $case = $this->quotedDelivery();
        $booking = $this->bookDelivery($case);
        $this->expire($booking);
        $data = $this->retryData($case);
        $this->actingAs($this->publisher(3, 'renter'))
            ->post(route('amd-rent.enquiries.reopen', $case), $data)->assertNotFound();
        $this->flushSession();
        $operator = $this->publisher(2, 'renter');
        DB::table('role_has_permissions')->where('role_id', 2)->where('permission_id', 4)->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($operator)->post(route('amd-rent.enquiries.reopen', $case), $data)->assertForbidden();
        $this->assertSame($booking->id, (int) $case->fresh()->public_booking_id);
        $this->assertSame(0, $case->events()->count());
    }

    public function test_admin_can_reopen_another_organizations_request_and_actor_is_recorded(): void
    {
        $case = $this->quotedDelivery();
        $booking = $this->bookDelivery($case);
        $this->expire($booking);
        $admin = $this->publisher(1, 'admin');
        $this->actingAs($admin)->post(route('amd-rent.enquiries.reopen', $case), $this->retryData($case))
            ->assertRedirect(route('amd-rent.enquiries.show', $case))->assertSessionHasNoErrors();
        $this->assertSame($admin->id, (int) $case->events()->first()->user_id);
        $this->assertNull($case->fresh()->public_booking_id);
    }

    public function test_forms_opened_before_recovery_cannot_create_another_booking(): void
    {
        $case = $this->quotedDelivery();
        $oldUnsubmittedForm = $this->checkoutForm($case);
        $booking = $this->bookDelivery($case);
        $this->expire($booking);
        $this->post($this->retryUrl($case), $this->retryData($case))->assertRedirect();
        $this->post(route('public-cars.booking.store', 1), $oldUnsubmittedForm)
            ->assertRedirect($case->publicUrl())->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('public_bookings', 1);
        $this->assertDatabaseCount('rental_charges', 0);
    }

    public static function latePaymentMoments(): array
    {
        return ['before new summary' => [false], 'after new summary' => [true]];
    }

    #[DataProvider('latePaymentMoments')]
    public function test_a_late_payment_on_the_original_attempt_blocks_a_new_reservation(bool $openedSummary): void
    {
        $case = $this->quotedDelivery();
        $old = $this->bookDelivery($case);
        $this->expire($old);
        $this->post($this->retryUrl($case), $this->retryData($case))->assertRedirect();
        $form = $openedSummary ? $this->checkoutForm($case) : null;
        app(BookingPayments::class)->settle($this->stripeSession($old, ['status' => 'complete', 'payment_status' => 'paid']));
        $this->assertSame('review', $old->fresh()->payment_status);
        if ($form) {
            $this->post(route('public-cars.booking.store', 1), $form)->assertSessionHasErrors('booking');
        } else {
            $this->post($this->acceptUrl($case), ['accept_quote' => 1])->assertSessionHasErrors('booking');
        }
        $this->assertDatabaseCount('public_bookings', 1);
        $this->assertDatabaseCount('rental_charges', 0);
        $this->assertNull($case->fresh()->public_booking_id);
    }

    public function test_a_late_payment_blocks_resuming_a_newer_pending_checkout(): void
    {
        $case = $this->quotedDelivery();
        $old = $this->bookDelivery($case);
        $this->expire($old);
        $this->post($this->retryUrl($case), $this->retryData($case))->assertRedirect();
        $current = $this->bookDelivery($case);
        app(BookingPayments::class)->settle($this->stripeSession($old, ['status' => 'complete', 'payment_status' => 'paid']));
        $this->stripeResponds();
        $this->post(URL::signedRoute('public-bookings.pay', ['reference' => $current->reference]))
            ->assertRedirect($current->confirmationUrl())->assertSessionHas('payment_error');
        Http::assertNothingSent();
        $this->assertSame($current->id, (int) $case->fresh()->public_booking_id);
    }
}
