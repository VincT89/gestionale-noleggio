<?php

namespace Tests\Feature;

use App\Domain\Fees\AdminFeeResolver;
use App\Livewire\Rentals\Show;
use App\Models\{Organization, OrganizationFee, Rental, RentalCharge, RentalChecklist, ReportPreset, User, Vehicle, VehicleAssignment};
use App\Services\Reports\ReportRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB, Http, Mail};
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RentalPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;
    private Rental $rental;

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
        $this->travelTo(now()->setDate(2026, 8, 21)->setTime(18, 0));
        $owner = Organization::factory()->admin()->create(['name' => 'Proprietario di collaudo', 'email' => 'owner@example.test']);
        $renter = Organization::factory()->renter()->create(['name' => 'Noleggiatore di collaudo', 'email' => 'renter@example.test']);
        $this->operator = User::factory()->create(['organization_id' => $renter->id, 'name' => 'Operatore di collaudo', 'email' => 'operator@example.test']);
        foreach (['rentals.viewAny', 'rentals.view', 'rentals.update', 'rentals.close'] as $name) {
            $this->operator->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $vehicle = Vehicle::factory()->create(['admin_organization_id' => $owner->id, 'default_pickup_location_id' => null]);
        $assignment = VehicleAssignment::create(['vehicle_id' => $vehicle->id, 'renter_org_id' => $renter->id,
            'start_at' => '2026-08-01 00:00:00', 'end_at' => null, 'status' => 'active']);
        $this->rental = Rental::create(['organization_id' => $renter->id, 'vehicle_id' => $vehicle->id,
            'assignment_id' => $assignment->id, 'number_id' => 1, 'amount' => 520,
            'planned_pickup_at' => '2026-08-13 10:00:00', 'planned_return_at' => '2026-08-21 10:00:00',
            'actual_return_at' => '2026-08-21 10:00:00', 'status' => 'checked_in']);
        RentalChecklist::create(['rental_id' => $this->rental->id, 'type' => 'return', 'mileage' => 1000, 'fuel_percent' => 100]);
        OrganizationFee::create(['organization_id' => $renter->id, 'percent' => 15, 'effective_from' => '2026-08-01']);
        $this->actingAs($this->operator);
    }

    private function pay(string $kind, string $amount, array $extra = [])
    {
        return $this->postJson(route('rentals.record_payment', $this->rental), $extra + [
            'kind' => $kind, 'amount' => $amount, 'payment_method' => 'cash', 'request_key' => (string) Str::uuid(),
        ]);
    }

    private function report(string $type, array $metrics): object
    {
        return app(ReportRunner::class)->run(new ReportPreset([
            'report_type' => $type, 'metrics' => $metrics, 'dimensions' => ['rental'],
            'filters' => ['date_from' => '2026-08-01', 'date_to' => '2026-08-31', 'organization_id' => $this->rental->organization_id],
        ]))->sole();
    }

    public static function paymentKindsAndMethods(): array
    {
        $cases = [];
        foreach (['base' => true, 'acconto' => true, 'distance_overage' => true,
            'base+distance_overage' => true, 'surcharge' => true, 'other' => true,
            'damage' => false, 'fine' => false] as $kind => $included) {
            foreach (['cash', 'pos', 'bank_transfer', 'other'] as $method) {
                $cases[$kind.' / '.$method] = [$kind, $method, $included];
            }
        }
        return $cases;
    }

    #[DataProvider('paymentKindsAndMethods')]
    public function test_commission_rules_for_each_payment_type_and_method(string $kind, string $method, bool $included): void
    {
        $this->pay($kind, '10', ['payment_method' => $method, 'is_commissionable' => !$included])->assertOk();
        $this->assertSame($included, $this->rental->charges()->sole()->is_commissionable);
        $this->assertEquals($included ? 1.5 : 0, app(AdminFeeResolver::class)->calculateForRental($this->rental)['amount']);
    }

    public function test_unassigned_vehicle_payments_remain_outside_admin_commissions(): void
    {
        $this->rental->update(['assignment_id' => null]);
        foreach (\App\Models\RentalCharge::COMMISSIONABLE_KINDS as $kind) {
            $this->pay($kind, '10')->assertOk();
        }
        $this->assertSame(0, $this->rental->charges()->commissionable()->count());
        $this->assertEquals(0, app(AdminFeeResolver::class)->calculateForRental($this->rental)['amount']);
    }

    public function test_extra_payment_backfill_preserves_payment_data_and_historical_percentage_and_is_idempotent(): void
    {
        $payment = $this->legacyBalance();
        $old = $this->rental->fresh();
        $attributes = $payment->getAttributes();
        DB::table('organization_fees')->where('organization_id', $old->organization_id)->update(['percent' => 40]);
        $this->artisan('rentals:include-extra-commissions', ['--rental' => $old->id])->assertSuccessful();
        $this->assertFalse($payment->fresh()->is_commissionable);
        $this->assertEquals(68.25, $old->fresh()->admin_fee_amount);
        $this->artisan('rentals:include-extra-commissions', ['--rental' => $old->id, '--apply' => true])->assertSuccessful();
        $this->artisan('rentals:include-extra-commissions', ['--rental' => $old->id, '--apply' => true])->assertSuccessful();
        $this->assertTrue($payment->fresh()->is_commissionable);
        foreach (['kind', 'amount', 'payment_method', 'payment_recorded_at', 'payment_reference', 'created_by'] as $field) {
            $this->assertSame($attributes[$field], $payment->fresh()->getAttributes()[$field]);
        }
        $this->assertEquals(78, $old->fresh()->admin_fee_amount);
        $this->assertEquals(15, $old->fresh()->admin_fee_percent);
        $this->assertEquals($old->closed_at, $old->fresh()->closed_at);
        $this->assertSame($old->closed_by, $old->fresh()->closed_by);
        $this->assertSame(2, $old->charges()->count());
    }

    public function test_two_base_payments_are_counted_when_the_rental_closes(): void
    {
        $this->pay('base', '455.00')->assertOk()->assertJsonPath('base_paid_total', 455);
        $this->pay('base', '65.00')->assertOk()->assertJsonPath('base_paid_total', 520);
        $this->postJson(route('rentals.close', $this->rental))->assertOk()->assertJsonPath('admin_fee.amount', '78.00');
        $this->assertSame(2, $this->rental->charges()->paid()->count());
        $this->assertEquals(78, $this->report('commissions_by_closure', ['sum_admin_fee_amount'])->sum_admin_fee_amount);
        $this->assertEquals(520, $this->report('cash_by_closure_month', ['sum_paid_total'])->sum_paid_total);
    }

    public function test_multiple_deposits_and_the_balance_are_allowed(): void
    {
        $this->pay('acconto', '200')->assertOk();
        $this->pay('acconto', '255')->assertOk()->assertJsonPath('acconto_paid_total', 455);
        $this->pay('base', '65')->assertOk()->assertJsonPath('base_paid_total', 520);
        $this->assertEquals(78, app(AdminFeeResolver::class)->calculateForRental($this->rental)['amount']);
    }

    public function test_replaying_the_same_request_does_not_create_another_payment(): void
    {
        $key = (string) Str::uuid();
        $first = $this->pay('base', '455', ['request_key' => $key])->assertOk();
        $this->pay('base', '455', ['request_key' => $key])->assertOk()->assertJsonPath('payment_id', $first->json('payment_id'));
        $this->assertSame(1, $this->rental->charges()->count());
    }

    public function test_a_reused_key_with_different_payment_data_is_rejected(): void
    {
        $key = (string) Str::uuid();
        $this->pay('base', '455', ['request_key' => $key])->assertOk();
        $this->pay('base', '65', ['request_key' => $key])->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $this->assertSame(1, $this->rental->charges()->count());
    }

    public function test_references_and_notes_are_saved(): void
    {
        $this->pay('base', '65', ['payment_reference' => 'TEST-RECEIPT-2', 'payment_notes' => 'Saldo di collaudo'])->assertOk();
        $this->assertDatabaseHas('rental_charges', ['rental_id' => $this->rental->id,
            'payment_reference' => 'TEST-RECEIPT-2', 'description' => 'Saldo di collaudo', 'created_by' => $this->operator->id]);
    }

    public function test_a_payment_after_closure_updates_the_fee_preserving_its_percentage_and_dates(): void
    {
        $this->pay('base', '455')->assertOk();
        $this->postJson(route('rentals.close', $this->rental))->assertOk();
        $closed = $this->rental->fresh();
        DB::table('organization_fees')->where('organization_id', $closed->organization_id)->update(['percent' => 40]);
        Cache::flush();
        $this->pay('base', '65')->assertOk();
        $updated = $closed->fresh();
        $this->assertEquals(78, $updated->admin_fee_amount);
        $this->assertEquals(15, $updated->admin_fee_percent);
        $this->assertEquals($closed->closed_at, $updated->closed_at);
        $this->assertSame($closed->closed_by, $updated->closed_by);
        $this->assertEquals(78, app(AdminFeeResolver::class)->calculateForRental($updated)['amount']);
    }

    public function test_unpaid_deleted_and_non_commissionable_charges_are_not_commissioned(): void
    {
        $this->pay('base', '455')->assertOk();
        $this->pay('base', '65')->assertOk();
        $this->pay('damage', '90')->assertOk();
        $this->rental->charges()->create(['kind' => 'base', 'amount' => 800, 'is_commissionable' => true, 'payment_recorded' => false]);
        $deleted = $this->rental->charges()->create(['kind' => 'base', 'amount' => 700, 'is_commissionable' => true, 'payment_recorded' => true, 'payment_recorded_at' => now()]);
        $deleted->delete();
        $this->assertEquals(78, app(AdminFeeResolver::class)->calculateForRental($this->rental)['amount']);
        $cash = $this->report('cash_by_payment_date', ['sum_paid_total', 'sum_paid_commissionable', 'count_payments']);
        $this->assertEquals(610, $cash->sum_paid_total);
        $this->assertEquals(520, $cash->sum_paid_commissionable);
        $this->assertEquals(3, $cash->count_payments);
    }

    public function test_an_archived_fee_is_not_used_for_a_new_closure(): void
    {
        OrganizationFee::create(['organization_id' => $this->rental->organization_id, 'percent' => 99,
            'effective_from' => '2026-08-10'])->delete();
        $this->pay('base', '520')->assertOk();
        $this->assertEquals(78, app(AdminFeeResolver::class)->calculateForRental($this->rental)['amount']);
    }

    public function test_an_additional_payment_for_two_extension_days_enters_the_commission_base(): void
    {
        $this->rental->update(['amount' => 90, 'planned_return_at' => '2026-08-16 10:00:00']);
        $this->pay('base', '90')->assertOk();
        $this->pay('base', '60', ['payment_notes' => 'Due giorni aggiuntivi, dati di collaudo'])->assertOk();
        $this->assertEquals(150, app(AdminFeeResolver::class)->calculateForRental($this->rental)['commissionable_total']);
        $this->assertEquals(22.5, app(AdminFeeResolver::class)->calculateForRental($this->rental)['amount']);
        $this->assertEquals(90, $this->rental->fresh()->amount);
    }

    public function test_the_history_shows_both_payments_and_their_commission_classification(): void
    {
        $this->pay('base', '455')->assertOk();
        $this->pay('other', '65', ['payment_reference' => 'TEST-REFERENCE'])->assertOk();
        $this->rental->charges()->where('kind', 'other')->update(['is_commissionable' => false]);
        Livewire::test(Show::class, ['rental' => $this->rental])->assertSee('Storico pagamenti')
            ->assertSee('455,00 €')->assertSee('65,00 €')->assertSee('520,00 €')
            ->assertSee('TEST-REFERENCE')->assertSee('Escluso dalla base commissioni');
    }

    public function test_the_history_refreshes_after_a_new_payment(): void
    {
        $component = Livewire::test(Show::class, ['rental' => $this->rental])->assertSee('Nessun pagamento registrato.');
        $this->pay('base', '455')->assertOk();
        $component->dispatch('rental-payment-recorded', rentalId: $this->rental->id)->assertSee('455,00 €');
    }

    public function test_the_contract_header_and_cargos_controls_refresh_after_closure(): void
    {
        $this->pay('base', '520')->assertOk();
        $component = Livewire::test(Show::class, ['rental' => $this->rental]);
        $this->postJson(route('rentals.close', $this->rental))->assertOk();
        $component->dispatch('rental-state-updated', rentalId: $this->rental->id)
            ->assertSee('Chiuso')->assertSee('Non disponibile su noleggi');
    }

    public function test_a_renter_cannot_register_payments_or_view_history_for_another_renter(): void
    {
        $other = User::factory()->create(['email' => 'other@example.test']);
        $other->givePermissionTo(['rentals.update', 'rentals.view']);
        $this->actingAs($other);
        $this->pay('base', '65')->assertForbidden();
        $this->get(route('rentals.show', $this->rental))->assertForbidden();
        Livewire::test(Show::class, ['rental' => $this->rental])->assertForbidden();
        $this->assertSame(0, $this->rental->charges()->count());
    }

    public function test_overage_payments_are_scoped_to_the_rental_and_must_be_paid(): void
    {
        $other = $this->rental->replicate();
        $other->number_id = 2;
        $other->save();
        $other->charges()->create(['kind' => 'base+distance_overage', 'amount' => 99, 'payment_recorded' => true]);
        $this->rental->charges()->create(['kind' => 'distance_overage', 'amount' => 50, 'payment_recorded' => false]);
        $this->assertFalse($this->rental->has_distance_overage_payment);
        $this->assertFalse($this->rental->has_combined_payment);
    }

    public function test_invalid_amounts_and_payment_methods_are_rejected_before_writing(): void
    {
        foreach (['0', '-5', '65.001', '10000000000'] as $amount) {
            $this->pay('base', $amount)->assertUnprocessable()->assertJsonValidationErrors('amount');
        }
        $this->pay('base', '65', ['payment_method' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->assertSame(0, $this->rental->charges()->count());
    }

    public function test_both_report_screens_use_the_percentage_saved_at_closure(): void
    {
        $this->pay('base', '455')->assertOk();
        $this->postJson(route('rentals.close', $this->rental))->assertOk();
        $this->pay('base', '65')->assertOk();
        DB::table('organization_fees')->where('organization_id', $this->rental->organization_id)->update(['percent' => 40]);
        Cache::flush();
        $preset = new ReportPreset(['dimensions' => ['rental'], 'filters' => [
            'date_from' => '2026-08-01', 'date_to' => '2026-08-31', 'organization_id' => $this->rental->organization_id,
        ]]);
        foreach ([\App\Livewire\Reports\RunSavedPreset::class, \App\Livewire\Reports\RunAdHocReport::class] as $class) {
            $method = new \ReflectionMethod($class, 'buildResolvedCommissionableTotalsByGroup');
            $totals = collect($method->invoke(new $class, $preset, app(AdminFeeResolver::class)))->sole();
            $this->assertEquals(520, $totals['resolved_commissionable_total']);
            $this->assertEquals(78, $totals['resolved_admin_fee_amount']);
        }
    }

    private function legacyBalance(): RentalCharge
    {
        $this->pay('base', '455')->assertOk();
        $this->pay('other', '65', ['payment_reference' => 'LEGACY-TEST'])->assertOk();
        $this->rental->charges()->where('kind', 'other')->update(['is_commissionable' => false]);
        $this->postJson(route('rentals.close', $this->rental))->assertOk();
        return $this->rental->charges()->where('kind', 'other')->sole();
    }

    private function correctionOptions(RentalCharge $payment): array
    {
        return ['rental' => $this->rental->id, 'payment' => $payment->id, '--expected-amount' => '65.00'];
    }

    public function test_legacy_balance_preview_does_not_change_the_payment_or_commission(): void
    {
        $payment = $this->legacyBalance();
        $this->artisan('rentals:correct-base-payment', $this->correctionOptions($payment))->assertSuccessful();
        $this->assertSame('other', $payment->fresh()->kind);
        $this->assertFalse($payment->fresh()->is_commissionable);
        $this->assertEquals(68.25, $this->rental->fresh()->admin_fee_amount);
    }

    public function test_explicit_legacy_balance_correction_preserves_the_original_payment_and_closure_details(): void
    {
        $payment = $this->legacyBalance();
        $closed = $this->rental->fresh();
        $this->artisan('rentals:correct-base-payment', $this->correctionOptions($payment) + ['--apply' => true])->assertSuccessful();
        $updated = $payment->fresh();
        $this->assertSame('base', $updated->kind);
        $this->assertTrue($updated->is_commissionable);
        foreach (['amount', 'created_by', 'payment_method', 'description', 'payment_reference', 'request_key'] as $field) {
            $this->assertSame($payment->{$field}, $updated->{$field});
        }
        $this->assertEquals($payment->payment_recorded_at, $updated->payment_recorded_at);
        $this->assertEquals($payment->created_at, $updated->created_at);
        $this->assertEquals($closed->closed_at, $closed->fresh()->closed_at);
        $this->assertEquals(78, $closed->fresh()->admin_fee_amount);
        $this->assertDatabaseHas('activity_log', ['subject_type' => RentalCharge::class, 'subject_id' => $payment->id,
            'event' => 'payment_reclassified']);
        $this->artisan('rentals:correct-base-payment', $this->correctionOptions($payment) + ['--apply' => true])->assertSuccessful();
        $this->assertSame(2, $this->rental->charges()->count());
        $this->assertEquals(78, $this->rental->fresh()->admin_fee_amount);
    }

    public function test_legacy_correction_rejects_a_different_amount_or_a_payment_from_another_rental(): void
    {
        $payment = $this->legacyBalance();
        $this->artisan('rentals:correct-base-payment', array_replace($this->correctionOptions($payment), [
            '--expected-amount' => '64.00', '--apply' => true,
        ]))->assertFailed();
        $other = $this->rental->replicate();
        $other->number_id = 2;
        $other->save();
        $this->artisan('rentals:correct-base-payment', array_replace($this->correctionOptions($payment), [
            'rental' => $other->id, '--apply' => true,
        ]))->assertFailed();
        $this->assertSame('other', $payment->fresh()->kind);
        $this->assertEquals(68.25, $this->rental->fresh()->admin_fee_amount);
    }
}
