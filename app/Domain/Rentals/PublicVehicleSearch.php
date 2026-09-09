<?php

namespace App\Domain\Rentals;

use App\Domain\Pricing\VehiclePricingService;
use App\Models\PublicRentalOffer;
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

    /** The supplied scope is either published offers or an authorized publisher's preview. */
    public function search(Builder $scope, array $filters): Collection
    {
        $start = CarbonImmutable::parse($filters['pickup_at'], config('app.timezone'));
        $end = CarbonImmutable::parse($filters['return_at'], config('app.timezone'));
        $query = clone $scope;

        if (!empty($filters['city'])) {
            $query->whereHas('location', fn (Builder $q) => $q->whereRaw('LOWER(city) = ?', [mb_strtolower($filters['city'])]));
        }
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
                    ->where('make', 'like', '%'.$word.'%')->orWhere('model', 'like', '%'.$word.'%'));
            }
        });

        $offers = $query->with(['vehicle.adminOrganization', 'organization', 'location'])->get();
        $availableIds = $this->availability->availableOfferIds($offers, $start, $end);
        $available = $offers->whereIn('id', $availableIds)->values();

        // Pricing and budget evaluation happen only after the full availability check.
        $available->load(['pricelist.seasons', 'pricelist.tiers', 'vehicle.media']);
        $results = collect();
        $budget = isset($filters['budget']) ? (int) round((float) $filters['budget'] * 100) : null;

        foreach ($available as $offer) {
            $offer->pricelist->setRelation('vehicle', $offer->vehicle);
            $quote = $this->pricing->quote($offer->pricelist, $start, $end);
            if ($quote['total'] < 0 || ($budget !== null && $quote['total'] > $budget)) {
                continue;
            }
            $results->push($this->present($offer, $quote));
        }

        return $results->sort(function (array $a, array $b) use ($filters) {
            $comparison = $a['total_cents'] <=> $b['total_cents'];
            if (($filters['sort'] ?? 'price_asc') === 'price_desc') {
                $comparison *= -1;
            }
            return $comparison ?: ($a['id'] <=> $b['id']);
        })->values();
    }

    /** Explicit public fields: no plate, VIN, customer details, supplier costs or margins. */
    private function present(PublicRentalOffer $offer, array $quote): array
    {
        $vehicle = $offer->vehicle;
        $photo = $this->photos->forVehicle($vehicle);

        return [
            'id' => (int) $offer->id,
            'title' => trim($vehicle->make.' '.$vehicle->model),
            'year' => $vehicle->year,
            'segment' => $vehicle->segment,
            'seats' => $vehicle->seats,
            'transmission' => $vehicle->transmission_label,
            'fuel' => $vehicle->fuel_type_label,
            'organization' => $offer->organization->name,
            'location' => $offer->location->name,
            'city' => $offer->location->city,
            'address' => $offer->location->address_line,
            'description' => $offer->description,
            'has_photo' => $photo !== null,
            'photo_is_reference' => $photo['is_reference'] ?? false,
            'total_cents' => (int) $quote['total'],
            'days' => (int) $quote['days'],
            'deposit_cents' => (int) $quote['deposit'],
            'km_per_day' => $offer->pricelist->km_included_per_day,
            'extra_km_cents' => (int) $offer->pricelist->extra_km_cents,
            'prices_include_vat' => (bool) $offer->prices_include_vat,
        ];
    }
}
