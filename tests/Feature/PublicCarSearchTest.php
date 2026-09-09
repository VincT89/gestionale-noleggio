<?php

namespace Tests\Feature;

use App\Domain\Pricing\VehiclePricingService;
use App\Domain\Rentals\PublicVehicleSearch;
use App\Models\PublicRentalOffer;
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
            ->assertSee('Indica le date per verificare la disponibilità.');
    }

    public function test_public_catalog_requires_explicit_publication_final_prices_and_valid_relations(): void
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
        $response->assertOk()->assertViewHas('results', fn ($results) => $results->pluck('id')->all() === [$valid->id]);
        $response->assertDontSee('TESTPLATE')->assertDontSee('PRIVATE-VIN')->assertDontSee('lt_daily_cost')->assertDontSee('net_total_after_lt');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get(route('public-cars.show', ['offer' => 2] + $this->period()))->assertNotFound();
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
        $url = route('public-cars.show', ['offer' => $offer->id] + $this->period(['budget' => '300']));
        $this->get($url)->assertOk()->assertSee('300,00 €')->assertSee('Prenota con pagamento al ritiro');
        DB::table('vehicle_pricelists')->where('id', 1)->update(['base_daily_cents' => 11000]);
        $this->get($url)->assertOk()->assertSee('330,00 €')->assertSee('Il prezzo aggiornato supera il budget indicato.');
        DB::table('rentals')->insert(['vehicle_id' => 1, 'status' => 'reserved', 'planned_pickup_at' => '2026-09-11', 'planned_return_at' => '2026-09-12']);
        $this->get($url)->assertOk()->assertSee('La disponibilità è cambiata')->assertDontSee('330,00 €')->assertDontSee('Prenota con pagamento al ritiro');
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
        $result = app(PublicVehicleSearch::class)->search(PublicRentalOffer::published(), $this->period())->first();
        $this->assertSame([
            'id','title','year','segment','seats','transmission','fuel','organization','location','city','address',
            'description','has_photo','photo_is_reference','total_cents','days','deposit_cents','km_per_day','extra_km_cents','prices_include_vat',
        ], array_keys($result));
    }

    public function test_photos_follow_offer_visibility_and_missing_files_do_not_break_results(): void
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
        $this->get($imageUrl)->assertNotFound();
        $this->actingAs($this->publisher())->get(route('public-cars.preview.photo', $offer))->assertOk();
    }

    public function test_publisher_can_save_draft_then_publish_after_confirming_public_prices(): void
    {
        $offer = $this->offer(offer: ['is_published' => false, 'prices_include_vat' => false]);
        $this->actingAs($this->publisher());
        $this->get(route('public-offers.index'))->assertOk()->assertSee('Catalogo pubblico');
        $this->get($this->url([], 'public-cars.preview.index'))->assertOk()->assertSee('Importo di listino, IVA da verificare');
        $this->get($this->url())->assertOk()->assertViewHas('results', fn ($r) => $r->total() === 0);
        $data = ['pricelist_id' => 1, 'location_id' => 1, 'is_published' => 1];
        $this->put(route('public-offers.update', $offer), $data)->assertSessionHasErrors('prices_include_vat');
        $this->assertFalse($offer->fresh()->is_published);
        $this->put(route('public-offers.update', $offer), $data + ['prices_include_vat' => 1])->assertRedirect(route('public-offers.index'));
        $this->assertTrue($offer->fresh()->is_published);
        $this->get($this->url())->assertOk()->assertViewHas('results', fn ($r) => $r->total() === 1);
        $this->put(route('public-offers.update', $offer), ['pricelist_id' => 1, 'location_id' => 1])->assertRedirect(route('public-offers.index'));
        $this->assertFalse($offer->fresh()->is_published);
    }

    public function test_renter_cannot_manage_other_organizations_or_spoof_price_and_location(): void
    {
        $foreign = $this->offer();
        $own = $this->offer(2, organization: 2, offer: ['is_published' => false]);
        DB::table('vehicle_assignments')->insert(['vehicle_id' => 2, 'renter_org_id' => 2, 'status' => 'active', 'start_at' => '2026-01-01', 'end_at' => null]);
        $this->actingAs($this->publisher(2, 'renter'));
        $this->get(route('public-offers.index', ['edit' => $foreign->id]))->assertNotFound();
        $this->put(route('public-offers.update', $foreign), ['pricelist_id' => 1, 'location_id' => 1])->assertNotFound();
        $this->get(route('public-cars.preview.show', ['offer' => $foreign->id] + $this->period()))->assertNotFound();
        $this->get($this->url([], 'public-cars.preview.index'))->assertOk()->assertViewHas('results', fn ($r) => $r->pluck('id')->all() === [$own->id]);
        $this->put(route('public-offers.update', $own), ['pricelist_id' => 1, 'location_id' => 1])->assertSessionHasErrors('pricelist_id');
        $this->put(route('public-offers.update', $own), ['pricelist_id' => 2, 'location_id' => 1])->assertSessionHasErrors('location_id');
        $this->assertSame(2, $own->fresh()->organization_id);
    }

    public function test_permission_and_active_user_are_required_to_manage_offers(): void
    {
        $this->actingAs($this->publisher(2, 'viewer'));
        $this->get(route('public-offers.index'))->assertForbidden();
        $this->get($this->url([], 'public-cars.preview.index'))->assertForbidden();
        $admin = $this->publisher();
        $admin->update(['is_active' => false]);
        $this->flushSession();
        $this->actingAs($admin)->get(route('public-offers.index'))->assertForbidden();
    }

    public function test_offer_management_filters_vehicle_and_organization_and_displays_real_prices(): void
    {
        $match = $this->offer(1, 2, price: ['base_daily_cents' => 4550, 'deposit_cents' => 35025], vehicle: ['make' => 'Toyota', 'model' => 'Yaris']);
        $this->offer(2, 3, vehicle: ['make' => 'Toyota', 'model' => 'Yaris']);
        $this->offer(3, 2, vehicle: ['make' => 'Fiat', 'model' => 'Panda']);
        $this->actingAs($this->publisher());
        $this->get(route('public-offers.index', ['q' => 'Toyota Yaris noleggiatore', 'organization_id' => 2]))
            ->assertOk()->assertViewHas('offers', fn ($rows) => $rows->pluck('id')->all() === [$match->id])
            ->assertSee('45,50 €')->assertSee('350,25 €')->assertSee('Tariffa base / giorno');
        $this->get(route('public-offers.index', ['q' => 'TESTPLATE3']))->assertOk()
            ->assertViewHas('offers', fn ($rows) => $rows->pluck('id')->all() === [3]);
    }

    public function test_offer_organization_filter_cannot_expand_renter_access(): void
    {
        $this->offer(1, 1);
        $own = $this->offer(2, 2);
        $this->actingAs($this->publisher(2, 'renter'));
        $this->get(route('public-offers.index'))->assertOk()
            ->assertViewHas('offers', fn ($rows) => $rows->pluck('id')->all() === [$own->id])
            ->assertViewHas('organizations', fn ($rows) => $rows->pluck('id')->all() === [2]);
        $this->get(route('public-offers.index', ['organization_id' => 1]))->assertOk()
            ->assertViewHas('offers', fn ($rows) => $rows->isEmpty());
    }

    public function test_offer_deposit_can_be_changed_with_comma_decimals_or_removed_without_changing_rental_price(): void
    {
        $offer = $this->offer();
        $this->actingAs($this->publisher());
        $data = ['pricelist_id' => 1, 'location_id' => 1, 'prices_include_vat' => 1, 'is_published' => 1];
        $this->put(route('public-offers.update', $offer), $data + ['deposit_euros' => '750,25'])
            ->assertRedirect(route('public-offers.index'));
        $this->assertSame(75025, $offer->pricelist->fresh()->deposit_cents);
        $this->get(route('public-cars.show', ['offer' => $offer->id] + $this->period()))
            ->assertOk()->assertSee('750,25 €')->assertSee('300,00 €');
        $this->put(route('public-offers.update', $offer), $data + ['deposit_euros' => '0'])
            ->assertRedirect(route('public-offers.index'));
        $this->assertSame(0, $offer->pricelist->fresh()->deposit_cents);
        foreach (['-1', '1.234', '', '1e3', '42949672.96'] as $invalid) {
            $this->put(route('public-offers.update', $offer), $data + ['deposit_euros' => $invalid])
                ->assertSessionHasErrors('deposit_euros');
            $this->assertSame(0, $offer->pricelist->fresh()->deposit_cents);
        }
    }

    public function test_rejected_offer_changes_never_modify_the_linked_or_foreign_deposit(): void
    {
        $foreign = $this->offer(1, 1);
        $own = $this->offer(2, 2);
        $this->actingAs($this->publisher(2, 'renter'));
        $this->put(route('public-offers.update', $foreign), ['pricelist_id' => 1, 'location_id' => 1, 'deposit_euros' => '1'])
            ->assertNotFound();
        $this->put(route('public-offers.update', $own), ['pricelist_id' => 1, 'location_id' => 1, 'deposit_euros' => '1'])
            ->assertSessionHasErrors('pricelist_id');
        $this->put(route('public-offers.update', $own), ['pricelist_id' => 2, 'location_id' => 1, 'deposit_euros' => '1'])
            ->assertSessionHasErrors('location_id');
        $this->assertSame([50000, 50000], VehiclePricelist::orderBy('id')->pluck('deposit_cents')->all());
    }

    public function test_invalid_management_input_renders_errors_and_preserves_unchecked_publication(): void
    {
        $offer = $this->offer();
        $this->actingAs($this->publisher());
        $editUrl = route('public-offers.index', ['edit' => $offer->id]);
        $this->from($editUrl)->put(route('public-offers.update', $offer), [
            'pricelist_id' => 1, 'location_id' => 1, 'description' => ['invalid'], 'prices_include_vat' => 0, 'is_published' => 0,
        ])->assertRedirect($editUrl)->assertSessionHasErrors('description');
        $response = $this->get($editUrl)->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(0, $xpath->query('//input[@type="checkbox" and @name="is_published" and @checked]')->length);
        $this->assertTrue($offer->fresh()->is_published);
    }
}
