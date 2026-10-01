<?php

namespace Tests\Feature;

use App\Models\{Location, PublicBooking, PublicDeliveryLocation, PublicPickupPlace, VehicleProduct};
use Illuminate\Support\Facades\DB;
use Tests\Support\PublicBookingTestCase;

class PublicProductSearchTest extends PublicBookingTestCase
{
    private function groupedOffer(int $id, VehicleProduct $product, int $daily, int $organization = 1, array $vehicle = [], array $price = [])
    {
        $offer = $this->offer($id, $organization, ['base_daily_cents' => $daily] + $price,
            $vehicle + ['vehicle_product_id' => $product->id, 'make' => 'Fiat', 'model' => 'Panda']);
        if ($organization !== 1) {
            DB::table('vehicle_assignments')->insert(['vehicle_id' => $id, 'renter_org_id' => $organization, 'start_at' => '2026-09-01', 'end_at' => null]);
        }
        return $offer;
    }

    private function results(array $filters = [])
    {
        return $this->get(route('public-cars.index', $this->period($filters)))->assertOk();
    }

    public function test_each_product_shows_only_the_cheapest_available_offer_with_its_own_conditions(): void
    {
        $product = VehicleProduct::create(['name' => 'Panda', 'description' => 'NOTA INTERNA RISERVATA']);
        $this->groupedOffer(1, $product, 5000, 1, price: ['deposit_cents' => 90000, 'km_included_per_day' => null]);
        $cheapest = $this->groupedOffer(2, $product, 3000, 2, ['transmission' => 'automatic'], ['deposit_cents' => 20000, 'km_included_per_day' => 150]);
        $response = $this->results()->assertViewHas('results', fn ($rows) => $rows->total() === 1);
        $car = $response->viewData('results')->first();
        $this->assertSame($cheapest->id, $car['id']);
        $this->assertSame(9000, $car['total_cents']);
        $this->assertSame(20000, $car['deposit_cents']);
        $this->assertSame(150, $car['km_per_day']);
        $this->assertSame('Automatico', $car['transmission']);
        $this->assertSame(2, $car['supplier_id']);
        $this->assertSame($product->id, $car['product_id']);
        $response->assertSee('Panda')->assertSee('Noleggiatore di prova')->assertDontSee('NOTA INTERNA RISERVATA')
            ->assertDontSee('TESTPLATE')->assertDontSee('PRIVATE-VIN')
            ->assertSee(route('public-cars.show', ['pricelist' => $cheapest->id] + $this->period()));
    }

    public function test_busy_and_inactive_pricelist_cheaper_cars_do_not_hide_the_next_available_car(): void
    {
        $product = VehicleProduct::create(['name' => 'Panda']);
        $this->groupedOffer(1, $product, 1000);
        $this->groupedOffer(2, $product, 2000)->pricelist->update(['status' => 'archived', 'active_flag' => null]);
        $this->groupedOffer(3, $product, 3000);
        DB::table('vehicle_blocks')->insert(['vehicle_id' => 1, 'status' => 'active', 'start_at' => '2026-09-01', 'end_at' => null]);
        $this->results()->assertViewHas('results', fn ($rows) => $rows->pluck('id')->all() === [3]);
        DB::table('rentals')->insert(['vehicle_id' => 3, 'status' => 'reserved', 'planned_pickup_at' => '2026-09-10', 'planned_return_at' => '2026-09-14']);
        $this->results()->assertViewHas('results', fn ($rows) => $rows->isEmpty());
    }

    public function test_vehicle_and_supplier_filters_run_before_choosing_the_cheapest(): void
    {
        $product = VehicleProduct::create(['name' => 'Panda']);
        $this->groupedOffer(1, $product, 1000, 1, ['transmission' => 'manual']);
        $this->groupedOffer(2, $product, 2000, 2, ['transmission' => 'automatic']);
        $this->results(['transmission' => 'automatic'])->assertViewHas('results', fn ($rows) => $rows->pluck('id')->all() === [2]);
        $this->results(['supplier' => 2])->assertViewHas('results', fn ($rows) => $rows->pluck('id')->all() === [2])
            ->assertViewHas('suppliers', fn ($rows) => $rows->pluck('id')->sort()->values()->all() === [1, 2]);
        $this->results(['supplier' => 2, 'budget' => '59.99'])->assertViewHas('results', fn ($rows) => $rows->isEmpty());
    }

    public function test_cheaper_offer_outside_the_selected_delivery_place_is_not_used(): void
    {
        $product = VehicleProduct::create(['name' => 'Panda']);
        $this->groupedOffer(1, $product, 1000);
        $this->groupedOffer(2, $product, 2000, 2);
        $data = ['name' => 'Aeroporto dimostrativo', 'kind' => 'airport', 'city' => 'Bari', 'address_line' => 'Punto dimostrativo', 'country_code' => 'IT'];
        $place = PublicPickupPlace::create($data + ['identity_key' => PublicPickupPlace::identity($data)]);
        $location = Location::create(['organization_id' => 2] + $place->only(['name', 'city', 'address_line', 'country_code']));
        PublicDeliveryLocation::create(['organization_id' => 2, 'public_pickup_place_id' => $place->id, 'location_id' => $location->id, 'is_active' => true]);
        $this->results(['place_id' => $place->id])->assertViewHas('results', fn ($rows) => $rows->pluck('id')->all() === [2]);
    }

