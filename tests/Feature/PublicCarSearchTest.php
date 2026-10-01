<?php

namespace Tests\Feature;

use App\Domain\Pricing\VehiclePricingService;
use App\Domain\Rentals\PublicVehicleSearch;
use App\Models\VehiclePricelist;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PublicCarsTestCase;

class PublicCarSearchTest extends PublicCarsTestCase
{
    private function url(array $filters = [], string $route = 'public-cars.index'): string
    {
        return route($route, $this->period($filters));
    }

    public function test_busy_vehicles_are_removed_before_any_pricing(): void
    {
        $this->offer();
        DB::table('vehicle_blocks')->insert(['vehicle_id' => 1, 'status' => 'scheduled', 'start_at' => '2026-09-11 00:00:00', 'end_at' => '2026-09-12 00:00:00']);
        $pricing = \Mockery::mock(VehiclePricingService::class);
        $pricing->shouldNotReceive('quote');
        $this->app->instance(VehiclePricingService::class, $pricing);
        $this->get($this->url())->assertOk()->assertViewHas('results', fn ($results) => $results->total() === 0);
    }

    public function test_initial_form_does_not_claim_availability_without_dates(): void
    {
        $this->offer();
        $pricing = \Mockery::mock(VehiclePricingService::class);
        $pricing->shouldNotReceive('quote');
        $this->app->instance(VehiclePricingService::class, $pricing);
        $this->get(route('public-cars.index'))->assertOk()->assertViewHas('searched', false)
            ->assertSee('Indica luogo, date e orari per confrontare i veicoli disponibili.');
    }

    public function test_public_catalog_uses_active_pricelists_and_ignores_legacy_offer_flags(): void
    {
        $valid = $this->offer();
        $this->offer(2, offer: ['is_published' => false]);
        $this->offer(3, offer: ['prices_include_vat' => false]);
        $this->offer(4, price: ['status' => 'archived', 'active_flag' => null]);
        $this->offer(5, vehicle: ['is_active' => false]);
        $this->offer(6, price: ['currency' => 'USD']);
        $this->offer(7, offer: ['location_id' => 3]);
        $this->offer(8, offer: ['organization_id' => 2, 'location_id' => 2]);
        $this->offer(9, price: ['base_daily_cents' => -100]);
        $this->offer(10, vehicle: ['deleted_at' => now()]);

        $response = $this->get($this->url(['preview' => 1, 'organization_id' => 3]));
        $response->assertOk()->assertViewHas('results', fn ($results) => $results->pluck('id')->all() === [1, 2, 3, 7, 8]);
        $response->assertDontSee('TESTPLATE')->assertDontSee('PRIVATE-VIN')->assertDontSee('lt_daily_cost')->assertDontSee('net_total_after_lt');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get(route('public-cars.show', ['pricelist' => 2] + $this->period()))->assertOk();
        $this->get(route('public-cars.photo', 2))->assertNotFound();
        $this->get(route('public-cars.preview.index'))->assertRedirect(route('login'));
    }

    public function test_budget_uses_final_period_price_with_seasons_weekends_and_duration_discount(): void
    {
        $offer = $this->offer(price: ['weekend_pct' => 20]);
        DB::table('vehicle_pricelist_seasons')->insert([
            ['vehicle_pricelist_id' => 1, 'name' => 'Stagione di prova', 'start_mmdd' => '09-01', 'end_mmdd' => '09-30', 'season_pct' => 10, 'weekend_pct_override' => 5, 'priority' => 0, 'is_active' => true],
            ['vehicle_pricelist_id' => 1, 'name' => 'Priorità inferiore', 'start_mmdd' => '09-01', 'end_mmdd' => '09-30', 'season_pct' => 90, 'weekend_pct_override' => 0, 'priority' => 9, 'is_active' => true],
            ['vehicle_pricelist_id' => 1, 'name' => 'Stagione disattivata', 'start_mmdd' => '09-01', 'end_mmdd' => '09-30', 'season_pct' => 99, 'weekend_pct_override' => 0, 'priority' => -1, 'is_active' => false],
        ]);
        DB::table('vehicle_pricelist_tiers')->insert(['vehicle_pricelist_id' => 1, 'name' => 'Tre giorni', 'min_days' => 3, 'max_days' => 6, 'discount_pct' => 10]);
        $pricing = app(VehiclePricingService::class);
        $start = CarbonImmutable::parse($this->period()['pickup_at']);
        $end = CarbonImmutable::parse($this->period()['return_at']);
        $quote = $pricing->quote(VehiclePricelist::findOrFail(1), $start, $end);
        $eagerQuote = $pricing->quote(VehiclePricelist::with(['seasons', 'tiers', 'vehicle'])->findOrFail(1), $start, $end);
        $this->assertSame($quote, $eagerQuote);
        // Thu/Fri: 110 EUR each; Sat: 135 EUR. Three-day discount 10% = 319.50 EUR.
        $this->assertSame(31950, $quote['total']);
        $this->assertSame(50000, $quote['deposit']);
        $this->get($this->url(['budget' => '319,50']))->assertOk()->assertViewHas('results', fn ($r) => $r->pluck('id')->all() === [$offer->id])->assertSee('319,50 €');
        $this->get($this->url(['budget' => '319.49']))->assertOk()->assertViewHas('results', fn ($r) => $r->total() === 0);
    }

