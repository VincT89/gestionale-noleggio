<?php

namespace App\Domain\Rentals;

use App\Domain\Pricing\VehiclePricingService;
use App\Models\VehiclePricelist;
use App\Models\PublicDeliveryLocation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PublicVehicleSearch
{
    public function __construct(
        private VehicleAvailabilityService $availability,
        private VehiclePricingService $pricing,
        private PublicVehiclePhoto $photos,
    ) {}

    /** The supplied scope contains eligible active pricelists, optionally restricted to a renter. */
    public function search(Builder $scope, array $filters): Collection
    {
        $start = CarbonImmutable::parse($filters['pickup_at'], config('app.timezone'));
        $end = CarbonImmutable::parse($filters['return_at'], config('app.timezone'));
        $query = clone $scope;

        $deliveries = PublicDeliveryLocation::available()->with(['place', 'location'])
            ->when(!empty($filters['place_id']), fn ($q) => $q->where('public_pickup_place_id', $filters['place_id']))
            ->when(empty($filters['place_id']) && !empty($filters['city']), fn ($q) => $q
                ->whereHas('place', fn ($place) => $place->whereRaw('LOWER(TRIM(city)) = ?', [mb_strtolower(trim($filters['city']))])))
            ->orderBy('id')->get()->unique('organization_id')->keyBy('organization_id');
        $query->whereIn('renter_org_id', $deliveries->keys());
        if (!empty($filters['supplier'])) $query->where('renter_org_id', $filters['supplier']);
        $query->whereHas('vehicle', function (Builder $q) use ($filters) {
            foreach (['transmission', 'fuel_type', 'segment'] as $field) {
                if (!empty($filters[$field])) {
                    if ($field === 'segment') {
                        $q->whereRaw('LOWER(segment) = ?', [mb_strtolower($filters[$field])]);
                    } else {
                        $q->where($field, $filters[$field]);
                    }
                }
            }
            if (!empty($filters['seats'])) {
                $q->where('seats', '>=', (int) $filters['seats']);
            }
            foreach (preg_split('/\s+/', trim($filters['q'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                $q->where(fn (Builder $part) => $part
                    ->where('make', 'like', '%'.$word.'%')->orWhere('model', 'like', '%'.$word.'%')
                    ->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', '%'.$word.'%')));
            }
        });

        $pricelists = $query->with(['vehicle.adminOrganization', 'renter'])->get();
        $availableIds = $this->availability->availablePricelistIds($pricelists, $start, $end);
        $available = $pricelists->whereIn('id', $availableIds)->values();

        // Pricing and budget evaluation happen only after the full availability check.
        $available->load(['seasons', 'tiers', 'vehicle.media', 'vehicle.product']);
        $results = collect();
        $budget = isset($filters['budget']) ? (int) round((float) $filters['budget'] * 100) : null;

        foreach ($available as $pricelist) {
            $quote = $this->pricing->quote($pricelist, $start, $end);
            if ($quote['total'] < 0 || ($budget !== null && $quote['total'] > $budget)) {
                continue;
            }
            $results->push($this->present($pricelist, $quote, $deliveries->get($pricelist->renter_org_id)));
        }

        return $this->sortByPrice($results, $filters['sort'] ?? 'price_asc');
    }

    /** Group only search results; checkout continues to resolve a concrete vehicle and pricelist. */
    public function cheapestPerProduct(Collection $offers, string $sort = 'price_asc'): Collection
    {
        // Pick the cheapest first, regardless of the customer's final display ordering.
        $cheapest = $this->sortByPrice($offers, 'price_asc')->unique(fn (array $offer) =>
            $offer['product_id'] !== null ? 'product:'.$offer['product_id'] : 'offer:'.$offer['id']);

        return $this->sortByPrice($cheapest, $sort);
    }

    private function sortByPrice(Collection $results, string $sort): Collection
    {
        return $results->sort(function (array $a, array $b) use ($sort) {
            $comparison = $a['total_cents'] <=> $b['total_cents'];
            if ($sort === 'price_desc') {
                $comparison *= -1;
            }
            return $comparison ?: ($a['id'] <=> $b['id']);
        })->values();
    }

    /** Explicit public fields: no plate, VIN, customer details, supplier costs or margins. */
    private function present(VehiclePricelist $pricelist, array $quote, PublicDeliveryLocation $delivery): array
    {
        $vehicle = $pricelist->vehicle;
        $photo = $this->photos->forVehicle($vehicle);

        return [
            'id' => (int) $pricelist->id,
            'vehicle_id' => (int) $pricelist->vehicle_id,
            'title' => trim($vehicle->make.' '.$vehicle->model),
            'year' => $vehicle->year,
            'segment' => $vehicle->segment,
            'seats' => $vehicle->seats,
            'transmission' => $vehicle->transmission_label,
            'fuel' => $vehicle->fuel_type_label,
            'organization' => $pricelist->renter->name,
            'location' => $delivery->place->name,
            'city' => $delivery->place->city,
            'address' => $delivery->place->address_line,
            // Pricelist notes and product descriptions are internal management data.
            'description' => null,
            'has_photo' => $photo !== null,
            'photo_is_reference' => $photo['is_reference'] ?? false,
            'total_cents' => (int) $quote['total'],
            'days' => (int) $quote['days'],
            'deposit_cents' => (int) $quote['deposit'],
            'km_per_day' => $pricelist->km_included_per_day,
            'extra_km_cents' => (int) $pricelist->extra_km_cents,
            'prices_include_vat' => true,
            'place_id' => (int) $delivery->public_pickup_place_id,
            'pickup_location_id' => (int) $delivery->location_id,
            'supplier_id' => (int) $pricelist->renter_org_id,
            'custom_delivery_enabled' => (bool) $delivery->custom_delivery_enabled,
            'delivery_area' => $delivery->delivery_area,
            'product_id' => $vehicle->product ? (int) $vehicle->product->id : null,
            'product_name' => $vehicle->product?->name,
        ];
    }
}
