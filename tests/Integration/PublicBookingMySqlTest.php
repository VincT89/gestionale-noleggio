<?php

namespace Tests\Integration;

use App\Domain\Rentals\{PublicBookingService, PublicVehicleSearch};
use App\Models\{Location, Organization, PublicBooking, PublicRentalOffer, RentalContractSnapshot, User, Vehicle, VehicleAssignment, VehiclePricelist};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Artisan, Crypt, DB, Http, Mail};
use Spatie\Permission\Models\{Permission, Role};
use Symfony\Component\Process\Process;
use Tests\Support\MySqlQaGuard;
use Tests\TestCase;

class PublicBookingMySqlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MySqlQaGuard::check(empty: true);
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]), Artisan::output());
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00:00', 'Europe/Rome'));
        Http::preventStrayRequests();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        DB::disconnect();
        parent::tearDown();
    }

    public function test_concurrent_booking_delivery_pdf_price_freeze_and_cancellation_on_mysql(): void
    {
        $owner = Organization::create(['name' => 'Proprietario QA', 'type' => 'admin', 'is_active' => true]);
        $operator = Organization::create(['name' => 'Noleggiatore QA', 'type' => 'renter', 'is_active' => true]);
        $other = Organization::create(['name' => 'Altro noleggiatore QA', 'type' => 'renter', 'is_active' => true]);
        $location = Location::create(['organization_id' => $operator->id, 'name' => 'Sede QA', 'city' => 'Bari', 'address_line' => 'Indirizzo dimostrativo']);
        $vehicle = Vehicle::create(['admin_organization_id' => $owner->id, 'default_pickup_location_id' => $location->id,
            'plate' => 'QA001AA', 'make' => 'Toyota', 'model' => 'Yaris', 'fuel_type' => 'petrol',
            'transmission' => 'manual', 'seats' => 5, 'year' => 2024, 'segment' => 'compact', 'is_active' => true]);
        $assignment = VehicleAssignment::create(['vehicle_id' => $vehicle->id, 'renter_org_id' => $operator->id,
            'status' => 'active', 'start_at' => '2026-09-01 00:00:00', 'end_at' => null]);
        $price = VehiclePricelist::create(['vehicle_id' => $vehicle->id, 'renter_org_id' => $operator->id,
            'name' => 'Listino QA', 'currency' => 'EUR', 'base_daily_cents' => 10000, 'weekend_pct' => 0,
            'km_included_per_day' => 100, 'extra_km_cents' => 30, 'deposit_cents' => 50000, 'rounding' => 'none',
            'version' => 1, 'status' => 'active', 'active_flag' => true, 'is_active' => true]);
        $offer = PublicRentalOffer::create(['vehicle_id' => $vehicle->id, 'organization_id' => $operator->id,
            'location_id' => $location->id, 'pricelist_id' => $price->id, 'prices_include_vat' => true, 'is_published' => true]);
        $role = Role::findOrCreate('renter', 'web');
        foreach (['rentals.viewAny', 'rentals.view', 'rentals.cancel', 'vehicle_pricing.update'] as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $user = $this->operator($operator, 'operator@example.test', $role);
        $outsider = $this->operator($other, 'other@example.test', $role);
        $period = ['pickup_at' => '2026-09-10T10:00', 'return_at' => '2026-09-13T10:00'];
        $search = app(PublicVehicleSearch::class);
        $this->assertCount(1, $search->search(PublicRentalOffer::published(), $period));
        $intents = [];
        for ($i = 0; $i < 2; $i++) {
            $page = $this->get(route('public-cars.booking.create', ['offer' => $offer->id] + $period))->assertOk();
            $intents[] = json_decode(Crypt::decryptString($page->viewData('checkoutToken')), true, 32, JSON_THROW_ON_ERROR);
        }
        $contact = ['first_name' => 'Cliente', 'last_name' => 'Dimostrativo', 'email' => 'customer@example.test', 'phone' => '+393200000000'];
        $results = $this->race($operator->id, $vehicle->id, $intents, $contact);
        $statuses = array_column($results, 'status'); sort($statuses);
        $this->assertSame(['confirmed', 'unavailable'], $statuses);
        foreach (['public_bookings', 'rentals', 'customers', 'renter_contract_number_ledger'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        $this->assertDatabaseCount('rental_charges', 0);
        $booking = PublicBooking::with('rental')->firstOrFail();
        $this->assertSame((int) $operator->id, (int) $booking->organization_id);
        $this->assertSame((int) $operator->id, (int) $booking->rental->organization_id);
        $this->assertSame((int) $operator->id, (int) $booking->rental->customer->organization_id);
        $this->assertSame((int) $assignment->id, (int) $booking->rental->assignment_id);
        $this->assertSame('reserved', $booking->rental->status);
        $this->assertSame('pay_at_pickup', $booking->payment_method);
        $this->assertSame(30000, $booking->total_cents);
        $this->assertSame(50000, $booking->deposit_cents);
        $this->assertCount(0, $search->search(PublicRentalOffer::published(), $period));
        $winner = array_search('confirmed', array_column($results, 'status'), true);
        $retry = app(PublicBookingService::class)->reserve($intents[$winner], $contact);
        $this->assertSame($booking->id, $retry->id);
        $this->assertDatabaseCount('customers', 1);
        $this->actingAs($user)->get(route('public-bookings.index'))->assertOk()->assertSee($booking->reference)
            ->assertSee('Scarica PDF')->assertSee('Prepara WhatsApp');
        $this->flushSession();
        $this->actingAs($outsider)->get(route('public-bookings.index'))->assertOk()->assertDontSee($booking->reference);
        $this->flushSession();
        $this->actingAs($user)->put(route('public-offers.update', $offer), [
            'pricelist_id' => $price->id, 'location_id' => $location->id, 'prices_include_vat' => 1,
            'is_published' => 1, 'deposit_euros' => '750,25',
        ])->assertRedirect(route('public-offers.index'));
        $this->assertSame(75025, $price->fresh()->deposit_cents);
        $this->assertSame(50000, $booking->fresh()->deposit_cents);
        $this->assertSame(50000, RentalContractSnapshot::firstOrFail()->pricing_snapshot['deposit_cents']);
        $confirmation = $booking->confirmationUrl();
        $this->get($confirmation)->assertOk()->assertSee('Prenotazione confermata')->assertSee('500,00')->assertDontSee('750,25');
        $this->get(route('public-bookings.pdf', $booking->reference))->assertForbidden();
        $pdf = $this->get($booking->pdfUrl(true))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('attachment;', $pdf->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        file_put_contents(storage_path('logs/'.MySqlQaGuard::check().'-confirmation.pdf'), $pdf->getContent());
        $message = rawurldecode($booking->whatsappComposeUrl());
        foreach ([$booking->reference, 'Noleggiatore QA', 'Toyota Yaris', '300,00 EUR', '500,00 EUR'] as $value) {
            $this->assertStringContainsString($value, $message);
        }
        $this->assertStringNotContainsString('localhost', $message);
        $this->postJson(route('rentals.cancel', $booking->rental_id))->assertOk()->assertJson(['status' => 'cancelled']);
        $this->get($confirmation)->assertOk()->assertSee('Prenotazione annullata')->assertDontSee('l’auto è riservata');
        $this->assertCount(1, $search->search(PublicRentalOffer::published(), $period));
        $this->assertStringContainsString('Annullata', $booking->fresh()->shareText());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    private function operator(Organization $organization, string $email, Role $role): User
    {
        $user = User::create(['organization_id' => $organization->id, 'name' => 'Operatore QA',
            'email' => $email, 'password' => 'qa-password', 'is_active' => true]);
        $user->forceFill(['email_verified_at' => now()])->save();
        return $user->assignRole($role);
    }

    private function race(int $organization, int $vehicle, array $intents, array $contact): array
    {
        $workers = [];
        DB::beginTransaction();
        try {
            Organization::whereKey($organization)->lockForUpdate()->firstOrFail();
            Vehicle::whereKey($vehicle)->lockForUpdate()->firstOrFail();
            foreach ($intents as $intent) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/public-booking-mysql-worker.php')], base_path(), null,
                    json_encode(['intent' => $intent, 'contact' => $contact, 'now' => now()->toIso8601String()], JSON_THROW_ON_ERROR), 30);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 15;
            foreach ($workers as $worker) {
                while (!str_contains($worker->getOutput(), 'WAITING_FOR_LOCK')) {
                    if (!$worker->isRunning()) $this->fail($worker->getErrorOutput().$worker->getOutput());
                    if (microtime(true) >= $deadline) $this->fail('Worker did not reach the reservation lock.');
                    usleep(20000);
                }
            }
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput().$worker->getOutput());
                $this->assertSame(1, preg_match('/^RESULT (.+)$/m', $worker->getOutput(), $match), $worker->getOutput());
                $results[] = json_decode($match[1], true, 32, JSON_THROW_ON_ERROR);
            }
            return $results;
        } finally {
            if (DB::transactionLevel() > 0) DB::rollBack();
            foreach ($workers as $worker) if ($worker->isRunning()) $worker->stop();
        }
    }
}
