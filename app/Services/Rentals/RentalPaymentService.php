<?php

namespace App\Services\Rentals;

use App\Domain\Fees\AdminFeeResolver;
use App\Models\{Rental, RentalCharge, User};
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Validation\ValidationException;

class RentalPaymentService
{
    public function __construct(private AdminFeeResolver $fees) {}

    public function record(Rental $rental, array $data, User $actor): RentalCharge
    {
        return DB::transaction(function () use ($rental, $data, $actor) {
            $rental = Rental::query()->lockForUpdate()->findOrFail($rental->id);
            Gate::forUser($actor)->authorize('update', $rental);

            $attributes = [
                'kind' => $data['kind'],
                'amount' => number_format((float) $data['amount'], 2, '.', ''),
                'description' => $data['description'] ?? $data['payment_notes'] ?? null,
                'payment_reference' => $data['payment_reference'] ?? null,
                'payment_method' => $data['payment_method'],
                'is_commissionable' => $this->isCommissionable($rental, $data['kind']),
            ];

            $key = $data['request_key'] ?? null;
            if ($key) {
                $existing = $rental->charges()->withTrashed()->where('request_key', $key)->first();
                if ($existing) {
                    $same = !$existing->trashed() && $existing->payment_recorded
                        && (int) $existing->created_by === (int) $actor->id;
                    foreach ($attributes as $field => $value) {
                        $same = $same && $existing->{$field} === $value;
                    }
                    if (!$same) {
                        throw ValidationException::withMessages([
                            'request_key' => 'Questa richiesta è già stata utilizzata per un pagamento diverso. Riapri il modulo.',
                        ]);
                    }
                    return $existing;
                }
            }

            $payment = $rental->charges()->create($attributes + [
                'request_key' => $key,
                'payment_recorded' => true,
                'payment_recorded_at' => now(),
                'created_by' => $actor->id,
            ]);

            $this->refreshClosedCommission($rental);

            return $payment;
        }, 3);
    }

    public function isCommissionable(Rental $rental, string $kind): bool
    {
        return $rental->assignment_id !== null
            && in_array($kind, RentalCharge::COMMISSIONABLE_KINDS, true);
    }

    /** Il chiamante mantiene il lock sul noleggio nella stessa transazione. */
    public function refreshClosedCommission(Rental $rental): void
    {
        if ($rental->status !== 'closed' || !$rental->closed_at || !$rental->organization?->isRenter()) {
            return;
        }

        $calculation = $this->fees->calculateForRental($rental);
        $rental->forceFill(['admin_fee_amount' => $calculation['amount']])->save();
    }
}
