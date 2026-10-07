<?php

namespace Tests\Feature;

use App\Livewire\Vehicles\CorrectMileage;
use App\Livewire\Vehicles\Show;
use App\Livewire\Vehicles\Table;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMileageLog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VehicleMileageCorrectionTest extends TestCase
{
    private User $admin;
    private User $renter;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('This test requires an isolated in-memory SQLite database.');
        }
        Schema::create('organizations', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('type'); $table->boolean('is_active')->default(true);
            $table->timestamps(); $table->softDeletes();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('email'); $table->string('password');
            $table->foreignId('organization_id')->nullable(); $table->boolean('is_active')->default(true);
            $table->timestamps(); $table->softDeletes();
        });
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id(); $table->foreignId('admin_organization_id'); $table->string('plate');
            $table->string('make'); $table->string('model'); $table->unsignedInteger('mileage_current')->nullable();
            $table->timestamps(); $table->softDeletes();
        });
        Schema::create('vehicle_assignments', function (Blueprint $table) {
            $table->id(); $table->foreignId('vehicle_id'); $table->foreignId('renter_org_id');
            $table->string('status'); $table->dateTime('start_at'); $table->dateTime('end_at')->nullable();
        });
        (require database_path('migrations/2025_09_25_201206_create_permission_tables.php'))->up();
        (require database_path('migrations/2025_10_03_193647_create_vehicle_mileage_logs_table.php'))->up();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        DB::table('organizations')->insert([
            ['id' => 1, 'name' => 'Admin di collaudo', 'type' => 'admin'],
            ['id' => 2, 'name' => 'Noleggiatore di collaudo', 'type' => 'renter'],
        ]);
        $this->admin = User::create(['name' => 'Admin di collaudo', 'email' => 'admin@example.test', 'password' => 'test-password', 'organization_id' => 1]);
        $this->admin->assignRole(Role::findOrCreate('admin', 'web'));
        $this->renter = User::create(['name' => 'Noleggiatore di collaudo', 'email' => 'renter@example.test', 'password' => 'test-password', 'organization_id' => 2]);
        $this->renter->assignRole(Role::findOrCreate('renter', 'web'));
        $this->renter->givePermissionTo(Permission::findOrCreate('vehicles.update_mileage', 'web'));
        $this->vehicle = Vehicle::create(['admin_organization_id' => 1, 'plate' => 'QA001', 'make' => 'Marca prova', 'model' => 'Modello prova', 'mileage_current' => 500000]);
        DB::table('vehicle_assignments')->insert([
            'vehicle_id' => $this->vehicle->id, 'renter_org_id' => 2, 'status' => 'active', 'start_at' => now()->subDay(),
        ]);
        $this->actingAs($this->admin);
    }

    private function correction()
    {
        return Livewire::test(CorrectMileage::class)->call('open', $this->vehicle->id);
    }

    public function test_admin_can_remove_an_extra_zero_and_the_change_is_audited(): void
    {
        $this->correction()->set('mileage', '50000')->call('save')->assertHasNoErrors()
            ->assertSet('showModal', false)->assertDispatched('vehicle-mileage-corrected');
        $this->assertSame(50000, $this->vehicle->fresh()->mileage_current);
        $this->assertDatabaseHas('vehicle_mileage_logs', [
            'vehicle_id' => $this->vehicle->id, 'mileage_old' => 500000, 'mileage_new' => 50000,
            'changed_by' => $this->admin->id, 'source' => 'manual', 'notes' => 'Correzione amministratore',
        ]);
        $this->assertDatabaseCount('vehicle_mileage_logs', 1);
        $this->assertSame('QA001', $this->vehicle->fresh()->plate);
    }

    public static function validCorrections(): array
    {
        return ['increase' => [100, 200], 'zero' => [100, 0], 'initial value' => [null, 50], 'null to zero' => [null, 0]];
    }

    #[DataProvider('validCorrections')]
    public function test_valid_values_preserve_the_actual_old_value(?int $old, int $new): void
    {
        $this->vehicle->update(['mileage_current' => $old]);
        $this->correction()->set('mileage', $new)->call('save')->assertHasNoErrors();
        $this->assertSame($new, $this->vehicle->fresh()->mileage_current);
        $this->assertSame($old, VehicleMileageLog::sole()->mileage_old);
    }

    public static function invalidValues(): array
    {
        return ['empty' => [''], 'null' => [null], 'negative' => [-1], 'fraction' => ['12.5'],
            'text' => ['12000x'], 'overflow' => ['4294967296'], 'array' => [[50000]]];
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_values_do_not_close_the_dialog_or_write_data($value): void
    {
        $this->correction()->set('mileage', $value)->call('save')->assertHasErrors('mileage')->assertSet('showModal', true);
        $this->assertSame(500000, $this->vehicle->fresh()->mileage_current);
        $this->assertDatabaseCount('vehicle_mileage_logs', 0);
    }

    public function test_unchanged_value_creates_no_duplicate_audit_entry(): void
    {
        $this->correction()->call('save')->assertHasNoErrors()->assertSet('showModal', false);
        $this->assertDatabaseCount('vehicle_mileage_logs', 0);
    }

    public function test_repeated_submission_does_not_write_twice(): void
    {
        $component = $this->correction()->set('mileage', 50000)->call('save');
        $component->call('save')->assertForbidden();
        $this->assertDatabaseCount('vehicle_mileage_logs', 1);
    }

    public function test_non_admin_cannot_mount_correction_even_with_a_matching_permission(): void
    {
        $this->renter->givePermissionTo(Permission::findOrCreate('correctMileage', 'web'));
        $this->actingAs($this->renter);
        Livewire::test(CorrectMileage::class)->assertForbidden();
        $this->assertDatabaseCount('vehicle_mileage_logs', 0);
    }

    public function test_permission_is_rechecked_when_saving_an_open_dialog(): void
    {
        $component = $this->correction()->set('mileage', 50000);
        $this->admin->removeRole('admin');
        $component->call('save')->assertForbidden();
        $this->assertSame(500000, $this->vehicle->fresh()->mileage_current);
        $this->assertDatabaseCount('vehicle_mileage_logs', 0);
    }

    public function test_concurrent_update_is_not_overwritten(): void
    {
        $component = $this->correction()->set('mileage', 50000);
        $this->vehicle->update(['mileage_current' => 500100]);
        $component->call('save')->assertHasErrors('mileage')->assertSee('è cambiato nel frattempo')->assertSet('showModal', true);
        $this->assertSame(500100, $this->vehicle->fresh()->mileage_current);
        $this->assertDatabaseCount('vehicle_mileage_logs', 0);
    }

    public function test_admin_can_correct_a_returned_vehicle_without_an_active_assignment(): void
    {
        DB::table('vehicle_assignments')->where('vehicle_id', $this->vehicle->id)->update([
            'status' => 'ended', 'end_at' => now()->subMinute(),
        ]);
        $assignment = DB::table('vehicle_assignments')->where('vehicle_id', $this->vehicle->id)->first();
        $this->correction()->set('mileage', 50000)->call('save')->assertHasNoErrors();
        $this->assertSame(50000, $this->vehicle->fresh()->mileage_current);
        $this->assertEquals($assignment, DB::table('vehicle_assignments')->where('vehicle_id', $this->vehicle->id)->first());
        $this->assertDatabaseCount('vehicle_mileage_logs', 1);
    }

    public function test_vehicle_archived_after_opening_cannot_be_changed(): void
    {
        $component = $this->correction()->set('mileage', 50000);
        $this->vehicle->delete();
        $component->call('save')->assertHasErrors('mileage');
        $this->assertSame(500000, Vehicle::withTrashed()->findOrFail($this->vehicle->id)->mileage_current);
        $this->assertDatabaseCount('vehicle_mileage_logs', 0);
    }

    public function test_archived_vehicle_cannot_be_opened_for_correction(): void
    {
        $this->vehicle->delete();
        Livewire::test(CorrectMileage::class)->call('open', $this->vehicle->id)->assertHasErrors('mileage')->assertSet('showModal', false);
    }

    public static function lockedFields(): array
    {
        return [['vehicleId', 999], ['currentMileage', 400000]];
    }

    #[DataProvider('lockedFields')]
    public function test_client_cannot_replace_the_vehicle_or_the_expected_mileage(string $field, int $value): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $this->correction()->set($field, $value);
    }

    public function test_audit_failure_rolls_back_the_vehicle_update(): void
    {
        $component = $this->correction()->set('mileage', 50000);
        VehicleMileageLog::creating(fn () => throw new \RuntimeException('Simulated audit failure'));
        try {
            $component->call('save');
            $this->fail('The simulated audit failure should stop the save.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated audit failure', $error->getMessage());
        } finally {
            VehicleMileageLog::flushEventListeners();
        }
        $this->assertSame(500000, $this->vehicle->fresh()->mileage_current);
        $this->assertDatabaseCount('vehicle_mileage_logs', 0);
    }

    public function test_cancelling_changes_nothing(): void
    {
        $this->correction()->set('mileage', 50000)->call('close')->assertSet('showModal', false)->assertSet('vehicleId', null);
        $this->assertSame(500000, $this->vehicle->fresh()->mileage_current);
        $this->assertDatabaseCount('vehicle_mileage_logs', 0);
    }

    public static function renterActions(): array
    {
        return [[MileageTableAction::class], [MileageShowAction::class]];
    }

    #[DataProvider('renterActions')]
    public function test_renter_retains_existing_increase_only_workflow(string $componentClass): void
    {
        $this->actingAs($this->renter);
        $component = Livewire::test($componentClass, ['vehicleId' => $this->vehicle->id]);
        $args = $componentClass === MileageTableAction::class ? [$this->vehicle->id] : [];
        $component->call('updateMileage', ...[...$args, 500100])->assertHasNoErrors();
        $this->assertSame(500100, $this->vehicle->fresh()->mileage_current);
        $component->call('updateMileage', ...[...$args, 50000])->assertHasErrors('mileage');
        $this->assertSame(500100, $this->vehicle->fresh()->mileage_current);
        $this->assertDatabaseCount('vehicle_mileage_logs', 1);
    }
}

class MileageTableAction extends Table
{
    public function render() { return '<div></div>'; }
}

class MileageShowAction extends Show
{
    public function render() { return '<div></div>'; }
}