    public function test_locality_and_car_filters_use_actual_vehicle_attributes(): void
    {
        $this->offer();
        $matching = $this->offer(2, vehicle: ['make' => 'Opel', 'model' => 'Vivaro', 'seats' => 9, 'fuel_type' => 'diesel', 'transmission' => 'automatic', 'segment' => 'Van']);
        $this->get($this->url(['city' => 'Bari', 'q' => '  Opel Vivaro ', 'seats' => 7, 'fuel_type' => 'diesel', 'transmission' => 'automatic', 'segment' => 'Van']))
            ->assertOk()->assertViewHas('results', fn ($r) => $r->pluck('id')->all() === [$matching->id]);
        $this->get($this->url(['city' => 'Roma']))->assertOk()->assertViewHas('results', fn ($r) => $r->total() === 0);
        $this->get($this->url(['q' => "' OR 1=1 --"]))->assertOk()->assertViewHas('results', fn ($r) => $r->total() === 0);
    }

    public function test_results_can_include_different_organizations_without_showing_unassigned_fleets(): void
    {
        $a = $this->offer();
        $b = $this->offer(2, organization: 2);
        $this->offer(3, organization: 3);
        DB::table('vehicle_assignments')->insert(['vehicle_id' => 2, 'renter_org_id' => 2, 'status' => 'active', 'start_at' => '2026-01-01', 'end_at' => null]);
        $this->get($this->url())->assertOk()->assertViewHas('results', fn ($r) => $r->pluck('id')->all() === [$a->id, $b->id]);
    }

    public function test_case_variants_share_a_filter_without_modifying_the_source_data(): void
    {
        $a = $this->offer(vehicle: ['segment' => 'SUV']);
        $b = $this->offer(2, vehicle: ['segment' => 'Suv']);
        $this->get($this->url(['city' => 'BARI', 'segment' => 'suv']))->assertOk()
            ->assertViewHas('results', fn ($r) => $r->pluck('id')->all() === [$a->id, $b->id])
            ->assertViewHas('segments', fn ($segments) => $segments->count() === 1);
        $this->assertSame('Suv', DB::table('vehicles')->where('id', 2)->value('segment'));
    }

    public function test_pagination_and_sort_are_applied_after_availability_and_budget(): void
    {
        config()->set('public_cars.per_page', 3);
        for ($i = 1; $i <= 8; $i++) $this->offer($i, price: ['base_daily_cents' => $i * 1000]);
        DB::table('vehicle_blocks')->insert(['vehicle_id' => 1, 'status' => 'active', 'start_at' => '2026-09-01', 'end_at' => null]);
        $this->get($this->url(['budget' => '180', 'sort' => 'price_desc', 'page' => 2]))->assertOk()
            ->assertViewHas('results', fn ($r) => $r->total() === 5 && $r->pluck('id')->all() === [3, 2])
            ->assertSee('Pagina 2 di 2');
    }

    public function test_opening_a_result_rechecks_current_availability_and_price(): void
    {
        $offer = $this->offer();
        $url = route('public-cars.show', ['pricelist' => $offer->id] + $this->period(['budget' => '300']));
        $this->get($url)->assertOk()->assertSee('300,00 €')->assertSee('Prenota con il 20% online');
        DB::table('vehicle_pricelists')->where('id', 1)->update(['base_daily_cents' => 11000]);
        $this->get($url)->assertOk()->assertSee('330,00 €')->assertSee('Il prezzo aggiornato supera il budget indicato.');
        DB::table('rentals')->insert(['vehicle_id' => 1, 'status' => 'reserved', 'planned_pickup_at' => '2026-09-11', 'planned_return_at' => '2026-09-12']);
        $this->get($url)->assertOk()->assertSee('La disponibilità è cambiata')->assertDontSee('330,00 €')->assertDontSee('Prenota con il 20% online');
    }

