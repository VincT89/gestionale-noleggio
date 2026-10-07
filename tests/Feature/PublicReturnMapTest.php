<?php

namespace Tests\Feature;

use App\Services\Geocoding\PlaceSelection;
use Illuminate\Support\Facades\Http;
use Tests\Support\PublicBookingTestCase;

class PublicReturnMapTest extends PublicBookingTestCase
{
    private function point(array $extra = []): array
    {
        return array_replace(['return_address' => 'Aeroporto dimostrativo, ingresso partenze, Comune di prova',
            'map_lat' => '41.1387594', 'map_lng' => '16.7651234', 'map_zoom' => 18, 'map_confirmed' => 1], $extra);
    }

    public function test_explicit_return_point_is_issued_separately_without_overwriting_pickup(): void
    {
        $this->startSession();
        $selection = app(PlaceSelection::class);
        $pickup = $selection->issue([['label' => 'Hotel dimostrativo, Comune di prova', 'lat' => 41.12, 'lng' => 16.86]])[0]['token'];
        $response = $this->postJson(route('public-cars.return-map.store'), $this->point())->assertOk()
            ->assertJsonPath('point.lat', 41.1387594)->assertJsonPath('point.lng', 16.7651234)->assertJsonPath('point.source', 'map');
        $token = $response->json('selection.token');
        $this->assertSame($this->point()['return_address'], $selection->resolve($token, null, 'public-return')['label']);
        $this->assertNull($selection->resolve($token));
        $this->assertSame(41.12, $selection->resolve($pickup)['lat']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('amd_rent_enquiries', 0);
    }

    public function test_only_precise_confirmed_coordinates_are_accepted(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        foreach (['map_lat' => [null, [], 91, '-86', 'NaN', '1e309'], 'map_lng' => [null, 181, '-181'],
            'map_zoom' => [null, 5, 15, 20, 16.5], 'map_confirmed' => [null, 0], 'return_address' => [null, [], 'airport', str_repeat('x', 501)]] as $field => $values) {
            foreach ($values as $value) {
                $this->postJson(route('public-cars.return-map.store'), $this->point([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
                $this->assertEmpty(session('amd_place_selections', []));
            }
        }
        Http::assertNothingSent();
    }

    public function test_preview_confirmation_requires_authentication_and_permission(): void
    {
        $this->postJson(route('public-cars.preview.return-map.store'), $this->point())->assertUnauthorized();
        $publisher = $this->publisher();
        $this->actingAs($publisher)->postJson(route('public-cars.preview.return-map.store'), $this->point())->assertOk();
        $publisher->update(['is_active' => false]);
        $this->postJson(route('public-cars.preview.return-map.store'), $this->point())->assertForbidden();
    }
}
