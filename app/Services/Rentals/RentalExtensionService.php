<?php

namespace App\Services\Rentals;

use App\Models\{Customer, Rental, RentalExtension, User, Vehicle};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{DB, Gate, Validator};
use Illuminate\Validation\ValidationException;

class RentalExtensionService
{
    public function __construct(private RentalPeriodAvailability $availability) {}

    public function extend(Rental $rental, array $data, User $actor): RentalExtension
    {
        Gate::forUser($actor)->authorize('update', $rental);
        $data['extensionAmount'] = ($data['extensionAmount'] ?? '') === '' ? null : $data['extensionAmount'];
        $data = Validator::make($data, [
            'extensionReturnAt' => ['required', 'date_format:Y-m-d\TH:i'],
            'extensionAmount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'extensionNotes' => ['nullable', 'string', 'max:2000'],
            'extensionRequestKey' => ['required', 'uuid'],
            'extensionExpectedReturnAt' => ['required', 'date_format:Y-m-d H:i:s'],
            'extensionExpectedAmount' => ['nullable', 'numeric'],
            'extensionExpectedOverride' => ['nullable', 'numeric'],
        ], [
            'extensionReturnAt.*' => 'Inserisci la nuova data e ora di rientro.',
            'extensionAmount.*' => 'Inserisci il costo aggiuntivo concordato, anche zero, con al massimo due decimali.',
            'extensionNotes.max' => 'Le note possono contenere al massimo 2000 caratteri.',
        ])->validate();

        return DB::transaction(function () use ($rental, $data, $actor) {
            Vehicle::whereKey($rental->vehicle_id)->lockForUpdate()->firstOrFail();
            $rental = Rental::lockForUpdate()->findOrFail($rental->id);
            Gate::forUser($actor)->authorize('update', $rental);
            $to = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['extensionReturnAt'], config('app.timezone'))->second(0);
            $amount = $this->money($data['extensionAmount']);
            $notes = trim($data['extensionNotes'] ?? '') ?: null;

            $existing = $rental->extensions()->where('request_key', $data['extensionRequestKey'])->first();
            if ($existing) {
                if ((int) $existing->created_by !== (int) $actor->id
                    || !$existing->new_return_at->equalTo($to)
                    || $existing->additional_amount !== $amount || $existing->notes !== $notes) {
                    $this->fail('La richiesta è già stata utilizzata per una proroga diversa. Riapri il modulo.');
                }
                return $existing;
            }

            if (!in_array($rental->status, ['reserved', 'in_use'], true) || $rental->actual_return_at || $rental->closed_at) {
                $this->fail('Puoi prorogare solo un noleggio prenotato o in uso, prima del rientro.');
            }
            if ($rental->extensions()->whereNull('additional_amount')->exists()) {
                $this->fail('Definisci prima il costo della proroga precedente.');
            }
            if (!$rental->planned_return_at || !$rental->planned_pickup_at
                || $rental->planned_return_at->format('Y-m-d H:i:s') !== $data['extensionExpectedReturnAt']
                || $this->money($rental->amount) !== $this->money($data['extensionExpectedAmount'] ?? null)
                || $this->money($rental->final_amount_override) !== $this->money($data['extensionExpectedOverride'] ?? null)) {
                $this->fail('Il contratto è cambiato mentre il modulo era aperto. Riapri la proroga e verifica i dati aggiornati.');
            }
            if (!$to->gt($rental->planned_return_at) || !$to->gt(now()) || !$to->gt($rental->planned_pickup_at)) {
                $this->fail('La nuova riconsegna deve essere successiva a quella attuale e alla data e ora di oggi.');
            }
            if ($rental->amount === null && $rental->final_amount_override === null) {
                throw ValidationException::withMessages(['extensionAmount' => 'Il contratto non ha un importo iniziale: verificalo prima di prorogare.']);
            }

            $from = $rental->planned_return_at;
            $this->availability->assertAvailable($rental, $from, $to);
            $driverIds = array_filter([$rental->customer_id, $rental->second_driver_id]);
            $drivers = Customer::whereIn('id', $driverIds)->orderBy('id')->lockForUpdate()->get();
            foreach ($drivers as $driver) {
                if ($driver->driver_license_expires_at && $driver->driver_license_expires_at->copy()->endOfDay()->lt($to)) {
                    $this->fail('La patente di uno dei conducenti scade prima della nuova riconsegna.');
                }
                if ($this->availability->overlappingRentals($from, $to)->whereKeyNot($rental->id)
                    ->where(fn ($q) => $q->where('customer_id', $driver->id)->orWhere('second_driver_id', $driver->id))
                    ->lockForUpdate()->first(['id'])) {
                    $this->fail('Uno dei conducenti ha un altro noleggio nel periodo richiesto.');
                }
            }

            $snapshot = $rental->contractSnapshot()->lockForUpdate()->first();
            $before = $snapshot?->pricing_snapshot;
            if (!is_array($before)) {
                $media = $rental->getMedia('signatures')->sortByDesc('id')->first()
                    ?? $rental->getMedia('contract')->sortByDesc('id')->first();
                $before = $media?->getCustomProperty('pricing_snapshot');
            }
            $before = is_array($before) ? $before : null;
            $pricing = $before ?? [
                'currency' => 'EUR',
                'tariff_total_cents' => $this->cents($rental->amount ?? $rental->final_amount_override),
                'km_daily_limit' => null, 'extra_km_cents' => null, 'deposit_cents' => null,
                'second_driver_daily_cents' => 0,
            ];
            $oldDays = (int) ($pricing['days'] ?? $this->days($rental->planned_pickup_at, $from));
            $pricing['second_driver_total_cents'] = (int) ($pricing['second_driver_total_cents']
                ?? ($rental->second_driver_id ? (int) ($pricing['second_driver_daily_cents'] ?? 0) * max(1, $oldDays) : 0));
            $pricing['days'] = $this->days($rental->planned_pickup_at, $to);
            $pricing['tariff_total_cents'] = (int) ($pricing['tariff_total_cents']
                ?? $this->cents($rental->amount ?? $rental->final_amount_override)) + $this->cents($amount);
            $newAmount = $this->cents($rental->amount ?? $rental->final_amount_override) + $this->cents($amount);
            $newOverride = $rental->final_amount_override === null
                ? null : $this->cents($rental->final_amount_override) + $this->cents($amount);
            if ($newAmount > 9999999999 || ($newOverride ?? 0) > 9999999999) {
                throw ValidationException::withMessages(['extensionAmount' => 'Il nuovo totale supera l’importo massimo consentito.']);
            }
            $pricing['tariff_override_cents'] = $newOverride;

            $extension = $rental->extensions()->create([
                'created_by' => $actor->id,
                'request_key' => $data['extensionRequestKey'],
                'previous_return_at' => $from,
                'new_return_at' => $to,
                'additional_amount' => $amount,
                'previous_amount' => $rental->amount,
                'new_amount' => $this->money($newAmount / 100),
                'previous_override' => $rental->final_amount_override,
                'new_override' => $newOverride === null ? null : $this->money($newOverride / 100),
                'previous_pricing' => $before,
                'new_pricing' => $pricing,
                'notes' => $notes,
            ]);
            $rental->contractSnapshot()->updateOrCreate([], [
                'pricing_snapshot' => $pricing,
                'created_by_user_id' => $snapshot?->created_by_user_id ?? $actor->id,
            ]);
            $rental->update([
                'planned_return_at' => $to,
                'amount' => $extension->new_amount,
                'final_amount_override' => $extension->new_override,
            ]);

            return $extension;
        }, 3);
    }

    public function defineAmount(Rental $rental, int $extensionId, array $data, User $actor): RentalExtension
    {
        $data = Validator::make($data, [
            'extensionAmount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'extensionExpectedReturnAt' => ['required', 'date_format:Y-m-d H:i:s'],
            'extensionExpectedAmount' => ['nullable', 'numeric'],
            'extensionExpectedOverride' => ['nullable', 'numeric'],
        ], ['extensionAmount.*' => 'Inserisci il costo concordato, anche zero, con al massimo due decimali.'])->validate();

        return DB::transaction(function () use ($rental, $extensionId, $data, $actor) {
            $rental = Rental::lockForUpdate()->findOrFail($rental->id);
            Gate::forUser($actor)->authorize('update', $rental);
            $extension = $rental->extensions()->lockForUpdate()->findOrFail($extensionId);
            $amount = $this->money($data['extensionAmount']);
            if ($extension->additional_amount !== null) {
                if ($extension->additional_amount === $amount) {
                    return $extension;
                }
                throw ValidationException::withMessages(['extensionAmount' => 'Il costo della proroga è già stato definito. Ricarica il contratto.']);
            }
            if ($rental->planned_return_at->format('Y-m-d H:i:s') !== $data['extensionExpectedReturnAt']
                || $this->money($rental->amount) !== $this->money($data['extensionExpectedAmount'] ?? null)
                || $this->money($rental->final_amount_override) !== $this->money($data['extensionExpectedOverride'] ?? null)) {
                throw ValidationException::withMessages(['extensionAmount' => 'Il contratto è cambiato. Riapri il modulo per verificare l’importo aggiornato.']);
            }
            $snapshot = $rental->contractSnapshot()->lockForUpdate()->firstOrFail();
            $pricing = $snapshot->pricing_snapshot;
            $newAmount = $this->cents($rental->amount) + $this->cents($amount);
            $newOverride = $rental->final_amount_override === null
                ? null : $this->cents($rental->final_amount_override) + $this->cents($amount);
            if ($newAmount > 9999999999 || ($newOverride ?? 0) > 9999999999) {
                throw ValidationException::withMessages(['extensionAmount' => 'Il nuovo totale supera l’importo massimo consentito.']);
            }
            $pricing['tariff_total_cents'] += $this->cents($amount);
            $pricing['tariff_override_cents'] = $newOverride;
            $snapshot->update(['pricing_snapshot' => $pricing]);
            $rental->update(['amount' => $this->money($newAmount / 100),
                'final_amount_override' => $newOverride === null ? null : $this->money($newOverride / 100)]);
            $extension->update(['additional_amount' => $amount, 'new_amount' => $rental->amount,
                'new_override' => $rental->final_amount_override, 'new_pricing' => $pricing]);
            activity('rental_extensions')->performedOn($rental)->causedBy($actor)->event('extension_price_defined')
                ->withProperties(['extension_id' => $extension->id, 'additional_amount' => $amount])
                ->log('Definito il costo concordato della proroga.');
            return $extension;
        }, 3);
    }

    private function days(\DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return max(1, (int) ceil(($to->getTimestamp() - $from->getTimestamp()) / 86400));
    }

    private function cents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function money(mixed $amount): ?string
    {
        return $amount === null ? null : number_format((float) $amount, 2, '.', '');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['extensionReturnAt' => $message]);
    }
}
