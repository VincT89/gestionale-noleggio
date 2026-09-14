<?php

namespace Tests\Feature;

use App\Livewire\Customers\{Show as CustomerShow, Table as CustomerTable};
use App\Livewire\Rentals\Show;
use App\Models\{CargosLuogo, Customer, Organization, Rental, RentalContractSnapshot, User, Vehicle};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http, Mail, Queue};
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\{Permission, Role};
use Tests\TestCase;

class RentalCustomerEditingTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;
    private Rental $rental;
    private Customer $customer;
    private Customer $otherCustomer;

    protected function beforeRefreshingDatabase(): void
    {
        $database = (string) getenv('R4_QA_DATABASE');
        if (config('database.default') !== 'mysql' || $database === '') {
            $this->markTestSkipped('Usare il collaudo MySQL isolato.');
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
        Queue::fake();
        $owner = Organization::factory()->admin()->create();
        $renter = Organization::factory()->renter()->create();
        $this->operator = User::factory()->create(['organization_id' => $renter->id]);
        $this->operator->assignRole(Role::findOrCreate('renter', 'web'));
        foreach (['rentals.viewAny', 'rentals.view', 'rentals.update', 'customers.viewAny', 'customers.view', 'customers.update'] as $permission) {
            $this->operator->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->customer = Customer::create([
            'organization_id' => $renter->id, 'first_name' => 'Cliente', 'last_name' => 'Prova',
            'name' => 'Cliente Prova', 'email' => 'cliente@example.test', 'phone' => '0000000000',
            'doc_id_type' => 'id', 'doc_id_number' => 'DOCUMENTO-PROVA',
            'driver_license_number' => 'PATENTE-PROVA', 'address_line' => 'Indirizzo dimostrativo',
        ]);
        $this->otherCustomer = Customer::create([
            'organization_id' => $renter->id, 'first_name' => 'Altro', 'last_name' => 'Cliente',
            'name' => 'Altro Cliente', 'email' => 'altro@example.test', 'phone' => '0000000001',
        ]);
        $vehicle = Vehicle::factory()->create(['admin_organization_id' => $owner->id, 'default_pickup_location_id' => null]);
        $this->rental = Rental::create([
            'organization_id' => $renter->id, 'customer_id' => $this->customer->id, 'vehicle_id' => $vehicle->id,
            'number_id' => 1, 'amount' => 30, 'final_amount_override' => 50, 'status' => 'checked_in',
            'planned_pickup_at' => '2026-09-09 15:00:00', 'planned_return_at' => '2026-09-10 15:00:00',
        ]);
        $this->actingAs($this->operator);
    }

    private function screen()
    {
        return Livewire::test(Show::class, ['rental' => $this->rental->fresh()]);
    }

    public static function rentalStates(): array
    {
        return array_map(fn ($state) => [$state], ['draft', 'reserved', 'checked_out', 'in_use', 'checked_in', 'closed', 'cancelled', 'no_show']);
    }

    #[DataProvider('rentalStates')]
    public function test_renter_can_edit_only_the_linked_customer_in_every_rental_state(string $state): void
    {
        $this->rental->update(['status' => $state]);
        $rentalBefore = $this->rental->fresh()->getAttributes();
        $otherBefore = $this->otherCustomer->fresh()->getAttributes();
        $screen = $this->screen()->assertSee('Modifica cliente')->call('openCustomerModal', 'primary')
            ->assertSet('customerModalMode', 'edit')->assertSet('customer_id', $this->customer->id)
            ->assertDontSee('Cerca cliente esistente')->assertDontSee('Altro Cliente')
            ->set('customerForm.phone', '0000000099')->call('createOrUpdateCustomer')
            ->assertHasNoErrors();
        $this->assertFalse($screen->get('customerModalOpen'), json_encode($screen->effects['dispatches'] ?? []));
        $this->assertSame('0000000099', $this->customer->fresh()->phone);
        $this->assertSame($rentalBefore, $this->rental->fresh()->getAttributes());
        $this->assertSame($otherBefore, $this->otherCustomer->fresh()->getAttributes());
        $this->assertSame(2, Customer::count());
        $audit = Activity::where('event', 'customer_updated')->sole();
        $this->assertSame($this->operator->id, $audit->causer_id);
        $this->assertSame($this->rental->id, $audit->subject_id);
        $this->assertSame($this->customer->id, $audit->properties['customer_id']);
        $this->assertSame('0000000000', $audit->properties['old']['phone']);
        $this->assertSame('0000000099', $audit->properties['attributes']['phone']);
    }

    public function test_cancel_and_invalid_data_do_not_write_customer_changes(): void
    {
        $before = $this->customer->fresh()->getAttributes();
        $screen = $this->screen()->call('openCustomerModal')->set('customerForm.phone', '0000000099')
            ->call('closeCustomerModal')->assertSet('customerModalOpen', false);
        $this->assertSame($before, $this->customer->fresh()->getAttributes());
        $screen->call('openCustomerModal')->assertSet('customerForm.phone', '0000000000')
            ->set('customerForm.email', 'invalid-email')->call('createOrUpdateCustomer')
            ->assertHasErrors(['customerForm.email'])->assertSet('customerModalOpen', true);
        $this->assertSame($before, $this->customer->fresh()->getAttributes());
        $this->assertSame(0, Activity::where('event', 'customer_updated')->count());
    }

    public function test_editing_does_not_allow_search_or_replacing_the_customer(): void
    {
        $screen = $this->screen()->call('openCustomerModal')->set('customerQuery', 'Altro');
        $this->assertSame([], $screen->instance()->getCustomerSearchResultsProperty());
        $screen->call('selectCustomer', $this->otherCustomer->id)->assertSet('customer_id', $this->customer->id)
            ->set('customerForm.email', 'corretto@example.test')->call('createOrUpdateCustomer')->assertHasNoErrors();
        $this->assertSame($this->customer->id, $this->rental->fresh()->customer_id);
        $this->assertSame('altro@example.test', $this->otherCustomer->fresh()->email);
    }

    public static function lockedFields(): array
    {
        return [['customer_id', 9999], ['customerRole', 'second'], ['customerModalMode', 'create'], ['customerPopulated', false]];
    }

    #[DataProvider('lockedFields')]
    public function test_browser_cannot_change_the_identity_or_mode_of_the_customer_form(string $property, mixed $value): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $this->screen()->call('openCustomerModal')->set($property, $value);
    }

    public function test_changed_customer_association_rejects_a_stale_form(): void
    {
        $screen = $this->screen()->call('openCustomerModal')->set('customerForm.phone', '0000000099');
        $this->rental->update(['customer_id' => $this->otherCustomer->id]);
        $screen->call('createOrUpdateCustomer')->assertHasErrors(['customerForm.name']);
        $this->assertSame('0000000000', $this->customer->fresh()->phone);
        $this->assertSame('0000000001', $this->otherCustomer->fresh()->phone);
    }

    public function test_permissions_are_rechecked_when_saving(): void
    {
        $screen = $this->screen()->call('openCustomerModal')->set('customerForm.phone', '0000000099');
        $this->operator->revokePermissionTo('customers.update');
        $screen->call('createOrUpdateCustomer')->assertForbidden();
        $this->assertSame('0000000000', $this->customer->fresh()->phone);
    }

    public function test_customer_is_resolved_again_under_the_rental_lock(): void
    {
        $screen = $this->screen()->call('openCustomerModal')->set('customerForm.phone', '0000000099');
        $intercepted = false;
        $rentalId = $this->rental->id;
        $otherId = $this->otherCustomer->id;
        DB::connection()->beforeExecuting(function ($query) use (&$intercepted, $rentalId, $otherId) {
            if (!$intercepted && str_contains($query, 'rentals') && str_contains(strtolower($query), 'for update')) {
                $intercepted = true;
                DB::table('rentals')->where('id', $rentalId)->update(['customer_id' => $otherId]);
            }
        });
        $screen->call('createOrUpdateCustomer')->assertHasErrors(['customerForm.name']);
        $this->assertTrue($intercepted);
        $this->assertSame('0000000000', $this->customer->fresh()->phone);
        $this->assertSame('0000000001', $this->otherCustomer->fresh()->phone);
    }

    public function test_a_foreign_rental_cannot_be_opened(): void
    {
        $foreignOrg = Organization::factory()->renter()->create();
        $this->rental->update(['organization_id' => $foreignOrg->id]);
        $this->screen()->assertForbidden();
        $this->get(route('rentals.show', $this->rental))->assertForbidden();
    }

    public function test_no_update_permission_means_no_button_and_no_edit_action(): void
    {
        $this->operator->revokePermissionTo('customers.update');
        $this->screen()->assertDontSee('Modifica cliente')->call('openCustomerModal')->assertForbidden();
    }

    public function test_customer_archive_and_direct_customer_pages_remain_admin_only(): void
    {
        $this->get(route('customers.index'))->assertForbidden();
        $this->get(route('customers.show', $this->customer))->assertForbidden();
        Livewire::test(CustomerTable::class)->assertForbidden();
        Livewire::test(CustomerShow::class, ['customer' => $this->customer])->assertForbidden();
        $tiles = view('components.dashboard-tiles', ['badges' => []])->render();
        $this->assertStringNotContainsString(route('customers.index'), $tiles);
    }

    public function test_admin_keeps_access_to_customer_archive_and_contract_editing(): void
    {
        $admin = User::factory()->create(['organization_id' => Organization::factory()->admin()->create()->id]);
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        $this->actingAs($admin);
        $this->get(route('customers.index'))->assertOk();
        $this->get(route('customers.show', $this->customer))->assertOk();
        $this->screen()->call('openCustomerModal')->set('customerForm.phone', '0000000098')
            ->call('createOrUpdateCustomer')->assertHasNoErrors();
        $this->assertSame('0000000098', $this->customer->fresh()->phone);
    }

    public function test_existing_customer_shared_by_contracts_is_updated_without_changing_document_or_pricing_records(): void
    {
        $this->customer->update(['organization_id' => Organization::factory()->renter()->create()->id]);
        $otherRental = $this->rental->replicate();
        $otherRental->number_id = 2;
        $otherRental->save();
        $snapshot = RentalContractSnapshot::create(['rental_id' => $this->rental->id, 'pricing_snapshot' => ['tariff_total_cents' => 5000]]);
        $snapshotBefore = $snapshot->fresh()->getAttributes();
        $media = $this->rental->media()->create([
            'collection_name' => 'signatures', 'name' => 'Documento di prova esistente', 'file_name' => 'fixture.pdf',
            'mime_type' => 'application/pdf', 'disk' => 'local', 'size' => 0, 'manipulations' => [],
            'custom_properties' => ['current' => true], 'generated_conversions' => [], 'responsive_images' => [],
        ]);
        $mediaBefore = $media->fresh()->getAttributes();
        $this->screen()->call('openCustomerModal')->set('customerForm.phone', '0000000099')
            ->call('createOrUpdateCustomer')->assertHasNoErrors();
        $this->assertSame('0000000099', $otherRental->fresh()->customer->phone);
        $this->assertSame($mediaBefore, $media->fresh()->getAttributes());
        $this->assertSame($snapshotBefore, $snapshot->fresh()->getAttributes());
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_existing_customer_association_flow_is_preserved_for_a_draft_without_customer(): void
    {
        $this->rental->update(['status' => 'draft', 'customer_id' => null]);
        $place = CargosLuogo::create(['code' => 999999, 'name' => 'Luogo dimostrativo', 'province_code' => 'XX', 'country_code' => 'IT']);
        $this->screen()->call('openCustomerModal')->assertSet('customerModalMode', 'create')
            ->assertSee('Cerca cliente esistente')->call('selectCustomer', $this->customer->id)
            ->set('customerForm.police_place_code', $place->code)->call('createOrUpdateCustomer')->assertHasNoErrors();
        $this->assertSame($this->customer->id, $this->rental->fresh()->customer_id);
        $this->assertSame(2, Customer::count());
    }

    public function test_second_driver_rules_are_not_extended_after_departure(): void
    {
        $this->rental->update(['second_driver_id' => $this->otherCustomer->id]);
        $this->screen()->call('openCustomerModal', 'second')->assertSet('customerModalOpen', false);
        $this->rental->update(['status' => 'reserved']);
        $this->screen()->call('openCustomerModal', 'second')->assertSet('customerModalMode', 'edit')
            ->set('customerForm.phone', '0000000099')->call('createOrUpdateCustomer')->assertHasNoErrors();
        $this->assertSame('0000000099', $this->otherCustomer->fresh()->phone);
        $this->assertSame('0000000000', $this->customer->fresh()->phone);
    }

    public function test_contact_correction_preserves_legacy_fields_and_readonly_license_type(): void
    {
        $this->customer->update(['birth_place' => 'Luogo legacy dimostrativo', 'citizenship' => 'Cittadinanza legacy dimostrativa']);
        $before = $this->customer->fresh()->getAttributes();
        $this->screen()->call('openCustomerModal')->set('customerForm.phone', '0000000099')
            ->set('customerForm.driver_license_document_type_code', 'ALTERATO')
            ->call('createOrUpdateCustomer')->assertHasNoErrors();
        $after = $this->customer->fresh()->getAttributes();
        $this->assertSame('0000000099', $after['phone']);
        unset($before['phone'], $before['updated_at'], $after['phone'], $after['updated_at']);
        $this->assertSame($before, $after);
    }

    public function test_corrected_document_type_updates_both_cargos_and_internal_fields(): void
    {
        DB::table('cargos_document_types')->insert(['code' => 'PASOR', 'label' => 'Passaporto di prova']);
        $this->screen()->call('openCustomerModal')->set('customerForm.identity_document_type_code', 'PASOR')
            ->call('createOrUpdateCustomer')->assertHasNoErrors();
        $this->assertSame('PASOR', $this->customer->fresh()->identity_document_type_code);
        $this->assertSame('passport', $this->customer->fresh()->doc_id_type);
    }
}
