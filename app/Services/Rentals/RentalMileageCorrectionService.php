<?php

namespace App\Services\Rentals;

use App\Models\{Rental, RentalChecklist, RentalMileageCorrection, User, Vehicle, VehicleMileageLog};
use Illuminate\Support\Facades\{DB, Gate, Validator};
use Illuminate\Validation\ValidationException;

class RentalMileageCorrectionService
{
    public function context(Rental $rental): array
    {
        $rental->load(['pickupChecklist', 'returnChecklist', 'contractSnapshot', 'vehicle']);
        $out = $rental->pickupChecklist?->mileage ?? $rental->mileage_out;
        $in = $rental->returnChecklist?->mileage ?? $rental->mileage_in;
        $last = $in ?? $out;
        $latestChecklist = RentalChecklist::query()->whereHas('rental', fn ($q) => $q->where('vehicle_id', $rental->vehicle_id))
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        $latestRentalId = Rental::where('vehicle_id', $rental->vehicle_id)
            ->where(fn ($q) => $q->whereNotNull('mileage_in')->orWhereNotNull('mileage_out'))
            ->orderByRaw('COALESCE(actual_return_at, actual_pickup_at, created_at) DESC')->orderByDesc('id')->value('id');
        $vehicle = $rental->vehicle;
        $canSync = $vehicle && $last !== null && $vehicle->mileage_current === $last && (int) $latestRentalId === $rental->id
            && (!$latestChecklist || $latestChecklist->rental_id === $rental->id);
        $kmExtra = (int) $rental->distance_overage_km;
        $rate = $rental->contractSnapshot?->pricing_snapshot['extra_km_cents'] ?? null;
        $state = [
            'out' => $out, 'in' => $in, 'rental_out' => $rental->mileage_out, 'rental_in' => $rental->mileage_in,
            'status' => $rental->status, 'vehicle_id' => $rental->vehicle_id,
            'vehicle' => $vehicle?->mileage_current, 'can_sync_vehicle' => (bool) $canSync,
            'latest_reading_id' => $latestChecklist?->id, 'latest_rental_id' => $latestRentalId,
            'checklists' => collect([$rental->pickupChecklist, $rental->returnChecklist])->filter()->map(fn ($checklist) => [
                'id' => $checklist->id, 'type' => $checklist->type, 'mileage' => $checklist->mileage,
                'locked_at' => $checklist->locked_at?->toIso8601String(), 'signed_media_id' => $checklist->signed_media_id,
                'last_pdf_media_id' => $checklist->last_pdf_media_id,
            ])->values()->all(),
            'pricing' => $rental->contractSnapshot?->pricing_snapshot,
            'extra_km' => $kmExtra,
            'extra_cents' => $kmExtra === 0 ? 0 : (is_numeric($rate) && $rate > 0 ? $kmExtra * (int) $rate : null),
        ];
        $state['version'] = hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
        return $state;
    }

    public function correct(int $rentalId, array $input, string $expectedVersion, User $actor): ?RentalMileageCorrection
    {
        abort_unless($actor->hasRole('admin'), 403);
        if (is_string($input['reason'] ?? null)) $input['reason'] = trim($input['reason']);
        $data = Validator::make($input, [
            'out' => ['nullable', 'integer', 'min:0', 'max:2000000'],
            'in' => ['nullable', 'integer', 'min:0', 'max:2000000'],
            'sync_vehicle' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            '*.integer' => 'Inserisci un numero intero di chilometri.',
            '*.min' => 'Controlla il valore inserito.', '*.max' => 'Il valore inserito supera il limite consentito.',
            'reason.required' => 'Indica il motivo della correzione.',
        ])->validate();
        foreach (['out', 'in'] as $field) $data[$field] = isset($data[$field]) && $data[$field] !== '' ? (int) $data[$field] : null;

        return DB::transaction(function () use ($rentalId, $data, $expectedVersion, $actor) {
            $rental = Rental::lockForUpdate()->findOrFail($rentalId);
            Gate::forUser($actor)->authorize('update', $rental);
            $checklists = $rental->checklists()->lockForUpdate()->get();
            $vehicle = Vehicle::lockForUpdate()->findOrFail($rental->vehicle_id);
            $before = $this->context($rental);
            if (!hash_equals($before['version'], $expectedVersion)) {
                throw ValidationException::withMessages(['correction' => 'I dati sono cambiati nel frattempo. Chiudi e riapri la correzione.']);
            }
            foreach (['out', 'in'] as $field) {
                if (($before[$field] === null) !== ($data[$field] === null)) {
                    throw ValidationException::withMessages([$field => 'Correggi una rilevazione già presente, senza aggiungerla o eliminarla.']);
                }
            }
            if ($data['in'] !== null && $data['out'] !== null && $data['in'] < $data['out']) {
                throw ValidationException::withMessages(['in' => 'I km al rientro devono essere almeno quelli in uscita. Correggi anche la rilevazione di uscita se è errata.']);
            }
            if ($data['sync_vehicle'] && !$before['can_sync_vehicle']) {
                throw ValidationException::withMessages(['sync_vehicle' => 'Il veicolo ha una rilevazione successiva o diversa. Correggi i km attuali dalla scheda veicolo.']);
            }
            if ($data['out'] === $before['out'] && $data['in'] === $before['in']
                && $data['out'] === $before['rental_out'] && $data['in'] === $before['rental_in']) return null;

            $rental->update(['mileage_out' => $data['out'], 'mileage_in' => $data['in']]);
            foreach ($checklists as $checklist) {
                $field = $checklist->type === 'pickup' ? 'out' : 'in';
                if (in_array($checklist->type, ['pickup', 'return'], true) && $checklist->mileage !== $data[$field]) {
                    // The signed original and its lock remain intact; the correction has its own document.
                    $checklist->update(['mileage' => $data[$field]]);
                }
            }
            if ($data['sync_vehicle']) {
                $new = $data['in'] ?? $data['out'];
                if ($new !== null && $vehicle->mileage_current !== $new) {
                    VehicleMileageLog::create([
                        'vehicle_id' => $vehicle->id, 'mileage_old' => $vehicle->mileage_current, 'mileage_new' => $new,
                        'changed_by' => $actor->id, 'source' => 'manual', 'notes' => 'Correzione noleggio #'.$rental->display_number,
                        'changed_at' => now(),
                    ]);
                    $vehicle->update(['mileage_current' => $new]);
                }
            }
            $after = $this->context($rental->fresh());
            $paymentIds = $rental->charges()->paid()->whereIn('kind', ['distance_overage', 'base+distance_overage'])->pluck('id')->all();
            return RentalMileageCorrection::create([
                'rental_id' => $rental->id, 'corrected_by' => $actor->id,
                'properties' => [
                    'before' => $before, 'after' => $after, 'reason' => $data['reason'],
                    'contract_number' => $rental->display_number_label, 'rental_id' => $rental->id,
                    'plate' => $vehicle->plate, 'actor_name' => $actor->name,
                    'vehicle_updated' => $before['vehicle'] !== $after['vehicle'],
                    'payment_review' => $paymentIds !== [] && $before['extra_km'] !== $after['extra_km'],
                    'recorded_payment_ids' => $paymentIds,
                ],
            ]);
        }, 3);
    }
}
