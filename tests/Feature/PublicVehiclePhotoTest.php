<?php

namespace Tests\Feature;

use App\Domain\Rentals\PublicVehiclePhoto;
use App\Domain\Rentals\PublicVehicleSearch;
use App\Models\PublicRentalOffer;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PublicCarsTestCase;

class PublicVehiclePhotoTest extends PublicCarsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('car_models');
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jN5kAAAAASUVORK5CYII=');
    }

    public function test_model_image_is_served_and_clearly_labelled_in_results_and_detail(): void
    {
        $offer = $this->offer(vehicle: ['make' => 'Toyota', 'model' => 'Yaris']);
        Storage::disk('car_models')->put('toyota-yaris.png', $this->png());

        $this->get(route('public-cars.index', $this->period()))->assertOk()
            ->assertSee('Immagine indicativa del modello')
            ->assertViewHas('results', fn ($results) => $results->first()['has_photo'] && $results->first()['photo_is_reference']);
        $this->get(route('public-cars.show', ['offer' => $offer->id] + $this->period()))->assertOk()
            ->assertSee('Colore e allestimento possono variare.');
        $response = $this->get(route('public-cars.photo', $offer))->assertOk()
            ->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($this->png(), $response->streamedContent());
    }

    public function test_model_image_does_not_make_a_draft_offer_public(): void
    {
        $offer = $this->offer(vehicle: ['make' => 'Toyota', 'model' => 'Yaris'], offer: ['is_published' => false]);
        Storage::disk('car_models')->put('toyota-yaris.png', $this->png());

        $this->get(route('public-cars.photo', $offer))->assertNotFound();
        $this->get(route('public-cars.preview.photo', $offer))->assertRedirect(route('login'));
        $this->actingAs($this->publisher())->get(route('public-cars.preview.photo', $offer))->assertOk();
        $this->get(route('public-cars.preview.index', $this->period()))->assertOk()
            ->assertSee('Immagine indicativa del modello')->assertDontSee('Anteprima riservata');
        $this->assertFalse($offer->fresh()->is_published);
    }

    public function test_real_photo_wins_even_when_an_earlier_attachment_is_missing(): void
    {
        $offer = $this->offer(vehicle: ['make' => 'Toyota', 'model' => 'Yaris']);
        Storage::disk('car_models')->put('toyota-yaris.png', $this->png());
        foreach ([100, 101] as $id) {
            DB::table('media')->insert([
                'id' => $id, 'model_type' => Vehicle::class, 'model_id' => 1,
                'collection_name' => 'vehicle_photos', 'name' => 'Foto di prova', 'file_name' => 'photo.png',
                'mime_type' => 'image/png', 'disk' => 'public', 'order_column' => $id,
            ]);
        }
        $photo = $offer->vehicle->getMedia('vehicle_photos')->last();
        Storage::disk('public')->put($photo->getPathRelativeToRoot(), $this->png());

        $resolved = app(PublicVehiclePhoto::class)->forVehicle($offer->vehicle);
        $this->assertSame('public', $resolved['disk']);
        $this->assertSame($photo->getPathRelativeToRoot(), $resolved['path']);
        $this->assertFalse($resolved['is_reference']);
        $this->get(route('public-cars.index', $this->period()))->assertOk()
            ->assertDontSee('Immagine indicativa')->assertDontSee('Foto non disponibile');
        $this->get(route('public-cars.photo', $offer))->assertOk();
    }

    public function test_only_exact_make_and_model_aliases_are_matched(): void
    {
        Storage::disk('car_models')->put('toyota-yaris.png', $this->png());
        $this->offer(vehicle: ['make' => '  TOYOTA ', 'model' => ' yARIs ']);
        $this->offer(2, vehicle: ['make' => 'Toyota', 'model' => 'Yaris Cross']);
        $this->offer(3, vehicle: ['make' => 'Altra marca', 'model' => 'Yaris']);

        $results = app(PublicVehicleSearch::class)->search(PublicRentalOffer::published(), $this->period())->keyBy('id');
        $this->assertTrue($results[1]['photo_is_reference']);
        $this->assertFalse($results[2]['has_photo']);
        $this->assertFalse($results[3]['has_photo']);
        $this->assertSame(' yARIs ', DB::table('vehicles')->where('id', 1)->value('model'));
    }

    public function test_missing_reference_file_keeps_a_working_placeholder(): void
    {
        $offer = $this->offer(vehicle: ['make' => 'Toyota', 'model' => 'Yaris']);
        $this->get(route('public-cars.index', $this->period()))->assertOk()
            ->assertSee('Foto non disponibile')->assertDontSee('Immagine indicativa');
        $this->get(route('public-cars.photo', $offer))->assertNotFound();
    }

    public function test_other_media_collections_and_unsupported_types_are_never_car_photos(): void
    {
        $offer = $this->offer(vehicle: ['make' => 'Toyota', 'model' => 'Yaris']);
        Storage::disk('car_models')->put('toyota-yaris.png', $this->png());
        DB::table('media')->insert([
            ['id' => 100, 'model_type' => Vehicle::class, 'model_id' => 1, 'collection_name' => 'vehicle_damages', 'name' => 'Danno di prova', 'file_name' => 'photo.png', 'mime_type' => 'image/png', 'disk' => 'public'],
            ['id' => 101, 'model_type' => Vehicle::class, 'model_id' => 1, 'collection_name' => 'vehicle_photos', 'name' => 'Formato non supportato', 'file_name' => 'photo.svg', 'mime_type' => 'image/svg+xml', 'disk' => 'public'],
        ]);
        foreach ($offer->vehicle->media as $media) Storage::disk('public')->put($media->getPathRelativeToRoot(), $this->png());

        $resolved = app(PublicVehiclePhoto::class)->forVehicle($offer->vehicle);
        $this->assertSame('car_models', $resolved['disk']);
        $this->assertTrue($resolved['is_reference']);
    }
}
