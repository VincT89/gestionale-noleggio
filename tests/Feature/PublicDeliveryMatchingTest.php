<?php

namespace Tests\Feature;

use App\Models\{Location, PublicBooking, PublicDeliveryLocation, PublicPickupPlace};
use Illuminate\Support\Facades\{DB, Mail};
use Tests\Support\PublicBookingTestCase;

class PublicDeliveryMatchingTest extends PublicBookingTestCase
{
    private function place(string $name = 'Aeroporto dimostrativo', string $kind = 'airport'): PublicPickupPlace
    {
        $data = ['name' => $name, 'kind' => $kind, 'city' => 'Bari', 'address_line' => 'Punto di incontro dimostrativo', 'country_code' => 'IT'];
        return PublicPickupPlace::create($data + ['identity_key' => PublicPickupPlace::identity($data)]);
    }

    private function cover(int $org, PublicPickupPlace $place): PublicDeliveryLocation
    {
        $location = Location::create(['organization_id' => $org] + $place->only(['name', 'city', 'address_line', 'country_code']));
        return PublicDeliveryLocation::create(['organization_id' => $org, 'public_pickup_place_id' => $place->id,
            'location_id' => $location->id, 'is_active' => true]);
    }

    private function assignedOffer(int $id, int $org, array $offer = [])
    {
        $model = $this->offer($id, $org, offer: $offer);
        DB::table('vehicle_assignments')->insert(['vehicle_id' => $id, 'renter_org_id' => $org, 'start_at' => '2026-09-01', 'end_at' => null]);
        return $model;
    }

    private function search(int $place, array $filters = [])
    {
        return $this->get(route('public-cars.index', ['place_id' => $place] + $this->period($filters)))->assertOk();
    }

    private function form(int $place): array
    {
        $period = $this->period(['place_id' => $place]);
        $page = $this->get(route('public-cars.booking.create', ['pricelist' => 1] + $period))->assertOk();
        return $period + ['checkout_token' => $page->viewData('checkoutToken'), 'first_name' => 'Cliente', 'last_name' => 'Dimostrativo',
            'email' => 'matching@example.test', 'phone' => '0000000000', 'accept_summary' => 1];
    }

    public function test_matching_uses_served_place_not_city_of_the_renters_office(): void
    {
        $this->assignedOffer(1, 2);
        $this->assignedOffer(2, 3); // The renter's office is in Roma.
        $this->offer(3); // A published supplier that does not serve the airport.
        $place = $this->place();
        $this->cover(2, $place); $this->cover(3, $place);
        $this->search($place->id)->assertViewHas('results', fn ($rows) => $rows->pluck('id')->all() === [1, 2]
            && $rows->pluck('location')->unique()->all() === [$place->name])
            ->assertViewHas('suppliers', fn ($rows) => $rows->pluck('id')->sort()->values()->all() === [2, 3]);
        $this->search($place->id, ['supplier' => 3])->assertViewHas('results', fn ($rows) => $rows->pluck('id')->all() === [2]);
    }

    public function test_an_empty_budget_does_not_remove_the_selected_or_other_eligible_suppliers(): void
    {
        $this->assignedOffer(1, 2);
        $this->assignedOffer(2, 3);
        $this->offer(3);
        $place = $this->place();
        $this->cover(2, $place); $this->cover(3, $place);

        $this->search($place->id, ['supplier' => 3, 'budget' => 1])
            ->assertViewHas('results', fn ($rows) => $rows->isEmpty())
            ->assertViewHas('suppliers', fn ($rows) => $rows->pluck('id')->sort()->values()->all() === [2, 3])
            ->assertSee('value="3" selected', false);
        $this->search($place->id, ['supplier' => 3, 'budget' => 1000])
            ->assertViewHas('results', fn ($rows) => $rows->pluck('id')->all() === [2]);
        $this->search($place->id, ['supplier' => 3, 'q' => 'Nessun modello corrispondente'])
            ->assertViewHas('results', fn ($rows) => $rows->isEmpty())
            ->assertViewHas('suppliers', fn ($rows) => $rows->pluck('id')->sort()->values()->all() === [2, 3]);
    }

    public function test_pickup_cards_render_the_actual_place_without_template_directives(): void
    {
        $this->assignedOffer(1, 2);
        $place = $this->place();
        $this->cover(2, $place);
        $page = $this->search($place->id)->assertDontSee('@else')->assertDontSee('@endif');
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">'.$page->getContent());
        $pickup = (new \DOMXPath($document))->query('//div[@class="amd-car-pickup"]/p')->item(0);
        $this->assertStringContainsString($place->name, $pickup->textContent);
    }

