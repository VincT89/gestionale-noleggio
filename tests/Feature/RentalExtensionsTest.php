<?php

namespace Tests\Feature;

use App\Domain\Fees\AdminFeeResolver;
use App\Livewire\Rentals\{CreateWizard, Show};
use App\Models\{Customer, Organization, OrganizationFee, Rental, User, Vehicle, VehicleAssignment, VehicleBlock};
use App\Services\Contracts\GenerateRentalContract;
use App\Services\Rentals\RentalExtensionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail, Storage, View};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RentalExtensionsTest extends TestCase
{
    use RefreshDatabase;

    private Rental $rental;
    private User $operator;

    protected function beforeRefreshingDatabase(): void
    {
        $database = (string) getenv('R4_QA_DATABASE');
        if (config('database.default') !== 'mysql' || $database === '') {
            $this->markTestSkipped('Usare php tests/mysql-payments.php per il database MySQL isolato.');
        }
        if (!preg_match('/^adm_era_qa_payments_[0-9]{8}_[0-9]{6}_[a-f0-9]{6}$/', $database)
            || config('database.connections.mysql.database') !== $database
            || !in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1', '::1'], true)
            || config('database.connections.mysql.url') || config('database.connections.mysql.unix_socket')) {
            throw new \RuntimeException('Database non consentito per il collaudo.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        $this->travelTo(now()->setDate(2026, 9, 9)->setTime(10, 0));
        $owner = Organization::factory()->admin()->create(['name' => 'Proprietario di collaudo']);
        $renter = Organization::factory()->renter()->create(['name' => 'Noleggiatore di collaudo', 'rental_license' => true]);
        $this->operator = User::factory()->create(['organization_id' => $renter->id]);
        foreach (['rentals.view', 'rentals.viewAny', 'rentals.update', 'rentals.create', 'rentals.contract.generate',
            'media.attach.contract', 'media.attach.rental_document'] as $name) {
            $this->operator->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $customer = Customer::factory()->create(['organization_id' => $renter->id, 'name' => 'Cliente di collaudo',
            'email' => 'customer@example.test', 'driver_license_expires_at' => '2030-01-01']);
        $vehicle = Vehicle::factory()->create(['admin_organization_id' => $owner->id,
            'default_pickup_location_id' => null, 'is_active' => true, 'make' => 'Auto di collaudo', 'model' => 'Proroga']);
        $assignment = VehicleAssignment::create(['vehicle_id' => $vehicle->id, 'renter_org_id' => $renter->id,
            'start_at' => '2026-09-01 00:00:00', 'end_at' => null, 'status' => 'active']);
        $this->rental = Rental::create(['organization_id' => $renter->id, 'vehicle_id' => $vehicle->id,
            'assignment_id' => $assignment->id, 'customer_id' => $customer->id, 'number_id' => 1, 'amount' => 90,
            'planned_pickup_at' => '2026-09-07 10:00:00', 'planned_return_at' => '2026-09-10 10:00:00',
            'actual_pickup_at' => '2026-09-07 10:00:00', 'status' => 'in_use']);
        $this->rental->contractSnapshot()->create(['pricing_snapshot' => ['days' => 3, 'currency' => 'EUR',
            'tariff_total_cents' => 9000, 'km_daily_limit' => 100, 'extra_km_cents' => 20, 'deposit_cents' => 30000]]);
        OrganizationFee::create(['organization_id' => $renter->id, 'percent' => 15, 'effective_from' => '2026-09-01']);
        $this->actingAs($this->operator);
    }

    private function data(array $extra = []): array
    {
        $current = $this->rental->fresh();
        return $extra + [
            'extensionReturnAt' => '2026-09-12T10:00', 'extensionAmount' => '60.00',
            'extensionNotes' => 'Accordo di collaudo', 'extensionRequestKey' => (string) Str::uuid(),
            'extensionExpectedReturnAt' => $current->planned_return_at->format('Y-m-d H:i:s'),
            'extensionExpectedAmount' => (string) $current->amount,
            'extensionExpectedOverride' => $current->final_amount_override,
        ];
    }

    private function extend(array $data = [])
    {
        return app(RentalExtensionService::class)->extend($this->rental, $data ?: $this->data(), $this->operator);
    }

    private function reject(array $data = []): void
    {
        try {
            $this->extend($data);
            $this->fail('La proroga doveva essere rifiutata.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
        $this->assertSame(0, $this->rental->extensions()->count());
        $this->assertEquals(90, $this->rental->fresh()->amount);
        $this->assertEquals(3, $this->rental->contractSnapshot()->first()->pricing_snapshot['days']);
    }

    public function test_extension_updates_dates_price_km_and_history_without_recording_a_payment(): void
    {
        $this->postJson(route('rentals.record_payment', $this->rental),
            ['kind' => 'base', 'amount' => 90, 'payment_method' => 'cash', 'request_key' => (string) Str::uuid()])->assertOk();
        $extension = $this->extend();
        $current = $this->rental->fresh();
        $this->assertSame('2026-09-12 10:00:00', $current->planned_return_at->format('Y-m-d H:i:s'));
        $this->assertNull($current->actual_return_at);
        $this->assertSame('in_use', $current->status);
        $this->assertEquals(150, $current->amount);
        $this->assertEquals(90, $current->base_paid_total);
        $this->assertSame(1, $current->charges()->count());
        $this->assertEquals(13.5, app(AdminFeeResolver::class)->calculateForRental($current)['amount']);
        $this->assertEquals(5, $extension->new_pricing['days']);
        $this->assertEquals(3, $extension->previous_pricing['days']);
        $this->assertEquals(30000, $extension->new_pricing['deposit_cents']);
        $current->update(['mileage_out' => 1000, 'mileage_in' => 1600]);
        $this->assertEquals(100, $current->distance_overage_km);
        $this->postJson(route('rentals.record_payment', $current),
            ['kind' => 'base', 'amount' => 60, 'payment_method' => 'bank_transfer', 'request_key' => (string) Str::uuid()])->assertOk();
        $this->assertEquals(22.5, app(AdminFeeResolver::class)->calculateForRental($current)['amount']);
        $this->assertEquals(150, $current->fresh()->base_paid_total);
    }

    public function test_livewire_shows_the_new_return_and_history_and_refreshes_the_payment_balance(): void
    {
        Livewire::test(Show::class, ['rental' => $this->rental])
            ->call('openExtension')->set('extensionReturnAt', '2026-09-12T10:00')
            ->set('extensionAmount', '60.00')->set('extensionNotes', 'Due giorni concordati')
            ->call('saveExtension')->assertHasNoErrors()->assertSet('extensionOpen', false)
            ->assertSee('12/09/2026 10:00')->assertSee('150,00 €')->assertSee('Due giorni concordati')
            ->assertDispatched('rental-amount-updated', base_amount: 150);
    }

    public function test_replay_is_idempotent_but_another_stale_form_cannot_extend_again(): void
    {
        $data = $this->data();
        $this->extend($data);
        $this->extend($data);
        $this->assertSame(1, $this->rental->extensions()->count());
        try {
            $this->extend(array_replace($data, ['extensionRequestKey' => (string) Str::uuid(), 'extensionReturnAt' => '2026-09-13T10:00']));
            $this->fail('Modulo precedente accettato.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('contratto è cambiato', $exception->getMessage());
        }
        $this->assertEquals(150, $this->rental->fresh()->amount);
    }

    public function test_a_replayed_key_cannot_change_the_agreed_amount(): void
    {
        $data = $this->data();
        $this->extend($data);
        $this->expectException(ValidationException::class);
        $this->extend(array_replace($data, ['extensionAmount' => '70.00']));
    }

    public function test_a_changed_price_is_detected_before_confirmation(): void
    {
        $data = $this->data();
        $this->rental->update(['final_amount_override' => 80]);
        $this->reject($data);
    }

    public function test_an_existing_override_and_a_second_extension_keep_the_exact_agreed_increments(): void
    {
        $this->rental->update(['final_amount_override' => 80]);
        $this->extend($this->data(['extensionAmount' => '12.35']));
        $this->extend($this->data(['extensionReturnAt' => '2026-09-13T10:00', 'extensionAmount' => '0']));
        $current = $this->rental->fresh();
        $this->assertEquals(102.35, $current->amount);
        $this->assertSame('92.35', $current->final_amount_override);
        $this->assertSame(9235, $current->contractSnapshot->pricing_snapshot['tariff_override_cents']);
        $this->assertSame(2, $current->extensions()->count());
    }

    public static function invalidStates(): array
    {
        return [['draft'], ['checked_in'], ['closed'], ['cancelled'], ['no_show']];
    }

    #[DataProvider('invalidStates')]
    public function test_returned_closed_or_ineligible_rentals_cannot_be_extended(string $status): void
    {
        $this->rental->update(['status' => $status]);
        $this->reject();
    }

    public function test_an_actual_return_blocks_extension_even_if_the_status_is_stale(): void
    {
        $this->rental->update(['actual_return_at' => '2026-09-09 09:00:00']);
        $this->reject();
    }

    public static function invalidInputs(): array
    {
        return [
            [['extensionReturnAt' => '2026-09-10T10:00']],
            [['extensionReturnAt' => '2026-09-08T10:00']],
            [['extensionAmount' => '-1']], [['extensionAmount' => '1.111']],
            [['extensionAmount' => '99999999.99']],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_dates_and_prices_cannot_partially_change_the_contract(array $data): void
    {
        $this->reject($this->data($data));
    }

    public function test_overlapping_booking_blocks_an_extension(): void
    {
        $other = $this->rental->replicate(['actual_pickup_at']);
        $other->fill(['number_id' => 2, 'status' => 'reserved', 'planned_pickup_at' => '2026-09-11 10:00:00',
            'planned_return_at' => '2026-09-14 10:00:00'])->save();
        $this->reject();
    }

    public function test_date_only_extension_keeps_a_reminder_until_price_and_payment_are_entered(): void
    {
        $this->postJson(route('rentals.record_payment', $this->rental),
            ['kind' => 'base', 'amount' => 90, 'payment_method' => 'cash', 'request_key' => (string) Str::uuid()])->assertOk();
        $component = Livewire::test(Show::class, ['rental' => $this->rental])
            ->call('openExtension')->set('extensionReturnAt', '2026-09-12T10:00')
            ->call('saveExtension')->assertHasNoErrors()->assertSee('Importo della proroga da definire');
        $extension = $this->rental->extensions()->sole();
        $this->assertNull($extension->additional_amount);
        $this->assertEquals(90, $this->rental->fresh()->amount);
        $this->assertSame(1, $this->rental->charges()->count());
        $component->call('openExtensionPrice', $extension->id)->set('extensionAmount', '60')
            ->call('saveExtension')->assertHasNoErrors()->assertSee('Pagamento da registrare')
            ->assertSee('60,00 €')->assertDontSee('Importo della proroga da definire');
        $this->assertEquals(150, $this->rental->fresh()->amount);
        $this->assertSame(1, $this->rental->charges()->count());
        $this->postJson(route('rentals.record_payment', $this->rental),
            ['kind' => 'base', 'amount' => 60, 'payment_method' => 'cash', 'request_key' => (string) Str::uuid()])->assertOk();
        $component->dispatch('rental-payment-recorded', rentalId: $this->rental->id)->assertDontSee('Pagamento da registrare');
        $this->assertEquals(22.5, app(AdminFeeResolver::class)->calculateForRental($this->rental)['amount']);
    }

    public function test_a_cancelled_booking_or_one_starting_exactly_at_return_does_not_block(): void
    {
        foreach (['cancelled', 'reserved'] as $index => $status) {
            $other = $this->rental->replicate(['actual_pickup_at']);
            $other->fill(['number_id' => $index + 2, 'status' => $status,
                'planned_pickup_at' => $status === 'reserved' ? '2026-09-12 10:00:00' : '2026-09-11 10:00:00',
                'planned_return_at' => '2026-09-14 10:00:00'])->save();
        }
        $this->extend();
        $this->assertEquals(150, $this->rental->fresh()->amount);
    }

    public function test_a_vehicle_block_prevents_extension(): void
    {
        VehicleBlock::create(['vehicle_id' => $this->rental->vehicle_id, 'organization_id' => $this->rental->organization_id,
            'type' => 'maintenance', 'status' => 'scheduled', 'start_at' => '2026-09-11 10:00:00', 'end_at' => '2026-09-13 10:00:00']);
        $this->reject();
    }

    public function test_assignment_must_cover_the_new_return(): void
    {
        $this->rental->assignment->update(['end_at' => '2026-09-11 10:00:00']);
        $this->reject();
    }

    public function test_primary_and_second_driver_licenses_cover_the_new_return(): void
    {
        $second = Customer::factory()->create(['organization_id' => $this->rental->organization_id,
            'driver_license_expires_at' => '2026-09-11']);
        $this->rental->update(['second_driver_id' => $second->id]);
        $this->reject();
    }

    public function test_another_renter_cannot_extend_this_contract(): void
    {
        $other = User::factory()->create(['organization_id' => Organization::factory()->renter()->create()->id]);
        $other->givePermissionTo('rentals.update');
        $this->actingAs($other);
        Livewire::test(Show::class, ['rental' => $this->rental])->assertForbidden();
        $this->assertSame(0, $this->rental->extensions()->count());
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(RentalExtensionService::class)->extend($this->rental, $this->data(), $other);
    }

    public function test_the_new_rental_wizard_rechecks_the_period_when_saving(): void
    {
        $this->extend();
        $location = \App\Models\Location::factory()->create(['organization_id' => $this->rental->organization_id]);
        Livewire::test(CreateWizard::class)
            ->set('rentalData.vehicle_id', $this->rental->vehicle_id)
            ->set('rentalData.pickup_location_id', $location->id)
            ->set('rentalData.return_location_id', $location->id)
            ->set('rentalData.planned_pickup_at', '2026-09-11T10:00')
            ->set('rentalData.planned_return_at', '2026-09-14T10:00')
            ->call('saveDraft')->assertHasErrors('rentalData.planned_return_at');
        $this->assertSame(1, Rental::count());
    }

    public function test_an_old_signature_cannot_sign_the_updated_contract_and_old_pdf_is_kept(): void
    {
        $oldPdf = app(GenerateRentalContract::class)->handle($this->rental, forceUnsigned: true);
        $oldBytes = file_get_contents($oldPdf->getPath());
        $signature = $this->rental->addMedia(\Illuminate\Http\UploadedFile::fake()->image('firma-di-collaudo.png', 40, 20))
            ->toMediaCollection('signature_customer');
        $this->assertNotNull($this->rental->fresh()->currentCustomerSignature());
        $this->extend();
        $this->assertNull($this->rental->fresh()->currentCustomerSignature());
        try {
            app(GenerateRentalContract::class)->handle($this->rental, forceSigned: true);
            $this->fail('Una firma precedente è stata riutilizzata.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('signature', $exception->errors());
        }
        $captured = [];
        View::composer('contracts.rental', function ($view) use (&$captured) { $captured = $view->getData(); });
        $newPdf = app(GenerateRentalContract::class)->handle($this->rental, forceUnsigned: true);
        $this->assertSame(5, $captured['pricing']['days']);
        $this->assertSame(15000, $captured['pricing_totals']['computed_total_cents']);
        $this->assertSame('12/09/2026 10:00', $captured['rental']['return_at']);
        $this->assertSame($oldBytes, file_get_contents($oldPdf->fresh()->getPath()));
        $this->assertSame(2, $this->rental->media()->where('collection_name', 'contract')->count());
        $this->assertEquals($this->rental->contractRevision(), $newPdf->getCustomProperty('rental_extension_id'));
        $signature->setCustomProperty('rental_extension_id', $this->rental->contractRevision())->save();
        $this->assertNotNull($this->rental->fresh()->currentCustomerSignature());
        if ($output = getenv('R4_QA_ARTIFACTS')) {
            if (!is_dir($output)) mkdir($output, 0777, true);
            copy($newPdf->getPath(), $output.'/proroga-contratto.pdf');
        }
    }

    public function test_the_agreed_extension_includes_extras_without_adding_an_automatic_second_driver_fee(): void
    {
        $second = Customer::factory()->create(['organization_id' => $this->rental->organization_id]);
        $this->rental->update(['second_driver_id' => $second->id, 'amount' => 105]);
        $snap = $this->rental->contractSnapshot;
        $snap->update(['pricing_snapshot' => $snap->pricing_snapshot + ['second_driver_daily_cents' => 500]]);
        $this->extend();
        $captured = [];
        View::composer('contracts.rental', function ($view) use (&$captured) { $captured = $view->getData(); });
        $media = app(GenerateRentalContract::class)->handle($this->rental, forceUnsigned: true);
        $this->assertEquals(165, $this->rental->fresh()->amount);
        $this->assertSame(1500, $captured['pricing_totals']['second_driver_cents']);
        $this->assertSame(16500, $captured['pricing_totals']['computed_total_cents']);
        if ($output = getenv('R4_QA_ARTIFACTS')) {
            if (!is_dir($output)) mkdir($output, 0777, true);
            copy($media->getPath(), $output.'/proroga-seconda-guida.pdf');
        }
    }

    public function test_signature_upload_checks_the_contract_version(): void
    {
        $this->extend();
        $url = route('rentals.signature.customer.store', $this->rental);
        $this->postJson($url, ['file' => \Illuminate\Http\UploadedFile::fake()->image('firma-di-collaudo.png')])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson($url, [
            'file' => \Illuminate\Http\UploadedFile::fake()->image('firma-di-collaudo.png'),
            'contract_revision' => $this->rental->contractRevision(),
        ])->assertCreated();
        $this->assertNotNull($this->rental->fresh()->currentCustomerSignature());
    }

    public function test_a_date_only_extension_cannot_generate_or_sign_a_price_not_yet_agreed(): void
    {
        $this->extend($this->data(['extensionAmount' => '']));
        $this->postJson(route('rentals.signature.customer.store', $this->rental), [
            'file' => \Illuminate\Http\UploadedFile::fake()->image('firma-di-collaudo.png'),
            'contract_revision' => $this->rental->contractRevision(),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->expectException(ValidationException::class);
        app(GenerateRentalContract::class)->handle($this->rental, forceUnsigned: true);
    }
}
