<?php

namespace App\Domain\Rentals;

use App\Models\PublicPickupPlace;
use Illuminate\Support\Collection;

class PublicPickupDirectory
{
    /** Public destinations are cities and configured landmarks, not renters' office names. */
    public function destinations(Collection $places): Collection
    {
        $destinations = collect();
        foreach ($places->groupBy(fn ($place) => mb_strtolower(trim($place->city ?? ''))) as $cityPlaces) {
            $city = trim($cityPlaces->first()->city ?? '');
            if ($city !== '') {
                $destinations->push([
                    'value' => 'city:'.$city, 'name' => $city, 'city' => $city, 'kind' => 'city',
                    'label' => $city.' — Tutti i punti di ritiro', 'filters' => ['city' => $city],
                ]);
            }
            foreach ($cityPlaces as $place) {
                // Legacy locations can contain a business name. Do not infer a landmark from it.
                if (!in_array($place->kind, ['airport', 'station', 'area'], true)) continue;
                $destinations->push([
                    'value' => (string) $place->id, 'name' => $place->name, 'city' => $city, 'kind' => $place->kind,
                    'label' => $place->label, 'filters' => ['place_id' => $place->id],
                ]);
            }
        }

        return $destinations->values();
    }
}