    public function test_invalid_periods_and_filters_return_useful_errors(): void
    {
        foreach ([
            [['pickup_at' => '2026-09-07T10:00'], 'pickup_at'],
            [['return_at' => '2026-09-10T10:00'], 'return_at'],
            [['return_at' => '2026-09-09T10:00'], 'return_at'],
            [['return_at' => '2027-09-14T10:00'], 'return_at'],
            [['pickup_at' => '2026-02-30T10:00'], 'pickup_at'],
            [['budget' => '-1'], 'budget'],
            [['budget' => '10.999'], 'budget'],
            [['transmission' => 'inventato'], 'transmission'],
            [['pickup_at' => ['invalid']], 'pickup_at'],
        ] as [$filters, $error]) {
            $this->get($this->url($filters))->assertRedirect(route('public-cars.index'))->assertSessionHasErrors($error);
        }
        $this->get(route('public-cars.index'))->assertOk()->assertSee('Controlla i dati della ricerca.');
    }

    public function test_public_data_is_an_explicit_allowlist(): void
    {
        $this->offer();
        $result = app(PublicVehicleSearch::class)->search(\App\Models\VehiclePricelist::forPublicRental(), $this->period())->first();
        $this->assertSame([
            'id','vehicle_id','title','year','segment','seats','transmission','fuel','organization','location','city','address',
            'description','has_photo','photo_is_reference','total_cents','days','deposit_cents','km_per_day','extra_km_cents','prices_include_vat',
            'place_id','pickup_location_id','supplier_id','custom_delivery_enabled','delivery_area','product_id','product_name',
        ], array_keys($result));
    }

    public function test_photos_follow_pricelist_visibility_and_missing_files_do_not_break_results(): void
    {
        $offer = $this->offer();
        Storage::fake('public');
        DB::table('media')->insert([
            'id' => 100, 'model_type' => \App\Models\Vehicle::class, 'model_id' => 1,
            'collection_name' => 'vehicle_photos', 'name' => 'Foto di prova', 'file_name' => 'photo.png',
            'mime_type' => 'image/png', 'disk' => 'public',
        ]);
        $photo = $offer->vehicle->getMedia('vehicle_photos')->first();
        $imageUrl = route('public-cars.photo', $offer);
        $this->get($imageUrl)->assertNotFound();
        $this->get($this->url())->assertOk()->assertSee('Foto non disponibile');
        Storage::disk('public')->put($photo->getPathRelativeToRoot(), base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jN5kAAAAASUVORK5CYII='));
        $this->get($imageUrl)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $offer->update(['is_published' => false]);
        $this->get($imageUrl)->assertOk();
        $offer->pricelist->update(['status' => 'draft', 'active_flag' => null]);
        $this->get($imageUrl)->assertNotFound();
        $this->actingAs($this->publisher())->get(route('public-cars.preview.photo', $offer))->assertNotFound();
    }

    public function test_old_catalog_redirects_to_delivery_places_and_cannot_write_prices(): void
    {
        $offer = $this->offer();
        $this->actingAs($this->publisher());
        $this->get(route('public-offers.index'))->assertRedirect(route('public-deliveries.index'));
        $this->post('/catalogo-pubblico', ['pricelist_id' => 1, 'deposit_euros' => '1'])->assertStatus(405);
        $this->put('/catalogo-pubblico/'.$offer->id, ['pricelist_id' => 1, 'deposit_euros' => '1'])->assertNotFound();
        $this->assertSame(50000, $offer->pricelist->fresh()->deposit_cents);
        $this->get(route('public-deliveries.index'))->assertOk()->assertDontSee('Offerte del sito');
    }

    public function test_renter_preview_cannot_expand_to_other_organizations(): void
    {
        $this->offer();
        $this->offer(2, organization: 2);
        DB::table('vehicle_assignments')->insert(['vehicle_id' => 2, 'renter_org_id' => 2, 'status' => 'active', 'start_at' => '2026-01-01', 'end_at' => null]);
        $this->actingAs($this->publisher(2, 'renter'));
        $this->get(route('public-cars.preview.show', ['pricelist' => 1] + $this->period()))->assertNotFound();
        $this->get($this->url(['organization_id' => 1], 'public-cars.preview.index'))->assertOk()
            ->assertViewHas('results', fn ($r) => $r->pluck('id')->all() === [2]);
        $this->get($this->url(['supplier' => 1], 'public-cars.preview.index'))->assertOk()
            ->assertViewHas('results', fn ($r) => $r->isEmpty());
    }

    public function test_permission_and_active_user_are_required_for_management_preview(): void
    {
        $this->actingAs($this->publisher(2, 'viewer'));
        $this->get(route('public-deliveries.index'))->assertForbidden();
        $this->get($this->url([], 'public-cars.preview.index'))->assertForbidden();
        $admin = $this->publisher();
        $admin->update(['is_active' => false]);
        $this->flushSession();
        $this->actingAs($admin)->get($this->url([], 'public-cars.preview.index'))->assertForbidden();
    }
}
