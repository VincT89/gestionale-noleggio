<?php

namespace App\Domain\Rentals;

use App\Models\PublicDeliveryLocation;
use App\Services\AmdRent\DemoMap;
use Illuminate\Support\Collection;

class DeliveryCoverage
{
    public function covers(PublicDeliveryLocation $delivery, array $point): bool
    {
        return $this->rank(collect([$delivery]), $point)->isNotEmpty();
    }

    public function rank(Collection $deliveries, array $point): Collection
    {
        if (!is_numeric($point['lat'] ?? null) || !is_numeric($point['lng'] ?? null)
            || !is_finite((float) $point['lat']) || !is_finite((float) $point['lng'])
            || abs((float) $point['lat']) > 90 || abs((float) $point['lng']) > 180) return collect();
        return $deliveries->filter(function (PublicDeliveryLocation $delivery) use ($point) {
            /** Explicit demo coverage has no physical radius; address validation still applies. */
            if (DemoMap::isDelivery($delivery)) {
                $delivery->setAttribute('distance_km', null);
                return true;
            }
            $origin = $delivery->origin;
            if (!$delivery->custom_delivery_enabled || !$origin || (int) $origin->organization_id !== (int) $delivery->organization_id
                || !$delivery->delivery_radius_km || !is_numeric($origin->lat) || !is_numeric($origin->lng)
                || abs((float) $origin->lat) > 90 || abs((float) $origin->lng) > 180) return false;
            $distance = self::distance((float) $origin->lat, (float) $origin->lng, $point['lat'], $point['lng']);
            if ($distance > (float) $delivery->delivery_radius_km) return false;
            $delivery->setAttribute('distance_km', $distance);
            return true;
        })->sort(fn ($a, $b) => ($a->distance_km <=> $b->distance_km) ?: ($a->id <=> $b->id));
    }

    public static function distance(float $latA, float $lngA, float $latB, float $lngB): float
    {
        $a = sin(deg2rad($latB - $latA) / 2) ** 2
            + cos(deg2rad($latA)) * cos(deg2rad($latB)) * sin(deg2rad($lngB - $lngA) / 2) ** 2;
        return 6371.0088 * 2 * atan2(sqrt(min(1, max(0, $a))), sqrt(max(0, 1 - $a)));
    }
}
