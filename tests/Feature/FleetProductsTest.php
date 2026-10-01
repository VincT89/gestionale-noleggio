<?php

namespace Tests\Feature;

use App\Models\{Vehicle, VehicleProduct};
use Illuminate\Support\Facades\DB;
use Tests\Support\PublicCarsTestCase;

class FleetProductsTest extends PublicCarsTestCase
{
    public function test_admin_creates_and_renames_a_product_with_a_server_assigned_immutable_id(): void
    {
        $admin = $this->publisher();
        $this->actingAs($admin)->post(route('fleet-products.store'), [
            'name' => '  Fiat   Panda ', 'description' => 'Gruppo dimostrativo', 'id' => 99999, 'created_by' => 99999,
        ])->assertRedirect();
        $product = VehicleProduct::sole();
        $id = $product->id;
        $this->assertNotSame(99999, $id);
        $this->assertSame($admin->id, (int) $product->created_by);
        $this->assertSame('Fiat Panda', $product->name);
        $this->get(route('fleet-products.edit', $product))->assertOk()->assertSee('ID prodotto:')->assertSee('Fiat Panda');
        $this->put(route('fleet-products.update', $product), ['id' => 99999, 'name' => 'Panda', 'description' => null])
            ->assertRedirect(route('fleet-products.edit', $product));
        $this->assertSame($id, $product->fresh()->id);
        $this->assertSame('Panda', $product->fresh()->name);
        $this->assertDatabaseCount('vehicle_products', 1);
    }

    public function test_product_names_are_unique_after_trimming_case_and_spacing_normalization(): void
    {
        $this->actingAs($this->publisher());
        VehicleProduct::create(['name' => 'Fiat Panda']);
        $other = VehicleProduct::create(['name' => 'Yaris']);
        $this->post(route('fleet-products.store'), ['name' => '  FIAT   panda '])->assertSessionHasErrors('name');
        $this->put(route('fleet-products.update', $other), ['name' => 'fiat panda'])->assertSessionHasErrors('name');
        $this->assertSame('Yaris', $other->fresh()->name);
        foreach ([' ', str_repeat('a', 121), ['array']] as $name) {
            $this->post(route('fleet-products.store'), ['name' => $name])->assertSessionHasErrors('name');
        }
        $this->assertDatabaseCount('vehicle_products', 2);
    }

    public function test_renters_cannot_manage_products_even_with_catalog_permissions(): void
    {
        $this->offer();
        $product = VehicleProduct::create(['name' => 'Panda']);
        $this->get(route('fleet-products.index'))->assertRedirect(route('login'));
        $this->actingAs($this->publisher(2, 'renter'));
        $this->get(route('fleet-products.index'))->assertForbidden();
        $this->get(route('fleet-products.edit', $product))->assertForbidden();
        $this->post(route('fleet-products.store'), ['name' => 'Iniettato'])->assertForbidden();
        $this->put(route('fleet-products.update', $product), ['name' => 'Iniettato'])->assertForbidden();
        $this->post(route('fleet-products.attach', $product), ['vehicle_ids' => [1]])->assertForbidden();
        $this->delete(route('fleet-products.detach', [$product, 1]))->assertForbidden();
        $this->get(route('public-deliveries.index'))->assertOk()->assertDontSee('Prodotti della flotta');
        $this->assertDatabaseCount('vehicle_products', 1);
        $this->assertNull(Vehicle::findOrFail(1)->vehicle_product_id);
    }

    public function test_product_management_is_not_exposed_on_the_public_domain(): void
    {
        config()->set('public_cars.domain', 'rent.example.test');
        $this->actingAs($this->publisher())->get('http://rent.example.test/prodotti-flotta')->assertNotFound();
        $this->post('http://rent.example.test/prodotti-flotta', ['name' => 'Iniettato'])->assertNotFound();
        $this->assertDatabaseCount('vehicle_products', 0);
    }

    public function test_admin_associates_multiple_existing_cars_without_changing_their_business_data(): void
    {
        $this->offer(); $this->offer(2);
        $product = VehicleProduct::create(['name' => 'Panda']);
        DB::table('rentals')->insert(['vehicle_id' => 1, 'status' => 'returned', 'planned_pickup_at' => '2026-08-01', 'planned_return_at' => '2026-08-03']);
        DB::table('vehicle_assignments')->insert(['vehicle_id' => 2, 'renter_org_id' => 2, 'start_at' => '2026-08-01', 'end_at' => '2026-08-03']);
        $before = $this->businessData();
        $this->actingAs($this->publisher())->post(route('fleet-products.attach', $product), ['vehicle_ids' => [1, 2]])->assertRedirect();
        $this->assertSame([1, 2], $product->vehicles()->orderBy('id')->pluck('id')->all());
        $this->post(route('fleet-products.attach', $product), ['vehicle_ids' => [1, 2]])->assertRedirect();
        $this->assertSame($before, $this->businessData());
        $this->assertDatabaseCount('vehicles', 2);
        $this->assertSame($product->id, Vehicle::findOrFail(1)->product->id);
    }

