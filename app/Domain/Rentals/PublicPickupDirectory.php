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

    /** The public selector offers only the three Bari groups; other places use the address search. */
    public function destinations(): Collection
    {
        return collect(self::BARI_GROUPS)->map(fn ($group, $key) => [
            'value' => 'group:'.$key, 'name' => $group['name'], 'city' => 'Bari', 'kind' => $group['kind'],
            'label' => $group['name'], 'filters' => ['pickup_group' => $key],
        ])->values();
    }
}
