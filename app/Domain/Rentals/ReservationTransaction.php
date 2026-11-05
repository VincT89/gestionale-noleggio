<?php

namespace App\Domain\Rentals;

use App\Models\{Organization, Vehicle};
use Illuminate\Support\Facades\DB;

class ReservationTransaction
{
    /** Used only by public booking confirmation and the internal rental wizard. */
    public function run(int $organizationId, array $vehicleIds, callable $save): mixed
    {
        return DB::transaction(function () use ($organizationId, $vehicleIds, $save) {
            Organization::whereKey($organizationId)->lockForUpdate()->firstOrFail();
            Vehicle::withTrashed()->whereIn('id', array_filter($vehicleIds))->orderBy('id')->lockForUpdate()->get();
            return $save();
        }, 3);
    }
}
