<?php

namespace Tests\Feature;

use App\Models\{Location, PublicBooking, PublicDeliveryLocation, PublicRentalOffer, RentalContractSnapshot, VehiclePricelist};
use Illuminate\Support\Facades\DB;
use Tests\Support\PublicBookingTestCase;

class PublicAutomaticCatalogTest extends PublicBookingTestCase
{
    private function price(int $id = 41): VehiclePricelist
    {
        DB::table('vehicles')->insert([
            'id' => $id, 'admin_organization_id' => 1, 'default_pickup_location_id' => 1,
            'plate' => 'PRIVATE'.$id, 'vin' => 'PRIVATE-VIN-'.$id, 'make' => 'Fiat', 'model' => 'Panda',
            'seats' => 5, 'fuel_type' => 'petrol', 'transmission' => 'manual', 'is_active' => true,
        ]);
        DB::table('vehicle_pricelists')->insert([
            'id' => $id, 'vehicle_id' => $id, 'renter_org_id' => 1, 'base_daily_cents' => 5000,
            'name' => 'NOTA RISERVATA DEL GESTIONALE', 'deposit_cents' => 45000,
        ]);
        PublicDeliveryLocation::forLocation(Location::findOrFail(1));
        return VehiclePricelist::findOrFail($id);
    }

    private function form(int $id = 41): array
    {
        $period = $this->period(['place_id' => PublicDeliveryLocation::where('organization_id', 1)->value('public_pickup_place_id')]);
        $page = $this->get(route('public-cars.booking.create', ['pricelist' => $id] + $period))->assertOk();
        return $period + ['checkout_token' => $page->viewData('checkoutToken'), 'first_name' => 'Cliente',
            'last_name' => 'Dimostrativo', 'email' => 'automatico@example.test', 'phone' => '0000000000', 'accept_summary' => 1];
    }

    public function test_places_appear_without_any_fleet_pricelist_or_offer_and_are_unique(): void
    {
        $a = PublicDeliveryLocation::forLocation(Location::findOrFail(1));
        $b = PublicDeliveryLocation::forLocation(Location::findOrFail(2));
        PublicDeliveryLocation::create(['organization_id' => 2, 'public_pickup_place_id' => $a->public_pickup_place_id, 'location_id' => 2, 'is_active' => true]);
        $this->get(route('public-cars.index'))->assertOk()
            ->assertViewHas('places', fn ($places) => $places->pluck('id')->sort()->values()->all() === [$a->public_pickup_place_id, $b->public_pickup_place_id])
            ->assertViewHas('destinations', fn ($destinations) => $destinations->pluck('value')->all() === ['city:Bari'])
            ->assertSee('Bari — Tutti i punti di ritiro')->assertDontSee($a->place->name)->assertDontSee($b->place->name)
            ->assertSee('id="pickup-place" name="destination"', false)->assertDontSee('offerte pubblicate');
        $this->get(route('public-cars.index', $this->period(['place_id' => $a->public_pickup_place_id])))->assertOk()
            ->assertViewHas('results', fn ($rows) => $rows->isEmpty())
            ->assertViewHas('places', fn ($places) => $places->count() === 2)
            ->assertSee('Nessuna auto disponibile con questi criteri');
        foreach (['vehicles', 'vehicle_pricelists', 'public_rental_offers'] as $table) $this->assertDatabaseCount($table, 0);
    }

    public function test_disabled_places_and_inactive_renters_are_excluded_even_without_offers(): void
    {
        $a = PublicDeliveryLocation::forLocation(Location::findOrFail(1));
        $b = PublicDeliveryLocation::forLocation(Location::findOrFail(2));
        $c = PublicDeliveryLocation::forLocation(Location::findOrFail(3));
        $a->update(['is_active' => false]);
        DB::table('organizations')->where('id', 2)->update(['is_active' => false]);
        $this->get(route('public-cars.index'))->assertOk()
            ->assertViewHas('places', fn ($places) => $places->pluck('id')->all() === [$c->public_pickup_place_id]);
    }

    public function test_search_detail_and_checkout_work_without_creating_offers_on_get_requests(): void
    {
        $this->price();
        $this->get(route('public-cars.index', $this->period()))->assertOk()
            ->assertViewHas('results', fn ($rows) => $rows->pluck('id')->all() === [41] && $rows->first()['total_cents'] === 15000)
            ->assertSee('150,00 €')->assertDontSee('NOTA RISERVATA')->assertDontSee('PRIVATE');
        $this->get(route('public-cars.show', ['pricelist' => 41] + $this->period()))->assertOk()->assertSee('150,00 €');
        $this->form();
        foreach (['public_rental_offers', 'customers', 'rentals', 'public_bookings'] as $table) $this->assertDatabaseCount($table, 0);
    }

