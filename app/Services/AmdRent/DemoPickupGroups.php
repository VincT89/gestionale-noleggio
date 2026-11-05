<?php

namespace App\Services\AmdRent;

use App\Domain\Rentals\PublicPickupDirectory;
use App\Models\{Organization, PublicDeliveryLocation, PublicPickupPlace};
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DemoPickupGroups
{
    public const ADDRESS = 'Punto di incontro dimostrativo: nessun ritiro reale.';

    /** Reuse the demo ownership checks without enabling or changing its existing map coverage. */
    public function preview(int $renter): array
    {
        $demo = app(DemoMap::class)->preview($renter);
        $deliveries = PublicDeliveryLocation::where('organization_id', $renter)
            ->with(['place', 'location', 'organization'])->orderBy('id')->get();
        $anchor = $deliveries->first(fn ($delivery) => DemoMap::isDelivery($delivery))
            ?? $deliveries->firstWhere('is_active', true);
        $mapEnabled = DemoMap::isDelivery($anchor);
        $plan = [
            'version' => 1, 'renter' => $demo['renter'], 'database' => $demo['database'], 'host' => $demo['host'],
            'demo_fingerprint' => $demo['fingerprint'], 'map_enabled' => $mapEnabled,
            'existing_places' => $deliveries->map(fn ($delivery) => $delivery->place?->getAttributes())->all(),
            'areas' => [],
        ];
        foreach (PublicPickupDirectory::BARI_GROUPS as $key => $group) {
            $covered = $deliveries->first(fn ($delivery) => $delivery->is_active && $delivery->place
                && PublicPickupDirectory::groupForPlace($delivery->place) === $key
                && trim((string) $delivery->place->address_line) !== '');
            if ($covered) {
                $plan['areas'][] = ['group' => $key, 'label' => $group['name'], 'action' => 'keep',
                    'place' => $covered->place->getAttributes(), 'delivery_id' => $covered->id];
                continue;
            }

            /** Each placeholder is explicitly demonstrative and distinct from every real meeting point. */
            $attributes = ['name' => 'DEMO '.$renter.' - '.$group['name'], 'city' => 'Bari', 'kind' => $group['kind'],
                'address_line' => self::ADDRESS, 'country_code' => 'IT'];
            $attributes['identity_key'] = PublicPickupPlace::identity($attributes);
            $place = PublicPickupPlace::where('identity_key', $attributes['identity_key'])->first();
            if ($place && ($place->only(array_keys($attributes)) !== $attributes
                || $place->deliveries()->where('organization_id', '!=', $renter)->exists())) {
                throw new RuntimeException('Un punto demo risulta modificato o condiviso con un altro noleggiatore: nessuna modifica. Verificare i luoghi serviti.');
            }
            $delivery = $place ? $deliveries->firstWhere('public_pickup_place_id', $place->id) : null;
            $locationId = $delivery?->location_id ?? $anchor->location_id;
            $plan['areas'][] = ['group' => $key, 'label' => $group['name'],
                'action' => $delivery ? 'enable' : 'create', 'place' => $attributes, 'place_id' => $place?->id,
                'delivery_id' => $delivery?->id,
                'delivery' => $delivery ? ['is_active' => true] : [
                    'organization_id' => $renter, 'location_id' => $locationId, 'is_active' => true,
                    'custom_delivery_enabled' => $mapEnabled, 'delivery_area' => $mapEnabled ? DemoMap::NOTICE : null,
                    'delivery_origin_location_id' => $mapEnabled ? $locationId : null,
                    'delivery_radius_km' => null, 'custom_delivery_fee_cents' => $mapEnabled ? 0 : null,
                ],
            ];
        }
        $plan['already_configured'] = collect($plan['areas'])->every(fn ($area) => $area['action'] === 'keep');
        $plan['fingerprint'] = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $plan;
    }

    /** Only demo service links and new labelled places are written; the fleet and all existing places remain intact. */
    public function apply(int $renter, string $fingerprint): int
    {
        return DB::transaction(function () use ($renter, $fingerprint) {
            Organization::whereKey($renter)->lockForUpdate()->firstOrFail();
            PublicDeliveryLocation::where('organization_id', $renter)->lockForUpdate()->get();
            $plan = $this->preview($renter);
            if (!preg_match('/^[a-f0-9]{64}$/', $fingerprint) || !hash_equals($plan['fingerprint'], $fingerprint)) {
                throw new RuntimeException('Conferma assente o configurazione cambiata: ripetere l’anteprima delle aree demo.');
            }
            $count = 0;
            foreach ($plan['areas'] as $area) {
                if ($area['action'] === 'keep') continue;
                $now = now();
                if ($area['delivery_id']) {
                    DB::table('public_delivery_locations')->where('organization_id', $renter)
                        ->where('id', $area['delivery_id'])->update($area['delivery'] + ['updated_at' => $now]);
                } else {
                    $placeId = $area['place_id'] ?? DB::table('public_pickup_places')
                        ->insertGetId($area['place'] + ['created_at' => $now, 'updated_at' => $now]);
                    DB::table('public_delivery_locations')->insert($area['delivery'] + [
                        'public_pickup_place_id' => $placeId, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
                $count++;
            }
            return $count;
        });
    }
}
