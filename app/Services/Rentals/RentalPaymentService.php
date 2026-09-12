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
        if (empty($data['request_key'])) {
            throw ValidationException::withMessages([
                'request_key' => 'Pagina non aggiornata. Ricarica la pagina prima di registrare il pagamento.',
            ]);
        }
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

    public function delete(Rental $rental, int $paymentId, User $actor): void
    {
        DB::transaction(function () use ($rental, $paymentId, $actor) {
            $rental = Rental::query()->lockForUpdate()->findOrFail($rental->id);
            Gate::forUser($actor)->authorize('view', $rental);
            Gate::forUser($actor)->authorize('update', $rental);
            $payment = $rental->charges()->withTrashed()->paid()->lockForUpdate()->findOrFail($paymentId);
            if ($payment->trashed()) {
                return;
            }

            $affectsCommission = $payment->is_commissionable && (float) $payment->amount !== 0.0;
            if ($affectsCommission && $rental->status === 'closed' && $rental->closed_at
                && $rental->organization?->isRenter() && $rental->admin_fee_percent === null) {
                throw ValidationException::withMessages([
                    'payment' => 'La percentuale di commissione storica manca. Occorre verificarla prima di eliminare questo pagamento.',
                ]);
            }

            $previousFee = $rental->admin_fee_amount;
            $payment->delete();
            if ($affectsCommission) {
                $this->refreshClosedCommission($rental);
            }
            activity('rental_payments')->performedOn($payment)->causedBy($actor)->event('payment_deleted')
                ->withProperties([
                    'rental_id' => $rental->id,
                    'payment_id' => $payment->id,
                    'kind' => $payment->kind,
                    'amount' => $payment->amount,
                    'payment_method' => $payment->payment_method,
                    'payment_recorded_at' => $payment->payment_recorded_at?->toIso8601String(),
                    'created_by' => $payment->created_by,
                    'previous_admin_fee_amount' => $previousFee,
                    'new_admin_fee_amount' => $rental->admin_fee_amount,
                    'admin_fee_percent' => $rental->admin_fee_percent,
                    'deleted_at' => $payment->deleted_at?->toIso8601String(),
                ])->log('Pagamento eliminato dallo storico degli incassi.');
        }, 3);
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
