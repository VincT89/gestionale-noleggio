<?php

namespace Tests\Feature;

use App\Livewire\Rentals\MileageCorrection;
use App\Models\{Rental, RentalChecklist, RentalCharge, RentalContractSnapshot, RentalMileageCorrection, User, Vehicle};
use App\Services\Rentals\RentalMileageCorrectionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\{Permission, Role};
use Tests\TestCase;

class RentalMileageCorrectionTest extends TestCase
{
    private User $admin;
    private User $renter;
    private Rental $rental;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('This test requires an isolated in-memory SQLite database.');
        }
        Schema::create('organizations', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('type'); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email'); $t->string('password');
            $t->foreignId('organization_id'); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('vehicles', function (Blueprint $t) {
            $t->id(); $t->foreignId('admin_organization_id'); $t->string('plate');
            $t->unsignedInteger('mileage_current')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('rentals', function (Blueprint $t) {
            $t->id(); $t->foreignId('organization_id'); $t->foreignId('vehicle_id'); $t->string('status');
            $t->unsignedInteger('mileage_out')->nullable(); $t->unsignedInteger('mileage_in')->nullable();
            $t->dateTime('actual_pickup_at')->nullable(); $t->dateTime('actual_return_at')->nullable();
            $t->dateTime('closed_at')->nullable(); $t->foreignId('closed_by')->nullable();
            $t->decimal('admin_fee_amount', 12, 2)->nullable(); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('rental_checklists', function (Blueprint $t) {
            $t->id(); $t->foreignId('rental_id'); $t->string('type'); $t->unsignedInteger('mileage');
            $t->integer('fuel_percent')->default(100); $t->json('checklist_json')->nullable();
            $t->boolean('signed_by_customer')->default(false); $t->boolean('signed_by_operator')->default(false);
            $t->dateTime('locked_at')->nullable(); $t->foreignId('locked_by_user_id')->nullable();
            $t->string('locked_reason')->nullable(); $t->unsignedBigInteger('signed_media_id')->nullable();
            $t->unsignedBigInteger('last_pdf_media_id')->nullable(); $t->string('last_pdf_payload_hash')->nullable();
            $t->timestamps(); $t->unique(['rental_id', 'type']);
        });
        Schema::create('rental_contract_snapshots', function (Blueprint $t) {
            $t->id(); $t->foreignId('rental_id'); $t->json('pricing_snapshot'); $t->timestamps();
        });
        Schema::create('rental_charges', function (Blueprint $t) {
            $t->id(); $t->foreignId('rental_id'); $t->string('kind'); $t->decimal('amount', 12, 2);
            $t->boolean('payment_recorded')->default(false); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('activity_log', function (Blueprint $t) {
            $t->id(); $t->string('log_name')->nullable(); $t->text('description');
            $t->nullableMorphs('subject'); $t->nullableMorphs('causer');
            $t->json('properties')->nullable(); $t->string('event')->nullable(); $t->uuid('batch_uuid')->nullable(); $t->timestamps();
        });
        (require database_path('migrations/2025_09_25_201206_create_permission_tables.php'))->up();
        (require database_path('migrations/2025_10_03_193647_create_vehicle_mileage_logs_table.php'))->up();
        (require database_path('migrations/2026_10_05_120000_create_rental_mileage_corrections_table.php'))->up();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        DB::table('organizations')->insert([
            ['id'=>1, 'name'=>'Admin di collaudo', 'type'=>'admin'],
            ['id'=>2, 'name'=>'Noleggiatore di collaudo', 'type'=>'renter'],
        ]);
        $this->admin = User::create(['name'=>'Admin di collaudo','email'=>'admin@example.test','password'=>'test','organization_id'=>1]);
        $this->admin->assignRole(Role::findOrCreate('admin', 'web'));
        $this->renter = User::create(['name'=>'Renter di collaudo','email'=>'renter@example.test','password'=>'test','organization_id'=>2]);
        $this->renter->assignRole(Role::findOrCreate('renter', 'web'));
        foreach (['rentals.view', 'rentals.update'] as $permission) {
            $this->renter->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->vehicle = Vehicle::create(['admin_organization_id'=>1,'plate'=>'QAKM001','mileage_current'=>505000]);
        $this->rental = Rental::create(['organization_id'=>2,'vehicle_id'=>$this->vehicle->id,'status'=>'checked_in',
            'mileage_out'=>50000,'mileage_in'=>505000,'actual_pickup_at'=>now()->subDays(2),'actual_return_at'=>now()]);
        foreach (['pickup'=>50000, 'return'=>505000] as $type=>$km) {
            RentalChecklist::create(['rental_id'=>$this->rental->id,'type'=>$type,'mileage'=>$km]);
        }
        RentalContractSnapshot::create(['rental_id'=>$this->rental->id,'pricing_snapshot'=>['km_daily_limit'=>100,'days'=>2,'extra_km_cents'=>50]]);
        $this->actingAs($this->admin);
    }

    private function correctionComponent()
    {
        return Livewire::test(MileageCorrection::class, ['rentalId'=>$this->rental->id])->call('open');
    }

    private function correct(array $changes = []): ?RentalMileageCorrection
    {
        $service = app(RentalMileageCorrectionService::class);
        $state = $service->context($this->rental->fresh());
        return $service->correct($this->rental->id, $changes + [
            'out'=>50000, 'in'=>50500, 'sync_vehicle'=>true, 'reason'=>'Zero inserito per errore',
        ], $state['version'], $this->admin);
    }

    public function test_return_correction_updates_all_readings_extra_km_and_audit(): void
    {
        $this->correctionComponent()->set('in', 50500)->set('reason', 'Zero inserito per errore')->call('save')
            ->assertHasNoErrors()->assertRedirect(route('rentals.show', ['rental'=>$this->rental->id,'tab'=>'return']));
        $this->assertSame(50500, $this->rental->fresh()->mileage_in);
        $this->assertSame(50500, $this->rental->fresh()->returnChecklist->mileage);
        $this->assertSame(50500, $this->vehicle->fresh()->mileage_current);
        $this->assertSame(300, $this->rental->fresh()->distance_overage_km);
        $record = RentalMileageCorrection::sole();
        $this->assertSame($this->admin->id, $record->corrected_by);
        $this->assertSame(505000, $record->properties['before']['in']);
        $this->assertSame(15000, $record->properties['after']['extra_cents']);
        $this->assertDatabaseHas('vehicle_mileage_logs',['mileage_old'=>505000,'mileage_new'=>50500,'changed_by'=>$this->admin->id]);
    }

    public function test_closed_rental_can_be_corrected_without_changing_payments_commission_or_signed_originals(): void
    {
        $this->rental->update(['status'=>'closed','closed_at'=>now(),'closed_by'=>$this->admin->id,'admin_fee_amount'=>'20.00']);
        $return = $this->rental->returnChecklist;
        $return->update(['locked_at'=>now(),'locked_by_user_id'=>$this->admin->id,'locked_reason'=>'signed',
            'signed_media_id'=>41,'last_pdf_media_id'=>42,'last_pdf_payload_hash'=>'original-hash','signed_by_customer'=>true,
            'signed_by_operator'=>true,'checklist_json'=>['keys'=>true]]);
        $original = $return->fresh()->getAttributes();
        $charge = RentalCharge::create(['rental_id'=>$this->rental->id,'kind'=>'base+distance_overage','amount'=>'1000.00','payment_recorded'=>true]);
        $payment = $charge->fresh()->getAttributes();
        $snapshot = $this->rental->contractSnapshot->getAttributes();
        $record = $this->correct();
        $this->assertSame('closed', $this->rental->fresh()->status);
        $this->assertEquals('20.00', $this->rental->fresh()->admin_fee_amount);
        $this->assertSame($payment, $charge->fresh()->getAttributes());
        $this->assertSame($snapshot, $this->rental->fresh()->contractSnapshot->getAttributes());
        foreach ($original as $key=>$value) if (!in_array($key,['mileage','updated_at'])) {
            $this->assertSame($value, $return->fresh()->getAttributes()[$key], $key.' changed unexpectedly');
        }
        $this->assertTrue($record->properties['payment_review']);
        $this->assertSame([$charge->id], $record->properties['recorded_payment_ids']);
    }

    public function test_pickup_and_return_can_both_be_reduced_together(): void
    {
        $this->rental->pickupChecklist->update(['mileage'=>500000]);
        $this->rental->update(['mileage_out'=>500000]);
        $this->correct();
        $this->assertSame(50000, $this->rental->fresh()->pickupChecklist->mileage);
        $this->assertSame(50500, $this->rental->fresh()->returnChecklist->mileage);
    }

    public function test_pickup_can_be_corrected_before_a_return_is_recorded(): void
    {
        $this->rental->checklists()->where('type', 'return')->delete();
        $this->rental->update(['mileage_in'=>null,'actual_return_at'=>null,'status'=>'in_use']);
        $this->vehicle->update(['mileage_current'=>50000]);
        $this->correct(['out'=>49900,'in'=>null]);
        $this->assertSame(49900, $this->vehicle->fresh()->mileage_current);
        $this->assertNull($this->rental->fresh()->mileage_in);
        $this->assertDatabaseCount('rental_checklists',1);
    }

    public function test_legacy_rental_without_checklists_can_be_corrected(): void
    {
        $this->rental->checklists()->delete();
        $this->correct();
        $this->assertSame(50500,$this->rental->fresh()->mileage_in);
        $this->assertSame(300,$this->rental->fresh()->distance_overage_km);
        $this->assertDatabaseCount('rental_checklists',0);
    }

    public static function invalidValues(): array
    {
        return ['negative'=>['in',-1], 'fraction'=>['in','50500.2'], 'overflow'=>['in',2000001],
            'inverted'=>['in',49000], 'remove reading'=>['out',null], 'missing reason'=>['reason',''],
            'blank reason'=>['reason','   '], 'text'=>['out','km']];
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_input_writes_nothing(string $field, $value): void
    {
        $this->correctionComponent()->set('in',50500)->set('reason','Correzione errore')->set($field,$value)
            ->call('save')->assertHasErrors($field)->assertSet('showModal',true);
        $this->assertSame(505000,$this->rental->fresh()->mileage_in);
        $this->assertSame(505000,$this->vehicle->fresh()->mileage_current);
        $this->assertDatabaseCount('rental_mileage_corrections',0);
    }

    public function test_zero_and_increase_are_valid(): void
    {
        $this->correct(['out'=>0,'in'=>0]);
        $this->assertSame(0,$this->vehicle->fresh()->mileage_current);
        $this->correct(['out'=>100,'in'=>600]);
        $this->assertSame(600,$this->vehicle->fresh()->mileage_current);
        $this->assertDatabaseCount('rental_mileage_corrections',2);
    }

    public function test_newer_vehicle_reading_is_not_replaced_by_historical_rental_correction(): void
    {
        $this->vehicle->update(['mileage_current'=>510000]);
        $this->correctionComponent()->set('in',50500)->set('reason','Correzione errore')->set('syncVehicle',true)
            ->call('save')->assertHasErrors('sync_vehicle');
        $this->correct(['sync_vehicle'=>false]);
        $this->assertSame(510000,$this->vehicle->fresh()->mileage_current);
        $this->assertSame(50500,$this->rental->fresh()->mileage_in);
        $this->assertDatabaseCount('vehicle_mileage_logs',0);
    }

    public function test_later_legacy_rental_with_same_km_prevents_vehicle_sync(): void
    {
        Rental::create(['organization_id'=>2,'vehicle_id'=>$this->vehicle->id,'status'=>'in_use',
            'mileage_out'=>505000,'actual_pickup_at'=>now()->addDay()]);
        $this->correctionComponent()->assertSet('syncVehicle',false)->set('in',50500)->set('reason','Correzione errore')
            ->set('syncVehicle',true)->call('save')->assertHasErrors('sync_vehicle');
        $this->assertSame(505000,$this->vehicle->fresh()->mileage_current);
    }

    public function test_newer_checklist_also_prevents_sync_if_rental_was_created_earlier(): void
    {
        $other = Rental::create(['organization_id'=>2,'vehicle_id'=>$this->vehicle->id,'status'=>'in_use',
            'mileage_out'=>505000,'actual_pickup_at'=>now()->subDays(3)]);
        RentalChecklist::create(['rental_id'=>$other->id,'type'=>'pickup','mileage'=>505000]);
        $this->correctionComponent()->assertSet('syncVehicle',false);
    }

    public function test_concurrent_change_requires_reopening_the_dialog(): void
    {
        $component = $this->correctionComponent()->set('in',50500)->set('reason','Correzione errore');
        $this->rental->returnChecklist->update(['mileage'=>505100]);
        $component->call('save')->assertHasErrors('correction');
        $this->assertSame(505100,$this->rental->fresh()->returnChecklist->mileage);
        $this->assertDatabaseCount('rental_mileage_corrections',0);
    }

    public function test_non_admin_can_view_but_cannot_correct_even_with_update_permission(): void
    {
        $this->actingAs($this->renter);
        Livewire::test(MileageCorrection::class,['rentalId'=>$this->rental->id])
            ->assertDontSee('Correggi km del noleggio')->call('open')->assertForbidden();
        Livewire::test(MileageCorrection::class,['rentalId'=>$this->rental->id])
            ->set('showModal',true)->set('in',50500)->call('save')->assertForbidden();
        $this->assertDatabaseCount('rental_mileage_corrections',0);
    }

    public function test_access_to_other_organization_is_denied(): void
    {
        $this->rental->update(['organization_id'=>1]);
        $this->actingAs($this->renter);
        Livewire::test(MileageCorrection::class,['rentalId'=>$this->rental->id])->assertForbidden();
    }

    public function test_admin_role_is_rechecked_on_save(): void
    {
        $component = $this->correctionComponent()->set('in',50500)->set('reason','Correzione errore');
        $this->admin->removeRole('admin');
        $component->call('save')->assertForbidden();
        $this->assertDatabaseCount('rental_mileage_corrections',0);
    }

    public function test_original_values_cannot_be_replaced_by_client(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $this->correctionComponent()->set('original.version','forged');
    }

    public function test_no_change_and_cancel_do_not_create_records(): void
    {
        $this->assertNull($this->correct(['in'=>505000]));
        $this->correctionComponent()->set('in',50500)->call('close')->assertSet('showModal',false);
        $this->assertDatabaseCount('rental_mileage_corrections',0);
        $this->assertSame(505000,$this->vehicle->fresh()->mileage_current);
    }

    public function test_failure_to_record_correction_rolls_back_all_readings(): void
    {
        RentalMileageCorrection::creating(fn()=>throw new \RuntimeException('Simulated correction failure'));
        try {
            $this->correct();
            $this->fail('Expected correction failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated correction failure',$e->getMessage());
        } finally {
            RentalMileageCorrection::flushEventListeners();
        }
        $this->assertSame(505000,$this->rental->fresh()->mileage_in);
        $this->assertSame(505000,$this->rental->fresh()->returnChecklist->mileage);
        $this->assertSame(505000,$this->vehicle->fresh()->mileage_current);
        $this->assertDatabaseCount('vehicle_mileage_logs',0);
    }

    public function test_unlimited_mileage_does_not_generate_extra_charges(): void
    {
        $this->rental->contractSnapshot->update(['pricing_snapshot'=>['km_daily_limit'=>null,'extra_km_cents'=>50]]);
        $record = $this->correct();
        $this->assertSame(0,$record->properties['after']['extra_km']);
        $this->assertSame(0,$record->properties['after']['extra_cents']);
    }

    public function test_correction_is_still_available_after_general_activity_log_cleanup(): void
    {
        $this->correct();
        DB::table('activity_log')->delete();
        $this->assertDatabaseCount('rental_mileage_corrections',1);
        $this->assertSame(50500,RentalMileageCorrection::sole()->properties['after']['in']);
    }

    public function test_payment_review_warning_survives_later_corrections_until_recorded_payment_is_revised(): void
    {
        $charge = RentalCharge::create(['rental_id'=>$this->rental->id,'kind'=>'distance_overage','amount'=>'1000.00','payment_recorded'=>true]);
        $this->correct();
        $second = $this->correct(['out'=>49900,'in'=>50400]);
        $this->assertFalse($second->properties['payment_review']);
        Livewire::test(MileageCorrection::class,['rentalId'=>$this->rental->id])->assertSee('Esistono incassi già registrati');
        $charge->delete();
        Livewire::test(MileageCorrection::class,['rentalId'=>$this->rental->id])->assertDontSee('Esistono incassi già registrati');
    }

    public function test_download_is_scoped_to_the_authorized_rental(): void
    {
        $other = Rental::create(['organization_id'=>1,'vehicle_id'=>$this->vehicle->id,'status'=>'closed']);
        $correction = RentalMileageCorrection::create(['rental_id'=>$other->id,'corrected_by'=>$this->admin->id,'properties'=>[]]);
        $this->actingAs($this->renter);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test(MileageCorrection::class,['rentalId'=>$this->rental->id])->call('download',$correction->id);
    }

    public function test_amendment_can_be_downloaded_without_replacing_originals(): void
    {
        $correction = $this->correct();
        $this->actingAs($this->renter);
        Livewire::test(MileageCorrection::class,['rentalId'=>$this->rental->id])->call('download',$correction->id)
            ->assertFileDownloaded('rettifica-km-'.$this->rental->id.'-'.$correction->id.'.pdf');
    }
}
