<?php

namespace App\Services\AmdRent;

use App\Models\{Organization, PublicDeliveryLocation, Vehicle, VehiclePricelist};
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DemoMap
{
    public const NOTICE = 'DEMO AMD Rent: copertura simulata per qualsiasi indirizzo riconosciuto. Consegna e ritiro dimostrativi gratuiti. Prenotazioni disabilitate.';

    public static function isVehicle(Vehicle $vehicle, int $renter): bool
    {
        return str_starts_with((string) $vehicle->notes, DemoFleet::marker($renter, 'vehicle', ''));
    }

    /** The simulation requires both its explicit setting and a separately owned demo location. */
    public static function isDelivery(PublicDeliveryLocation $delivery): bool
    {
        $location = $delivery->location;
        $organization = $delivery->organization;
        return $delivery->is_active && $delivery->custom_delivery_enabled
            && $organization && $organization->is_active && $organization->type === 'renter'
            && preg_match('/^DEMO\b/iu', $organization->name)
            && $delivery->delivery_area === self::NOTICE
            && $location && (int) $location->organization_id === (int) $delivery->organization_id
            && (int) $delivery->delivery_origin_location_id === (int) $location->id
            && str_starts_with((string) $location->notes, DemoFleet::marker($delivery->organization_id, 'location', ''));
    }

    public function preview(int $renter): array
    {
        $organization = Organization::find($renter);
        if (!$organization || !$organization->is_active || $organization->type !== 'renter'
            || !preg_match('/^DEMO\b/iu', $organization->name)) {
            throw new RuntimeException('La copertura simulata è riservata al noleggiatore DEMO attivo.');
        }
        $prices = VehiclePricelist::where('renter_org_id', $renter)->with('vehicle')->orderBy('id')->get();
        if ($prices->isEmpty() || $prices->contains(fn ($price) => !$price->vehicle || !self::isVehicle($price->vehicle, $renter))) {
            throw new RuntimeException('Servono le copie demo già create; tutti i listini del destinatario devono riferirsi alle sole copie.');
        }
        $deliveries = PublicDeliveryLocation::where('organization_id', $renter)->with('location')->orderBy('id')->get();
        if (!$deliveries->contains('is_active', true)) {
            throw new RuntimeException('Serve almeno un luogo demo attivo per abilitare la ricerca sulla mappa.');
        }
        if ($deliveries->isEmpty() || $deliveries->contains(fn ($delivery) => !$delivery->location
            || (int) $delivery->location->organization_id !== $renter
            || !str_starts_with((string) $delivery->location->notes, DemoFleet::marker($renter, 'location', '')))) {
            throw new RuntimeException('I luoghi da configurare devono appartenere alle copie demo, con collegamenti coerenti.');
        }
        $plan = [
            'version' => 1, 'renter' => $organization->only(['id', 'name', 'is_active', 'type']),
            'database' => DB::connection()->getDatabaseName(),
            'host' => DB::connection()->getConfig('host'),
            'notice' => self::NOTICE,
            'vehicles' => $prices->map(fn ($price) => [$price->id, $price->vehicle_id, $price->vehicle->notes])->all(),
            'deliveries' => $deliveries->map(fn ($delivery) => [
                'before' => $delivery->getAttributes(),
                'location' => $delivery->location->only(['id', 'organization_id', 'name', 'notes']),
                'after' => ['custom_delivery_enabled' => true, 'delivery_area' => self::NOTICE,
                    'delivery_origin_location_id' => $delivery->location_id,
                    'delivery_radius_km' => null, 'custom_delivery_fee_cents' => 0],
            ])->all(),
        ];
        $plan['already_configured'] = $deliveries->every(fn ($delivery) => $delivery->delivery_area === self::NOTICE
            && $delivery->custom_delivery_enabled && (int) $delivery->delivery_origin_location_id === (int) $delivery->location_id
            && $delivery->custom_delivery_fee_cents === 0 && $delivery->delivery_radius_km === null);
        $plan['fingerprint'] = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $plan;
    }

    /** Update only the demo's service settings; leave vehicles, original locations and real coverage unchanged. */
    public function apply(int $renter, string $fingerprint): int
    {
        return DB::transaction(function () use ($renter, $fingerprint) {
            Organization::whereKey($renter)->lockForUpdate()->firstOrFail();
            PublicDeliveryLocation::where('organization_id', $renter)->lockForUpdate()->get();
            $plan = $this->preview($renter);
            if (!preg_match('/^[a-f0-9]{64}$/', $fingerprint) || !hash_equals($plan['fingerprint'], $fingerprint)) {
                throw new RuntimeException('Conferma assente o configurazione cambiata: ripetere l’anteprima della copertura demo.');
            }
            if ($plan['already_configured']) return 0;
            foreach ($plan['deliveries'] as $delivery) {
                DB::table('public_delivery_locations')->where('organization_id', $renter)
                    ->where('id', $delivery['before']['id'])->update($delivery['after'] + ['updated_at' => now()]);
            }
            return count($plan['deliveries']);
        });
    }
}