    public function test_same_city_is_not_sufficient_and_unserved_places_never_fall_back_to_the_office(): void
    {
        $this->offer();
        $airport = $this->place(); $station = $this->place('Stazione dimostrativa', 'station');
        $this->cover(1, $airport);
        $this->search($station->id)->assertViewHas('results', fn ($rows) => $rows->isEmpty());
        $this->search(2147483647)->assertViewHas('results', fn ($rows) => $rows->isEmpty());
        foreach (['public-cars.show', 'public-cars.booking.create'] as $route) {
            $this->get(route($route, ['pricelist' => 1, 'place_id' => $station->id] + $this->period()))->assertOk()->assertViewHas('car', null);
        }
    }

    public function test_match_excludes_busy_unassigned_inactive_pricelists_and_inactive_renters(): void
    {
        $place = $this->place();
        $this->cover(1, $place); $this->cover(2, $place); $this->cover(3, $place);
        $this->offer(1); $this->offer(2);
        $this->offer(3, 2); // No assignment.
        $this->assignedOffer(4, 3);
        $this->offer(5, price: ['status' => 'draft', 'active_flag' => null]);
        DB::table('organizations')->where('id', 3)->update(['is_active' => false]);
        DB::table('vehicle_blocks')->insert(['vehicle_id' => 2, 'status' => 'active', 'start_at' => '2026-09-11', 'end_at' => null]);
        $this->search($place->id)->assertViewHas('results', fn ($rows) => $rows->pluck('id')->all() === [1]);
    }

    public function test_place_selection_is_carried_through_links_checkout_contract_and_confirmation(): void
    {
        Mail::fake(); $this->assignedOffer(1, 2);
        $place = $this->place(); $delivery = $this->cover(2, $place);
        $period = $this->period(['place_id' => $place->id]);
        $this->get(route('public-cars.show', ['pricelist' => 1] + $period))->assertOk()
            ->assertSee(route('public-cars.booking.create', ['pricelist' => 1] + $period));
        $form = $this->form($place->id);
        $form['place_id'] = (string) $place->id; // Browser form values are strings.
        $response = $this->post(route('public-cars.booking.store', 1), $form)->assertStatus(303);
        $booking = PublicBooking::firstOrFail();
        $this->assertSame(2, (int) $booking->organization_id);
        $this->assertSame($delivery->location_id, (int) $booking->rental->pickup_location_id);
        $this->assertSame($delivery->location_id, (int) $booking->rental->return_location_id);
        $this->assertSame($place->id, $booking->quote_snapshot['place_id']);
        $this->get($response->headers->get('Location'))->assertOk()->assertSee($place->name);
        $this->post(route('public-cars.booking.store', 1), $form)->assertRedirect($booking->confirmationUrl());
        $this->assertDatabaseCount('public_bookings', 1);
        Mail::assertNothingSent();
    }

    public function test_changing_or_removing_place_in_checkout_is_rejected_without_creating_records(): void
    {
        $this->offer(); $a = $this->place(); $b = $this->place('Stazione dimostrativa', 'station');
        $this->cover(1, $a); $this->cover(1, $b);
        $form = $this->form($a->id);
        $this->post(route('public-cars.booking.store', 1), array_replace($form, ['place_id' => $b->id]))->assertSessionHasErrors('checkout_token');
        unset($form['place_id']);
        $this->post(route('public-cars.booking.store', 1), $form)->assertSessionHasErrors('checkout_token');
        $this->assertDatabaseCount('rentals', 0); $this->assertDatabaseCount('customers', 0);
    }

    public function test_disabling_a_place_invalidates_pending_checkout_but_preserves_confirmed_bookings(): void
    {
        $this->offer(); $place = $this->place(); $delivery = $this->cover(1, $place);
        $form = $this->form($place->id);
        $delivery->update(['is_active' => false]);
        $this->post(route('public-cars.booking.store', 1), $form)->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('customers', 0);
        $delivery->update(['is_active' => true]);
        $this->post(route('public-cars.booking.store', 1), $form)->assertStatus(303);
        $booking = PublicBooking::firstOrFail();
        $delivery->update(['is_active' => false]);
        $this->get($booking->confirmationUrl())->assertOk()->assertSee($place->name);
        $this->assertSame('reserved', $booking->rental->status);
    }

    public function test_disabled_original_pickup_is_not_bookable_using_an_old_link_without_place(): void
    {
        $this->offer();
        PublicDeliveryLocation::query()->update(['is_active' => false]);
        $this->get(route('public-cars.booking.create', ['pricelist' => 1] + $this->period()))->assertOk()->assertViewHas('car', null);
    }

