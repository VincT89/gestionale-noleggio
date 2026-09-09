<?php

namespace App\Domain\Rentals;

use App\Models\Vehicle;
use Illuminate\Support\Facades\Storage;

class PublicVehiclePhoto
{
    private const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** @return array{disk: string, path: string, mime_type: string, is_reference: bool}|null */
    public function forVehicle(Vehicle $vehicle): ?array
    {
        // Real vehicle photos take precedence; a missing first file must not hide later photos.
        foreach ($vehicle->getMedia('vehicle_photos') as $media) {
            if (!in_array($media->mime_type, self::MIME_TYPES, true)) {
                continue;
            }

            $path = $media->getPathRelativeToRoot();
            if (Storage::disk($media->disk)->exists($path)) {
                return ['disk' => $media->disk, 'path' => $path, 'mime_type' => $media->mime_type, 'is_reference' => false];
            }
        }

        $make = $this->normalize($vehicle->make);
        $model = $this->normalize($vehicle->model);
        foreach (config('public_car_images', []) as $reference) {
            $makes = array_map($this->normalize(...), $reference['makes']);
            $models = array_map($this->normalize(...), $reference['models']);
            if (!in_array($make, $makes, true) || !in_array($model, $models, true)) {
                continue;
            }

            if (in_array($reference['mime_type'], self::MIME_TYPES, true)
                && Storage::disk('car_models')->exists($reference['file'])) {
                return ['disk' => 'car_models', 'path' => $reference['file'], 'mime_type' => $reference['mime_type'], 'is_reference' => true];
            }
        }

        return null;
    }

    private function normalize(?string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value ?? '')));
    }
}
