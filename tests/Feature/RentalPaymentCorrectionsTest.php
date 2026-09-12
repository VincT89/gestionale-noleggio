<?php

namespace Tests\Feature;

use App\Domain\Rentals\Guards\CloseRentalGuard;
use App\Livewire\Rentals\Show;
use App\Models\{Organization, OrganizationFee, Rental, RentalCharge, RentalChecklist, RentalContractSnapshot, User, Vehicle, VehicleAssignment};
use App\Services\Rentals\RentalPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http, Mail};
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RentalPaymentCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;
    private Rental $rental;

    protected function beforeRefreshingDatabase(): void
    {
        $database = (string) getenv('R4_QA_DATABASE');
        if (config('database.default') !== 'mysql' || $database === '') {
            $this->markTestSkipped('Usare un database MySQL di collaudo isolato.');
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
        $this->travelTo(now()->setDate(2026, 9, 11)->setTime(12, 0));
        $owner = Organization::factory()->admin()->create(['name' => 'Proprietario fittizio', 'email' => 'owner@example.test']);
        $renter = Organization::factory()->renter()->create(['name' => 'Noleggiatore fittizio', 'email' => 'renter@example.test']);
        $this->operator = User::factory()->create(['organization_id' => $renter->id, 'name' => 'Operatore fittizio', 'email' => 'operator@example.test']);
        foreach (['rentals.viewAny', 'rentals.view', 'rentals.update', 'rentals.close'] as $name) {
            $this->operator->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $vehicle = Vehicle::factory()->create(['admin_organization_id' => $owner->id, 'default_pickup_location_id' => null]);
        $assignment = VehicleAssignment::create(['vehicle_id' => $vehicle->id, 'renter_org_id' => $renter->id,
            'start_at' => '2026-09-01 00:00:00', 'status' => 'active']);
        $this->rental = Rental::create(['organization_id' => $renter->id, 'vehicle_id' => $vehicle->id,
            'assignment_id' => $assignment->id, 'number_id' => 164, 'amount' => 30, 'final_amount_override' => 50,
            'planned_pickup_at' => '2026-09-09 15:00:00', 'planned_return_at' => '2026-09-10 15:00:00',
            'actual_return_at' => '2026-09-10 14:11:00', 'status' => 'checked_in']);
        foreach (['pickup' => 10000, 'return' => 10100] as $type => $mileage) {
            RentalChecklist::create(['rental_id' => $this->rental->id, 'type' => $type, 'mileage' => $mileage, 'fuel_percent' => 100]);
        }
        RentalContractSnapshot::create(['rental_id' => $this->rental->id,
            'pricing_snapshot' => ['days' => 1, 'km_daily_limit' => null, 'extra_km_cents' => null,
                'tariff_total_cents' => 3000, 'tariff_override_cents' => 5000]]);
        OrganizationFee::create(['organization_id' => $renter->id, 'percent' => 15, 'effective_from' => '2026-09-01']);
        $this->actingAs($this->operator);
    }

    private function pay(string $kind = 'base', string $amount = '50.00', ?string $key = null): RentalCharge
    {
        $response = $this->postJson(route('rentals.record_payment', $this->rental), [
            'kind' => $kind, 'amount' => $amount, 'payment_method' => 'cash', 'request_key' => $key ?? (string) Str::uuid(),
        ])->assertOk();
        return RentalCharge::findOrFail($response->json('payment_id'));
    }

    private function screen()
    {
        return Livewire::test(Show::class, ['rental' => $this->rental]);
    }

    public static function mileageSnapshots(): array
    {
        return [
            'unlimited' => [['km_daily_limit' => null, 'days' => 1], 0],
            'explicit unlimited overrides legacy zero' => [['km_daily_limit' => null, 'included_km_total' => 0], 0],
            'missing limit' => [[], 0],
            'zero included is a real limit' => [['km_daily_limit' => 0, 'days' => 1], 100],
            'daily limit' => [['km_daily_limit' => 25, 'days' => 2], 50],
            'within daily limit' => [['km_daily_limit' => 50, 'days' => 2], 0],
            'legacy total remains valid' => [['included_km_total' => 25], 75],
        ];
    }

    #[DataProvider('mileageSnapshots')]
    public function test_unlimited_zero_and_legacy_mileage_limits_are_distinguished(array $snapshot, int $expected): void
    {
        $this->rental->contractSnapshot->update(['pricing_snapshot' => $snapshot]);
        $this->assertSame($expected, $this->rental->fresh()->distance_overage_km);
    }

    public function test_the_paid_unlimited_rental_closes_without_creating_another_payment(): void
    {
        $this->pay();
        $this->getJson(route('rentals.distance_overage', $this->rental))->assertOk()->assertJsonPath('km_extra', 0)->assertJsonPath('amount', 0);
        $this->postJson(route('rentals.close', $this->rental))->assertOk()->assertJsonPath('status', 'closed');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson(route('rentals.close', $this->rental))->assertUnprocessable();
        }
        $this->assertSame(1, $this->rental->charges()->count());
        $this->assertEquals(50, $this->rental->charges()->sum('amount'));
    }

    public function test_a_real_mileage_excess_still_requires_its_payment(): void
    {
        $this->rental->contractSnapshot->update(['pricing_snapshot' => ['days' => 2, 'km_daily_limit' => 25, 'extra_km_cents' => 50]]);
        $this->pay();
        $this->getJson(route('rentals.distance_overage', $this->rental))->assertOk()->assertJsonPath('km_extra', 50)->assertJsonPath('amount', 25);
        $this->postJson(route('rentals.close', $this->rental))->assertUnprocessable()->assertJsonPath('code', 'overage_unpaid');
        $this->postJson(route('rentals.record_payment', $this->rental), [
            'kind' => 'other', 'amount' => '25.00', 'payment_method' => 'cash',
            'payment_notes' => 'KM EXTRA', 'request_key' => (string) Str::uuid(),
        ])->assertOk();
        $this->assertFalse($this->rental->fresh()->has_distance_overage_payment);
        $this->postJson(route('rentals.close', $this->rental))->assertUnprocessable()->assertJsonPath('code', 'overage_unpaid');
        $this->pay('distance_overage', '25.00');
        $this->postJson(route('rentals.close', $this->rental))->assertOk();
    }

    public function test_the_reported_payment_history_does_not_block_an_unlimited_contract_or_create_more_charges(): void
    {
        for ($index = 0; $index < 5; $index++) {
            $this->pay();
        }
        $this->postJson(route('rentals.record_payment', $this->rental), [
            'kind' => 'other', 'amount' => '50.00', 'payment_method' => 'pos',
            'payment_notes' => 'KM EXTRA', 'request_key' => (string) Str::uuid(),
        ])->assertOk();
        $this->assertSame('other', $this->rental->charges()->where('description', 'KM EXTRA')->sole()->kind);
        $this->assertFalse($this->rental->fresh()->has_distance_overage_payment);
        $this->assertSame(0, $this->rental->fresh()->distance_overage_km);
        $this->postJson(route('rentals.close', $this->rental))->assertOk();
        $this->assertSame(6, $this->rental->charges()->count());
        $this->assertEquals(300, $this->rental->charges()->sum('amount'));
        $this->assertSame('50.00', $this->rental->fresh()->final_amount_override);
    }

    public function test_the_old_payment_form_is_rejected_without_creating_a_charge(): void
    {
        $this->postJson(route('rentals.record_payment', $this->rental), [
            'kind' => 'base', 'amount' => 50, 'payment_method' => 'cash',
        ])->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $this->assertSame(0, $this->rental->charges()->count());
    }

    public function test_deleting_a_payment_refreshes_history_balance_and_close_requirements(): void
    {
        $payment = $this->pay();
        $this->pay('other', '20.00');
        $this->screen()->assertSee('Elimina pagamento')->call('deletePayment', $payment->id)
            ->assertHasNoErrors()->assertDispatched('rental-flags-updated')->assertSee('20,00 €')->assertDontSee('70,00 €');
        $this->assertSoftDeleted($payment);
        $this->assertSame(1, $this->rental->charges()->count());
        $this->assertFalse($this->rental->fresh()->has_base_payment);
        $this->assertEquals(0, $this->rental->fresh()->base_paid_total);
        $this->postJson(route('rentals.close', $this->rental))->assertUnprocessable()->assertJsonPath('code', 'base_payment_missing');
    }

    public function test_deleting_a_closed_rental_payment_keeps_its_historical_percentage_and_closure(): void
    {
        $this->pay();
        $extra = $this->pay('other');
        $original = $extra->getAttributes();
        $this->postJson(route('rentals.close', $this->rental))->assertOk();
        $closed = $this->rental->fresh();
        DB::table('organization_fees')->where('organization_id', $closed->organization_id)->update(['percent' => 70]);
        $this->screen()->call('deletePayment', $extra->id)->assertHasNoErrors();
        $current = $closed->fresh();
        $this->assertEquals(7.50, $current->admin_fee_amount);
        $this->assertEquals(15, $current->admin_fee_percent);
        $this->assertSame('closed', $current->status);
        $this->assertEquals($closed->closed_at, $current->closed_at);
        $this->assertSame($closed->closed_by, $current->closed_by);
        $deleted = RentalCharge::withTrashed()->findOrFail($extra->id);
        foreach (['amount', 'kind', 'created_by', 'request_key', 'payment_method', 'payment_recorded_at'] as $field) {
            $this->assertSame($original[$field], $deleted->getAttributes()[$field]);
        }
        $this->assertDatabaseHas('activity_log', ['subject_type' => RentalCharge::class, 'subject_id' => $extra->id,
            'event' => 'payment_deleted', 'causer_id' => $this->operator->id]);
    }

    public function test_repeating_deletion_is_idempotent_and_does_not_restore_a_replayed_payment(): void
    {
        $key = (string) Str::uuid();
        $payment = $this->pay(key: $key);
        $component = $this->screen();
        $component->call('deletePayment', $payment->id)->assertHasNoErrors();
        $component->call('deletePayment', $payment->id)->assertHasNoErrors();
        $this->assertSame(1, DB::table('activity_log')->where('event', 'payment_deleted')->where('subject_id', $payment->id)->count());
        $this->postJson(route('rentals.record_payment', $this->rental), [
            'kind' => 'base', 'amount' => '50.00', 'payment_method' => 'cash', 'request_key' => $key,
        ])->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $this->assertSame(0, $this->rental->charges()->count());
        $this->assertSame(1, $this->rental->charges()->withTrashed()->count());
    }

    public function test_a_view_only_operator_cannot_delete_payments(): void
    {
        $payment = $this->pay();
        $viewer = User::factory()->create(['organization_id' => $this->rental->organization_id, 'email' => 'viewer@example.test']);
        $viewer->givePermissionTo('rentals.view');
        $this->actingAs($viewer);
        $this->screen()->assertDontSee('Elimina pagamento')->call('deletePayment', $payment->id)->assertForbidden();
        $this->assertNotSoftDeleted($payment);
    }

    public function test_a_payment_from_another_rental_cannot_be_deleted_by_changing_its_id(): void
    {
        $other = $this->rental->replicate();
        $other->number_id = 165;
        $other->save();
        $payment = $other->charges()->create(['kind' => 'base', 'amount' => 50, 'payment_recorded' => true]);
        try {
            $this->screen()->call('deletePayment', $payment->id);
            $this->fail('Un identificativo di un altro noleggio deve essere rifiutato.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            $this->assertSame(RentalCharge::class, $exception->getModel());
            $this->assertNotSoftDeleted($payment);
        }
    }

    public function test_a_renter_cannot_delete_payments_for_another_organization(): void
    {
        $payment = $this->pay();
        $outsider = User::factory()->create(['email' => 'outsider@example.test']);
        $outsider->givePermissionTo(['rentals.view', 'rentals.update']);
        $this->actingAs($outsider);
        $this->screen()->assertForbidden();
        try {
            app(RentalPaymentService::class)->delete($this->rental, $payment->id, $outsider);
            $this->fail('La cancellazione di un pagamento altrui deve essere vietata.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertNotSoftDeleted($payment);
        }
    }

    public function test_a_missing_historical_percentage_does_not_silently_erase_the_commission(): void
    {
        $payment = $this->pay();
        $this->postJson(route('rentals.close', $this->rental))->assertOk();
        $this->rental->refresh()->update(['admin_fee_percent' => null]);
        $this->screen()->call('deletePayment', $payment->id)->assertHasErrors('payment');
        $this->assertNotSoftDeleted($payment);
        $this->assertEquals(7.50, $this->rental->fresh()->admin_fee_amount);
    }

    public function test_closure_rechecks_payments_after_acquiring_the_rental_lock(): void
    {
        $payment = $this->pay();
        $realGuard = new CloseRentalGuard();
        $calls = 0;
        $guard = \Mockery::mock(CloseRentalGuard::class);
        $guard->shouldReceive('check')->twice()->andReturnUsing(function ($rental, $rules) use ($realGuard, $payment, &$calls) {
            $result = $realGuard->check($rental, $rules);
            if (++$calls === 1) app(RentalPaymentService::class)->delete($rental, $payment->id, $this->operator);
            return $result;
        });
        $this->app->instance(CloseRentalGuard::class, $guard);
        $this->postJson(route('rentals.close', $this->rental))->assertUnprocessable()->assertJsonPath('code', 'base_payment_missing');
        $this->assertSame('checked_in', $this->rental->fresh()->status);
    }
}