    public function test_foreign_location_mapping_never_matches_or_discloses_private_locations(): void
    {
        $this->offer(); $place = $this->place(); $delivery = $this->cover(1, $place);
        $delivery->update(['location_id' => 3]);
        $this->search($place->id)->assertViewHas('results', fn ($rows) => $rows->isEmpty());
        $this->get(route('public-cars.index'))->assertOk()->assertDontSee($place->name);
    }

    public function test_renter_can_select_shared_place_and_cannot_manage_another_renter(): void
    {
        $place = $this->place(); $foreign = $this->cover(3, $place);
        $this->actingAs($this->publisher(2, 'renter'));
        $data = ['organization_id' => 2, 'place_id' => $place->id, 'confirm_delivery' => 1];
        $this->post(route('public-deliveries.store'), $data)->assertRedirect(route('public-deliveries.index'));
        $own = PublicDeliveryLocation::where('organization_id', 2)->sole();
        $this->assertSame(2, (int) $own->location->organization_id);
        $this->assertSame($place->name, $own->location->name);
        $this->post(route('public-deliveries.store'), $data)->assertRedirect();
        $this->assertSame(1, PublicDeliveryLocation::where('organization_id', 2)->count());
        $this->get(route('public-deliveries.index'))->assertOk()->assertViewHas('deliveries', fn ($rows) => $rows->modelKeys() === [$own->id]);
        $this->post(route('public-deliveries.store'), array_replace($data, ['organization_id' => 3]))->assertNotFound();
        $this->put(route('public-deliveries.update', $foreign), ['is_active' => 0])->assertNotFound();
        $this->put(route('public-deliveries.update', $own), ['is_active' => 0])->assertRedirect();
        $this->assertFalse($own->fresh()->is_active);
        $this->assertTrue($foreign->fresh()->is_active);
    }

    public function test_new_places_are_normalized_without_changing_existing_shared_names(): void
    {
        $this->actingAs($this->publisher(2, 'renter'));
        $data = ['organization_id' => 2, 'name' => 'Aeroporto Dimostrativo', 'kind' => 'airport', 'city' => 'Bari',
            'address_line' => 'Ingresso dimostrativo', 'country_code' => 'IT', 'confirm_delivery' => 1];
        $this->post(route('public-deliveries.store'), $data)->assertRedirect();
        $this->post(route('public-deliveries.store'), array_replace($data, ['name' => '  aeroporto   dimostrativo  ', 'city' => 'BARI']))->assertRedirect();
        $this->assertDatabaseCount('public_pickup_places', 1);
        $this->assertDatabaseCount('public_delivery_locations', 1);
        $this->assertSame('Aeroporto Dimostrativo', PublicPickupPlace::sole()->name);
    }

    public function test_delivery_requires_permission(): void
    {
        $this->get(route('public-deliveries.index'))->assertRedirect(route('login'));
        $this->actingAs($this->publisher(2, 'viewer'))->get(route('public-deliveries.index'))->assertForbidden();
    }

    public function test_delivery_requires_explicit_conditions_acceptance(): void
    {
        $place = $this->place();
        $this->actingAs($this->publisher(3, 'renter'));
        $this->post(route('public-deliveries.store'), ['organization_id' => 3, 'place_id' => $place->id])->assertSessionHasErrors('confirm_delivery');
        $this->assertDatabaseCount('public_delivery_locations', 0);
    }

    public function test_legacy_migration_copies_only_the_original_offer_location_without_inventing_coverage(): void
    {
        $this->offer(); $this->offer(2, 2, offer: ['is_published' => false]);
        $migration = require database_path('migrations/2026_09_15_100000_create_public_pickup_places_table.php');
        $migration->down(); $migration->up();
        $this->assertDatabaseCount('public_delivery_locations', 2);
        $this->assertSame([1, 2], PublicDeliveryLocation::orderBy('location_id')->pluck('location_id')->map(fn ($id) => (int) $id)->all());
        $this->assertFalse(PublicDeliveryLocation::where('location_id', 3)->exists());
    }

    public function test_unlimited_mileage_and_zero_included_km_are_distinct_public_conditions(): void
    {
        $this->offer(price: ['km_included_per_day' => null]);
        $this->offer(2, price: ['km_included_per_day' => 0]);
        $this->get(route('public-cars.show', ['pricelist' => 1] + $this->period()))->assertOk()->assertSee('Illimitati')->assertDontSee('Chilometri extra');
        $this->get(route('public-cars.show', ['pricelist' => 2] + $this->period()))->assertOk()->assertSee('0 km/giorno')->assertSee('Chilometri extra');
    }
}
