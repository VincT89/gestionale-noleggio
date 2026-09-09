<?php

namespace App\Domain\Rentals;

use App\Models\PublicRentalOffer;
use App\Models\Rental;
use App\Models\VehicleAssignment;
use App\Models\VehicleBlock;
use App\Models\VehicleState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class VehicleAvailabilityService
{
    /**
     * Check the complete period before any pricing. Intervals are [start, end):
     * a return exactly at the next pickup is allowed, as in CreateWizard.
     * Assignments determine who may rent a vehicle; they are not customer rentals.
     *
     * @param Collection<int, PublicRentalOffer> $offers
     * @return list<int>
     */
    public function availableOfferIds(Collection $offers, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($end <= $start) {
            throw new InvalidArgumentException('La riconsegna deve essere successiva al ritiro.');
        }
        if ($offers->isEmpty()) {
            return [];
        }

        $connection = $offers->first()->getConnectionName();
        $vehicleIds = $offers->pluck('vehicle_id')->unique()->values();

        $rentals = Rental::on($connection)->whereIn('vehicle_id', $vehicleIds)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->where(function ($q) use ($start, $end) {
                // A vehicle still out cannot be advertised using an expired planned return.
                $q->where(fn ($out) => $out->whereIn('status', ['in_use', 'checked_out'])
                    ->whereNull('actual_return_at')
                    ->where(fn ($due) => $due->whereNull('planned_return_at')
                        ->orWhere('planned_return_at', '<=', CarbonImmutable::now(config('app.timezone')))))
                    ->orWhere(function ($period) use ($start, $end) {
                        $period->whereRaw('COALESCE(actual_pickup_at, planned_pickup_at) < ?', [$end])
                            ->where(fn ($until) => $until
                                ->whereRaw('COALESCE(actual_return_at, planned_return_at) > ?', [$start])
                                ->orWhereRaw('COALESCE(actual_return_at, planned_return_at) IS NULL'));
                    });
            })->pluck('vehicle_id');

        $blocks = VehicleBlock::on($connection)->whereIn('vehicle_id', $vehicleIds)
            ->whereIn('status', ['active', 'scheduled'])->where('start_at', '<', $end)
            ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>', $start))
            ->pluck('vehicle_id');

        $states = VehicleState::on($connection)->whereIn('vehicle_id', $vehicleIds)
            ->whereIn('state', ['maintenance', 'blocked', 'out_of_service'])
            ->where('started_at', '<', $end)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $start))
            ->pluck('vehicle_id');

        $busy = array_fill_keys($rentals->merge($blocks)->merge($states)->all(), true);
        $assignments = VehicleAssignment::on($connection)->whereIn('vehicle_id', $vehicleIds)
            ->whereIn('status', ['active', 'scheduled'])->where('start_at', '<', $end)
            ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>', $start))
            ->orderBy('start_at')->get()->groupBy('vehicle_id');

        return $offers->filter(function (PublicRentalOffer $offer) use ($busy, $assignments, $start, $end) {
            $vehicle = $offer->vehicle;
            if (!$vehicle || !$vehicle->is_active || isset($busy[$offer->vehicle_id])
                || !$offer->organization?->is_active || !$vehicle->adminOrganization?->is_active) {
                return false;
            }

            $periods = $assignments->get($offer->vehicle_id, collect());
            if ($periods->contains(fn ($period) => (int) $period->renter_org_id !== (int) $offer->organization_id)) {
                return false;
            }
            if ((int) $vehicle->admin_organization_id === (int) $offer->organization_id) {
                return true;
            }

            // Merge adjacent assignments of the same renter, rejecting every uncovered gap.
            $coveredUntil = $start;
            foreach ($periods as $period) {
                if ($period->start_at > $coveredUntil) {
                    return false;
                }
                if ($period->end_at === null || $period->end_at >= $end) {
                    return true;
                }
                if ($period->end_at > $coveredUntil) {
                    $coveredUntil = CarbonImmutable::instance($period->end_at);
                }
            }

            return false;
        })->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }
}
