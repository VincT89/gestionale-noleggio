<?php

namespace App\Services\AmdRent;

use App\Models\{AmdRentOption, Vehicle};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class BookingOptions
{
    public const MAX_MONEY_CENTS = 9999999999;

    public function catalog(array $car): array
    {
        $vehicle = Vehicle::findOrFail($car['vehicle_id']);
        $options = AmdRentOption::where('organization_id', $car['supplier_id'])->where('published', true)
            ->where(function (Builder $query) use ($car) {
                $query->where(fn (Builder $all) => $all->whereNull('vehicle_id')->whereNull('vehicle_product_id'))
                    ->orWhere('vehicle_id', $car['vehicle_id']);
                if ($car['product_id'] ?? null) {
                    $query->orWhere('vehicle_product_id', $car['product_id']);
                }
            })
            ->orderByRaw('CASE WHEN vehicle_id IS NOT NULL THEN 0 WHEN vehicle_product_id IS NOT NULL THEN 1 ELSE 2 END')
            ->orderBy('id')->get();

        $standard = $options->firstWhere('kind', 'standard');
        $coverage = [];
        foreach (AmdRentOption::COVERAGES as $key => $label) {
            $included = $key === 'rca' || array_key_exists($key, $standard?->coverage ?? []);
            $coverage[$key] = ['label' => $label, 'included' => $included,
                'franchise_cents' => $included ? $this->franchiseCents($standard, $vehicle, $key) : null];
        }

        $extras = [];
        $plus = $options->firstWhere('kind', 'plus');
        if ($plus && ($plus->price_unit !== 'multiplier' || $plus->price_multiplier_bps !== null)) {
            $changes = $this->plusCoverage($plus, $vehicle, $coverage);
            if ($plus->protection_type === 'policy' || $changes !== []) {
                $extras[] = $this->extra($plus, $car) + ['coverage' => $changes];
            }
        }

        $services = $options->where('kind', 'service');
        $secondDriver = $services->firstWhere('covers_second_driver', true);
        foreach ($services as $option) {
            if (!$option->covers_second_driver || $option->is($secondDriver)) {
                $extras[] = $this->extra($option, $car);
            }
        }

        return [
            'standard_protection' => [
                'description' => $standard?->description, 'exclusions' => $standard?->exclusions,
                'terms_url' => $standard?->terms_url, 'coverage' => $coverage,
            ],
            'extras' => $extras,
        ];
    }

    public function fingerprint(array $catalog): string
    {
        return hash('sha256', json_encode(Arr::sortRecursive($catalog), JSON_THROW_ON_ERROR));
    }

    public function apply(array $car, array $intent): array
    {
        $ids = $intent['extras'] ?? [];
        if (!isset($intent['options_fingerprint']) && $ids === []) {
            return $car;
        }

        $catalog = $this->catalog($car);
        if (!hash_equals($intent['options_fingerprint'] ?? '', $this->fingerprint($catalog))) {
            throw ValidationException::withMessages(['extras' => 'Le condizioni o gli extra disponibili sono cambiati. Controlla il nuovo riepilogo.']);
        }

        $extras = $this->selected($catalog, $ids);
        if (count($extras) !== count($ids)) {
            throw ValidationException::withMessages(['extras' => 'Uno degli extra selezionati non è disponibile per questa auto.']);
        }

        $car = self::withAgreedOptions($car, $catalog + ['selected_extras' => $extras]);
        if ($car['total_cents'] > self::MAX_MONEY_CENTS) {
            throw ValidationException::withMessages(['extras' => 'Il totale supera l’importo massimo gestibile. Contatta AMD Rent.']);
        }
        $displayedTotal = $intent['displayed_total_cents'] ?? null;
        if (($ids !== [] || $displayedTotal !== null) && $displayedTotal !== $car['total_cents']) {
            throw ValidationException::withMessages(['extras' => 'Controlla il totale aggiornato con gli extra selezionati e conferma di nuovo.']);
        }

        return $car;
    }

    public function selected(array $catalog, array $ids): array
    {
        return array_values(array_filter($catalog['extras'], fn (array $extra) => in_array($extra['id'], $ids, true)));
    }

    public static function withAgreedOptions(array $car, array $agreed): array
    {
        if (isset($agreed['standard_protection'])) {
            $car['standard_protection'] = $agreed['standard_protection'];
        }
        if (!empty($agreed['selected_extras'])) {
            $rentalCents = $car['rental_total_cents'] ?? $car['total_cents'];
            $car['selected_extras'] = array_map(fn (array $extra) => self::priceForRental($extra, $rentalCents), $agreed['selected_extras']);
            $car['total_cents'] += self::total($car['selected_extras']);
        }

        return $car;
    }

    public static function total(array $extras): int
    {
        return array_sum(array_column($extras, 'total_cents'));
    }

    public static function paymentBase(array $car, string $rule): int
    {
        $excluded = array_filter($car['selected_extras'] ?? [], fn (array $extra) => !$extra[$rule]);

        return $car['total_cents'] - self::total($excluded);
    }

    public static function hasSecondDriver(array $car): bool
    {
        return in_array(true, array_column($car['selected_extras'] ?? [], 'covers_second_driver'), true);
    }

    /** Only a contractual reduction changes the franchise charged by the rental company. */
    public static function rentalCoverage(array $car): array
    {
        $coverage = $car['standard_protection']['coverage'];
        foreach ($car['selected_extras'] ?? [] as $extra) {
            if (($extra['protection_type'] ?? null) === 'reduction') {
                $coverage = array_replace($coverage, $extra['coverage']);
            }
        }
        $attributes = [];
        foreach ($coverage as $key => $entry) {
            $attributes[$key] = $entry['included'];
            if (isset(AmdRentOption::VEHICLE_FRANCHISES[$key])) {
                $cents = $entry['franchise_cents'];
                $attributes['franchise_'.$key] = $cents === null ? null : $cents / 100;
            }
        }

        return $attributes;
    }

    private function franchiseCents(?AmdRentOption $option, Vehicle $vehicle, string $key): ?int
    {
        $column = AmdRentOption::VEHICLE_FRANCHISES[$key] ?? null;
        if ($column === null) {
            return null;
        }
        $fixed = $option?->coverage[$key] ?? null;
        if ($fixed !== null) {
            return (int) $fixed;
        }
        $base = $vehicle->{$column};
        if ($base === null || $base > self::MAX_MONEY_CENTS) {
            return null;
        }

        $factor = $option?->franchise_multipliers[$key] ?? AmdRentOption::MULTIPLIER_SCALE;
        $amount = self::multiplyCents((int) $base, $factor);

        return $amount <= self::MAX_MONEY_CENTS ? $amount : null;
    }

    private function plusCoverage(AmdRentOption $option, Vehicle $vehicle, array $coverage): array
    {
        $changes = [];
        foreach (array_keys($option->coverage ?? []) as $key) {
            $standard = $coverage[$key];
            $cents = $this->franchiseCents($option, $vehicle, $key);
            if ($key !== 'assistenza' && $cents === null) {
                return [];
            }
            if ($standard['included'] && ($key === 'assistenza'
                || ($standard['franchise_cents'] !== null && $cents >= $standard['franchise_cents']))) {
                continue;
            }
            $changes[$key] = ['label' => $standard['label'], 'included' => true, 'franchise_cents' => $cents];
        }

        return $changes;
    }

    private function extra(AmdRentOption $option, array $car): array
    {
        $extra = [
            'id' => $option->id,
            'kind' => $option->kind,
            'name' => $option->name,
            'description' => $option->description,
            'exclusions' => $option->exclusions,
            'protection_type' => $option->protection_type,
            'provider' => $option->provider,
            'terms_url' => $option->terms_url,
            'price_cents' => $option->price_cents,
            'price_unit' => $option->price_unit,
            'total_cents' => (int) $option->price_cents * ($option->price_unit === 'day' ? $car['days'] : 1),
            'depositable' => $option->depositable,
            'commissionable' => $option->commissionable,
            'covers_second_driver' => $option->covers_second_driver,
        ];
        if ($option->price_unit === 'multiplier') {
            $extra['price_multiplier_bps'] = $option->price_multiplier_bps;
        }

        return self::priceForRental($extra, $car['rental_total_cents'] ?? $car['total_cents']);
    }

    /** Apply the uplift only to the vehicle rate and keep the selected factor for delivery proposals. */
    private static function priceForRental(array $extra, int $rentalCents): array
    {
        if (isset($extra['price_multiplier_bps'])) {
            if ($rentalCents > self::MAX_MONEY_CENTS) {
                throw ValidationException::withMessages(['extras' => 'La tariffa supera l’importo massimo gestibile. Contatta AMD Rent.']);
            }
            $uplift = $extra['price_multiplier_bps'] - AmdRentOption::MULTIPLIER_SCALE;
            $extra['total_cents'] = self::multiplyCents($rentalCents, $uplift);
        }

        return $extra;
    }

    private static function multiplyCents(int $amount, int $multiplier): int
    {
        $scale = AmdRentOption::MULTIPLIER_SCALE;

        return intdiv($amount * $multiplier + intdiv($scale, 2), $scale);
    }
}