    public function test_confirming_without_a_legacy_offer_reserves_the_exact_car_only_once(): void
    {
        $this->price(); $form = $this->form();
        $url = route('public-cars.booking.store', 41);
        $first = $this->post($url, $form)->assertStatus(303);
        $this->post($url, $form)->assertRedirect($first->headers->get('Location'));
        $booking = PublicBooking::sole();
        $this->assertSame(41, (int) $booking->rental->vehicle_id);
        $this->assertSame(41, $booking->quote_snapshot['pricelist_id']);
        $this->assertSame(15000, $booking->total_cents);
        $this->assertSame(41, RentalContractSnapshot::sole()->pricing_snapshot['pricelist_id']);
        foreach (['public_rental_offers', 'customers', 'rentals', 'public_bookings', 'renter_contract_number_ledger'] as $table) $this->assertDatabaseCount($table, 1);
        $this->get($booking->confirmationUrl())->assertOk()->assertSee('150,00');
    }

    public function test_legacy_offer_urls_redirect_to_the_exact_pricelist_even_when_ids_differ(): void
    {
        $price = $this->price();
        $offer = PublicRentalOffer::create(['vehicle_id' => 41, 'organization_id' => 1, 'location_id' => 1,
            'pricelist_id' => 41, 'is_published' => false, 'prices_include_vat' => false]);
        $this->assertNotSame((int) $offer->id, (int) $price->id);
        $place = PublicDeliveryLocation::first()->public_pickup_place_id;
        foreach (['show' => 'show', 'photo' => 'photo', 'booking' => 'booking.create'] as $old => $new) {
            $this->get(route('public-cars.legacy.'.$old, ['offer' => $offer->id] + $this->period()))
                ->assertRedirect(route('public-cars.'.$new, ['pricelist' => 41] + $this->period(['place_id' => $place])));
        }
        $this->get(route('public-cars.show', ['pricelist' => $offer->id] + $this->period()))->assertNotFound();
        $this->post(route('public-cars.legacy.store', $offer->id), ['checkout_token' => 'old-token'])->assertStatus(303)->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('rentals', 0);
    }

    public function test_existing_legacy_records_remain_unchanged_and_do_not_override_current_pricing(): void
    {
        $oldPrice = $this->price();
        $offer = PublicRentalOffer::create(['vehicle_id' => 41, 'organization_id' => 1, 'location_id' => 1,
            'pricelist_id' => 41, 'description' => 'VECCHIE CONDIZIONI', 'is_published' => false, 'prices_include_vat' => false]);
        $oldAttributes = $offer->fresh()->getAttributes();
        $oldPrice->update(['status' => 'archived', 'active_flag' => null]);
        $newPrice = $oldPrice->replicate()->fill(['status' => 'active', 'active_flag' => true, 'base_daily_cents' => 6000]);
        $newPrice->save();
        $this->post(route('public-cars.booking.store', $newPrice->id), $this->form($newPrice->id))->assertStatus(303);
        $booking = PublicBooking::sole();
        $this->assertSame(18000, $booking->total_cents);
        $this->assertSame((int) $newPrice->id, $booking->quote_snapshot['pricelist_id']);
        $this->assertSame($oldAttributes, $offer->fresh()->getAttributes());
        $this->get($booking->confirmationUrl())->assertOk()->assertSee('180,00')->assertDontSee('VECCHIE CONDIZIONI');
    }

    public function test_no_offer_booking_still_rechecks_availability_and_price(): void
    {
        $price = $this->price(); $form = $this->form();
        $price->update(['base_daily_cents' => 6000]);
        $this->post(route('public-cars.booking.store', 41), $form)->assertSessionHasErrors('booking');
        $form = $this->form();
        DB::table('vehicle_blocks')->insert(['vehicle_id' => 41, 'start_at' => '2026-09-11', 'end_at' => null, 'status' => 'active']);
        $this->post(route('public-cars.booking.store', 41), $form)->assertSessionHasErrors('booking');
        foreach (['public_rental_offers', 'customers', 'rentals', 'public_bookings'] as $table) $this->assertDatabaseCount($table, 0);
    }
}
