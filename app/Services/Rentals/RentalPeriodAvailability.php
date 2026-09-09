<?php

namespace App\Services\Rentals;

use App\Models\{Rental, Vehicle, VehicleAssignment, VehicleBlock};
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class RentalPeriodAvailability
{
    /** Il chiamante mantiene il lock sul veicolo fino al salvataggio del periodo. */
    public function assertAvailable(
        Rental $rental,
        CarbonInterface $from,
        CarbonInterface $to,
        string $field = 'extensionReturnAt'
    ): void {
        $vehicle = Vehicle::findOrFail($rental->vehicle_id);
        if (!$vehicle->is_active) {
            $this->fail($field, 'Il veicolo non è attivo.');
        }

        $busy = $this->overlappingRentals($from, $to)
            ->where('vehicle_id', $vehicle->id)
            ->when($rental->exists, fn ($q) => $q->whereKeyNot($rental->id))
            ->lockForUpdate()->first(['id']);
        if ($busy) {
            $this->fail($field, 'Il veicolo è già impegnato nel periodo richiesto.');
        }

        if (VehicleBlock::where('vehicle_id', $vehicle->id)
            ->whereIn('status', ['scheduled', 'active'])
            ->where('start_at', '<', $to)->where('end_at', '>', $from)
            ->lockForUpdate()->first(['id'])) {
            $this->fail($field, 'Il veicolo ha un fermo o una manutenzione nel periodo richiesto.');
        }

        if ($rental->assignment_id) {
            $assignment = VehicleAssignment::lockForUpdate()->find($rental->assignment_id);
            $pickup = $rental->planned_pickup_at ?? $from;
            if (!$assignment
                || (int) $assignment->vehicle_id !== (int) $vehicle->id
                || (int) $assignment->renter_org_id !== (int) $rental->organization_id
                || !in_array($assignment->status, ['scheduled', 'active'], true)
                || $assignment->start_at->gt($pickup)
                || ($assignment->end_at && $assignment->end_at->lt($to))) {
                $this->fail($field, 'L’assegnazione al noleggiatore non copre tutto il periodo richiesto.');
            }
        } elseif ((int) $vehicle->admin_organization_id !== (int) $rental->organization_id) {
            $this->fail($field, 'Il veicolo non risulta assegnato a questo noleggiatore.');
        }

        if (VehicleAssignment::where('vehicle_id', $vehicle->id)
            ->when($rental->assignment_id, fn ($q) => $q->whereKeyNot($rental->assignment_id))
            ->whereIn('status', ['scheduled', 'active'])
            ->where('start_at', '<', $to)
            ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>', $from))
            ->lockForUpdate()->first(['id'])) {
            $this->fail($field, 'Il veicolo è assegnato a un altro noleggiatore nel periodo richiesto.');
        }
    }

    public function overlappingRentals(CarbonInterface $from, CarbonInterface $to): Builder
    {
        return Rental::whereNotIn('status', ['cancelled', 'canceled', 'no_show'])
            ->whereRaw('COALESCE(actual_pickup_at, planned_pickup_at) < ?', [$to])
            ->where(fn ($q) => $q
                ->whereRaw('COALESCE(actual_return_at, planned_return_at) > ?', [$from])
                ->orWhere(fn ($q) => $q->whereNull('actual_return_at')->whereNull('planned_return_at')));
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
