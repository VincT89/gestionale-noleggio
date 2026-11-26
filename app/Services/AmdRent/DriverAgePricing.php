<?php

namespace App\Services\AmdRent;

use App\Models\VehiclePricelist;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class DriverAgePricing
{
    public const BANDS = ['18_21' => 'Da 18 a 21 anni compresi', 'over_21' => 'Oltre 21 anni'];

    public static function ageAtPickup(string $birthdate, string $pickupAt): int
    {
        /** Count completed birthdays on the local pickup date, without using today's age or fractional years. */
        $birth = CarbonImmutable::createFromFormat('!Y-m-d', $birthdate, config('app.timezone'));
        $pickup = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $pickupAt, config('app.timezone'))->startOfDay();
        return $birth > $pickup ? -1 : $birth->diff($pickup)->y;
    }

    public static function bandForAge(int $age): ?string
    {
        return $age < 18 ? null : ($age <= 21 ? '18_21' : 'over_21');
    }

    public function apply(array $car, VehiclePricelist $pricelist, ?string $band): array
    {
        $settings = $pricelist->driver_age_supplements ?? [];
        if ($settings !== [] || $band) $car['driver_age_required'] = $settings !== [] && !$band;
        if (!$band) return $car;
        if (!isset(self::BANDS[$band])) throw ValidationException::withMessages(['driver_age_band' => 'Scegli la fascia di età del conducente alla data del ritiro.']);
        $rule = $settings[$band] ?? null;
        $unit = $rule['unit'] ?? 'rental';
        $amount = (int) ($rule['amount_cents'] ?? 0);
        if ($amount < 0 || !in_array($unit, ['day', 'rental'], true)) {
            throw ValidationException::withMessages(['booking' => 'Il supplemento per età richiede una verifica del listino.']);
        }
        $total = $amount * ($unit === 'day' ? $car['days'] : 1);
        if ($car['total_cents'] + $total > BookingOptions::MAX_MONEY_CENTS) {
            throw ValidationException::withMessages(['booking' => 'Il totale supera l’importo massimo gestibile.']);
        }
        /** Keep the vehicle rate separate so AMD Plus never multiplies the age supplement. */
        $car['vehicle_rental_total_cents'] = $car['total_cents'];
        $car['driver_age'] = ['band' => $band, 'label' => self::BANDS[$band], 'unit' => $unit, 'unit_cents' => $amount, 'total_cents' => $total];
        $car['total_cents'] += $total;
        return $car;
    }

    public static function retainAgreed(array $car, array $agreed): array
    {
        if (!isset($agreed['driver_age'])) return $car;
        /** A delivery proposal keeps the age supplement accepted with the original request. */
        $difference = $agreed['driver_age']['total_cents'] - ($car['driver_age']['total_cents'] ?? 0);
        $car['total_cents'] += $difference;
        if (isset($car['rental_total_cents'])) $car['rental_total_cents'] += $difference;
        $car['driver_age'] = $agreed['driver_age'];
        $car['driver_age_required'] = false;
        return $car;
    }
}