    public function test_association_is_additive_and_does_not_detach_members_on_other_pages(): void
    {
        $product = VehicleProduct::create(['name' => 'Panda']);
        for ($i = 1; $i <= 27; $i++) $this->offer($i, vehicle: ['vehicle_product_id' => $i <= 26 ? $product->id : null]);
        $this->actingAs($this->publisher())->get(route('fleet-products.edit', [$product, 'members_q' => 'TESTPLATE1', 'q' => 'TESTPLATE27']))
            ->assertOk()->assertViewHas('candidates', fn ($rows) => $rows->pluck('id')->all() === [27]);
        $this->post(route('fleet-products.attach', $product), ['vehicle_ids' => [27]])->assertRedirect();
        $this->assertSame(27, $product->vehicles()->count());
    }

    public function test_conflicting_or_missing_selection_is_rejected_atomically(): void
    {
        $a = VehicleProduct::create(['name' => 'Panda']);
        $b = VehicleProduct::create(['name' => 'Altro prodotto']);
        $this->offer(1, vehicle: ['vehicle_product_id' => $a->id]);
        $this->offer(2); $this->offer(3, vehicle: ['deleted_at' => now()]);
        $this->actingAs($this->publisher());
        foreach ([[1, 2], [2, 999999], [2, 3], [2, 2], []] as $ids) {
            $this->post(route('fleet-products.attach', $b), ['vehicle_ids' => $ids])->assertSessionHasErrors();
            $this->assertSame(0, $b->vehicles()->count());
            $this->assertNull(Vehicle::findOrFail(2)->vehicle_product_id);
        }
        $this->assertSame($a->id, (int) Vehicle::findOrFail(1)->vehicle_product_id);
    }

    public function test_detaching_removes_only_the_selected_association_and_allows_reassignment(): void
    {
        $a = VehicleProduct::create(['name' => 'Panda']);
        $b = VehicleProduct::create(['name' => 'Panda automatica']);
        $this->offer(1, vehicle: ['vehicle_product_id' => $a->id]);
        $this->offer(2, vehicle: ['vehicle_product_id' => $a->id]);
        $before = $this->businessData();
        $this->actingAs($this->publisher())->delete(route('fleet-products.detach', [$b, 1]))->assertNotFound();
        $this->delete(route('fleet-products.detach', [$a, 1]))->assertRedirect();
        $this->assertNull(Vehicle::findOrFail(1)->vehicle_product_id);
        $this->assertSame([2], $a->vehicles()->pluck('id')->all());
        $this->assertSame($before, $this->businessData());
        $this->post(route('fleet-products.attach', $b), ['vehicle_ids' => [1]])->assertRedirect();
        $this->assertSame([1], $b->vehicles()->pluck('id')->all());
    }

    public function test_archived_members_can_be_unlinked_without_restoring_or_deleting_the_car(): void
    {
        $product = VehicleProduct::create(['name' => 'Panda']);
        $this->offer(vehicle: ['vehicle_product_id' => $product->id, 'deleted_at' => now()]);
        $this->actingAs($this->publisher())->get(route('fleet-products.edit', $product))->assertOk()->assertSee('Auto archiviata');
        $this->delete(route('fleet-products.detach', [$product, 1]))->assertRedirect();
        $this->assertNotNull(Vehicle::withTrashed()->findOrFail(1)->deleted_at);
        $this->assertNull(Vehicle::withTrashed()->findOrFail(1)->vehicle_product_id);
    }

    public function test_names_are_escaped_and_existing_cars_are_not_automatically_grouped(): void
    {
        $this->offer(1, vehicle: ['make' => 'Fiat', 'model' => 'Panda']);
        $this->offer(2, vehicle: ['make' => 'Fiat', 'model' => 'Panda']);
        $product = VehicleProduct::create(['name' => '<script>alert(1)</script>']);
        $this->actingAs($this->publisher())->get(route('fleet-products.index'))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $this->assertSame(0, $product->vehicles()->count());
        $vehicle = Vehicle::findOrFail(1);
        $this->assertFalse($vehicle->isFillable('vehicle_product_id'));
    }

    private function businessData(): array
    {
        $result = [];
        foreach (['vehicle_pricelists', 'public_rental_offers', 'rentals', 'vehicle_assignments'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        $result['vehicles'] = DB::table('vehicles')->orderBy('id')->get(['id', 'plate', 'make', 'model', 'admin_organization_id', 'is_active', 'deleted_at'])
            ->map(fn ($row) => (array) $row)->all();
        return $result;
    }
}