    public function test_comparison_uses_the_full_period_quote_instead_of_the_base_daily_rate(): void
    {
        $product = VehicleProduct::create(['name' => 'Panda']);
        $this->groupedOffer(1, $product, 5000);
        $this->groupedOffer(2, $product, 6000);
        DB::table('vehicle_pricelist_tiers')->insert(['vehicle_pricelist_id' => 2, 'name' => 'Sconto dimostrativo', 'min_days' => 3, 'max_days' => 10, 'discount_pct' => 50]);
        $this->results()->assertViewHas('results', fn ($rows) => $rows->pluck('id')->all() === [2] && $rows->first()['total_cents'] === 9000);
    }

    public function test_descending_sort_and_pagination_never_select_the_expensive_car_within_a_product(): void
    {
        config()->set('public_cars.per_page', 2);
        $a = VehicleProduct::create(['name' => 'Panda']);
        $b = VehicleProduct::create(['name' => 'Altro modello']);
        $this->groupedOffer(1, $a, 1000); $this->groupedOffer(2, $a, 9000);
        $this->groupedOffer(3, $b, 2000); $this->groupedOffer(4, $b, 8000);
        $this->offer(5, price: ['base_daily_cents' => 3000]);
        $this->offer(6, price: ['base_daily_cents' => 4000]);
        $this->results(['sort' => 'price_desc'])->assertViewHas('results', fn ($rows) => $rows->total() === 4 && $rows->pluck('id')->all() === [6, 5]);
        $this->results(['sort' => 'price_desc', 'page' => 2])->assertViewHas('results', fn ($rows) => $rows->total() === 4 && $rows->pluck('id')->all() === [3, 1]);
        $this->results(['budget' => '60'])->assertViewHas('results', fn ($rows) => $rows->total() === 2 && $rows->pluck('id')->all() === [1, 3]);
    }

    public function test_tied_prices_have_a_stable_winner_and_ungrouped_offers_remain_individual(): void
    {
        $product = VehicleProduct::create(['name' => 'Panda']);
        $this->groupedOffer(1, $product, 1000); $this->groupedOffer(2, $product, 1000);
        $this->offer(3, price: ['base_daily_cents' => 1000], vehicle: ['make' => 'Fiat', 'model' => 'Panda']);
        $this->offer(4, price: ['base_daily_cents' => 1000], vehicle: ['make' => 'Fiat', 'model' => 'Panda']);
        foreach (['price_asc', 'price_desc'] as $sort) {
            $this->results(['sort' => $sort])->assertViewHas('results', fn ($rows) => $rows->pluck('id')->all() === [1, 3, 4]);
        }
    }

    public function test_search_recognizes_the_product_name_and_renter_preview_keeps_its_own_scope(): void
    {
        $product = VehicleProduct::create(['name' => 'Citycar dimostrativa']);
        $this->groupedOffer(1, $product, 1000); $this->groupedOffer(2, $product, 2000, 2);
        $this->results(['q' => 'citycar dimostrativa'])->assertViewHas('results', fn ($rows) => $rows->pluck('id')->all() === [1]);
        $this->actingAs($this->publisher(2, 'renter'))->get(route('public-cars.preview.index', $this->period()))->assertOk()
            ->assertViewHas('results', fn ($rows) => $rows->pluck('id')->all() === [2]);
    }

    public function test_booking_reserves_exactly_the_winning_vehicle_then_search_falls_back_to_the_next_one(): void
    {
        $product = VehicleProduct::create(['name' => 'Panda']);
        $this->groupedOffer(1, $product, 5000);
        $this->groupedOffer(2, $product, 3000, 2);
        $this->results()->assertViewHas('results', fn ($rows) => $rows->pluck('id')->all() === [2]);
        $page = $this->get(route('public-cars.booking.create', ['pricelist' => 2] + $this->period()))->assertOk();
        $form = $this->period() + ['checkout_token' => $page->viewData('checkoutToken'), 'first_name' => 'Cliente', 'last_name' => 'Dimostrativo',
            'email' => 'prodotto@example.test', 'phone' => '0000000000', 'accept_summary' => 1, 'vehicle_id' => 1, 'organization_id' => 1];
        $this->post(route('public-cars.booking.store', 2), $form)->assertStatus(303);
        $booking = PublicBooking::sole();
        $this->assertSame(2, (int) $booking->rental->vehicle_id);
        $this->assertSame(2, (int) $booking->organization_id);
        $this->assertSame(9000, $booking->total_cents);
        $this->assertDatabaseCount('rentals', 1);
        $this->results()->assertViewHas('results', fn ($rows) => $rows->pluck('id')->all() === [1]);
    }

    public function test_empty_products_do_not_claim_availability_and_product_name_is_escaped(): void
    {
        VehicleProduct::create(['name' => 'Prodotto vuoto']);
        $product = VehicleProduct::create(['name' => '<script>product()</script>']);
        $this->groupedOffer(1, $product, 1000);
        $this->results()->assertViewHas('results', fn ($rows) => $rows->total() === 1)
            ->assertSee('&lt;script&gt;product()&lt;/script&gt;', false)->assertDontSee('<script>product()</script>', false)->assertDontSee('Prodotto vuoto');
    }
}
