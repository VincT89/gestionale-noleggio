<?php

namespace App\Domain\Rentals;

use App\Models\PublicPickupPlace;
use Illuminate\Support\Collection;

class PublicPickupDirectory
{
    public const BARI_GROUPS = [
        'bari-airport' => ['name' => 'Bari Aeroporto', 'kind' => 'airport'],
        'bari-port' => ['name' => 'Bari Porto', 'kind' => 'port'],
        'bari-station' => ['name' => 'Bari Stazione', 'kind' => 'station'],
    ];

    public static function groupLabel(?string $group): ?string
    {
        return self::BARI_GROUPS[$group]['name'] ?? null;
    }

    public static function groupForPlace(PublicPickupPlace $place): ?string
    {
        if (mb_strtolower(trim($place->city ?? '')) !== 'bari' || $place->country_code !== 'IT') return null;
        foreach (self::BARI_GROUPS as $key => $group) {
            if ($place->kind === $group['kind']) return $key;
        }
        return null;
    }

    public static function constrainGroup(\Illuminate\Database\Eloquent\Builder $query, string $group): void
    {
        $kind = self::BARI_GROUPS[$group]['kind'] ?? null;
        /** Membership follows the configured city and type, never a guessed business name or geographic radius. */
        $query->whereRaw('LOWER(TRIM(city)) = ?', ['bari'])->where('country_code', 'IT')->where('kind', $kind ?? '__invalid__');
    }

    /** Public destinations are cities and configured landmarks, not renters' office names. */
    public function destinations(Collection $places): Collection
    {
        $destinations = collect(self::BARI_GROUPS)->map(fn ($group, $key) => [
            'value' => 'group:'.$key, 'name' => $group['name'], 'city' => 'Bari', 'kind' => $group['kind'],
            'label' => $group['name'], 'filters' => ['pickup_group' => $key],
        ])->values();
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
                if (!in_array($place->kind, ['airport', 'port', 'station', 'area'], true)) continue;
                if (mb_strtolower($city) === 'bari' && $place->country_code === 'IT'
                    && in_array($place->kind, ['airport', 'port', 'station'], true)) continue;
                $destinations->push([
                    'value' => (string) $place->id, 'name' => $place->name, 'city' => $city, 'kind' => $place->kind,
                    'label' => $place->label, 'filters' => ['place_id' => $place->id],
                ]);
            }
        }

        return $destinations->values();
    }
}
