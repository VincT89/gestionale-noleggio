<?php

namespace App\Services\AmdRent;

use App\Models\{AmdRentEnquiry, PublicBooking, Rental};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeliveryBookingRecovery
{
    public function canReopen(AmdRentEnquiry $case): bool
    {
        if ($case->type !== 'delivery' || $case->status !== 'converted' || !$case->public_booking_id) return false;

        $booking = $case->booking;
        $rental = $booking?->rental;

        return $booking && $booking->payment_method === 'stripe'
            && (int) $booking->organization_id === (int) $case->organization_id
            && (int) ($booking->quote_snapshot['delivery_request_id'] ?? 0) === (int) $case->id
            && in_array($booking->payment_status, ['failed', 'expired'], true)
            && $booking->online_paid_cents === 0 && $booking->refunded_cents === 0
            && $rental && !$rental->trashed() && $rental->status === 'cancelled'
            && !$rental->actual_pickup_at && !$rental->actual_return_at && !$rental->closed_at;
    }

    public function reopen(AmdRentEnquiry $case, int $bookingId, int $revision, ?int $userId = null): bool
    {
        return DB::transaction(function () use ($case, $bookingId, $revision, $userId) {
            [$locked, $attempts] = $this->lockRequest($case->id);
            abort_unless($locked->type === 'delivery', 404);

            // A repeated submission must not undo an operator's edit or a newer booking.
            if (!$locked->public_booking_id && $attempts->contains('id', $bookingId)) return false;
            abort_unless((int) $locked->public_booking_id === $bookingId && $locked->revision === $revision,
                409, 'La richiesta è cambiata. Aggiorna la pagina prima di riprovare.');

            if (!$this->canReopen($locked)) {
                throw ValidationException::withMessages(['booking' => 'La richiesta può essere riaperta solo dopo un pagamento non avviato o scaduto, senza incassi e con la prenotazione annullata.']);
            }
            $this->assertNoBlockingPayments($locked, attempts: $attempts);
            if ($locked->booking->rental->charges()->paid()->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['booking' => 'Sono presenti incassi nel noleggio. Contatta AMD Rent per la verifica prima di riprovare.']);
            }

            $previous = $locked->booking;
            $locked->update([
                'public_booking_id' => null,
                'status' => 'quoted',
                'revision' => $locked->revision + 1,
            ]);
            $locked->events()->create([
                'user_id' => $userId,
                'description' => 'Richiesta riaperta dopo '.$previous->reference.' ('.mb_strtolower($previous->status_label).'). Proposta e scadenza conservate; prezzo e disponibilità da ricontrollare.',
            ]);

            return true;
        }, 3);
    }

    /**
     * Call inside a transaction. Use the payment lifecycle's lock order:
     * rentals, bookings, then the delivery request.
     *
     * @return array{AmdRentEnquiry, Collection<int, PublicBooking>}
     */
    public function lockRequest(int $id): array
    {
        $candidate = AmdRentEnquiry::findOrFail($id);
        $ids = $this->attempts($candidate)->get(['id', 'rental_id']);
        $rentals = Rental::withTrashed()->whereIn('id', $ids->pluck('rental_id'))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $attempts = PublicBooking::whereIn('id', $ids->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        foreach ($attempts as $booking) $booking->setRelation('rental', $rentals->get($booking->rental_id));

        $case = AmdRentEnquiry::lockForUpdate()->findOrFail($id);
        abort_unless($case->revision === $candidate->revision
            && (int) $case->public_booking_id === (int) $candidate->public_booking_id,
            409, 'La richiesta è cambiata. Aggiorna la pagina prima di riprovare.');
        $case->setRelation('booking', $attempts->firstWhere('id', $case->public_booking_id));

        return [$case, $attempts];
    }

    public function assertNoBlockingPayments(AmdRentEnquiry $case, ?int $exceptBookingId = null, ?Collection $attempts = null): void
    {
        $attempts ??= $this->attempts($case)->get();
        if ($attempts->contains(fn (PublicBooking $booking) => (int) $booking->id !== $exceptBookingId
            && (in_array($booking->payment_status, ['pending', 'paid', 'review', 'pickup'], true)
                || $booking->online_paid_cents > $booking->refunded_cents))) {
            throw ValidationException::withMessages(['booking' => 'Un pagamento di questa richiesta è ancora in attesa, già incassato o da verificare. Non effettuare un nuovo pagamento; contatta AMD Rent.']);
        }
    }

    public function assertCanPay(PublicBooking $booking): void
    {
        $id = $booking->quote_snapshot['delivery_request_id'] ?? null;
        if (!$id) return;
        $case = AmdRentEnquiry::where('type', 'delivery')->findOrFail($id);
        if ((int) $case->public_booking_id !== (int) $booking->id) {
            throw ValidationException::withMessages(['booking' => 'La richiesta è collegata a un altro tentativo. Riapri il suo riepilogo aggiornato.']);
        }
        $this->assertNoBlockingPayments($case, $booking->id);
    }

    private function attempts(AmdRentEnquiry $case): Builder
    {
        // The immutable booking snapshot preserves the link after the request is reopened.
        return PublicBooking::where('organization_id', $case->organization_id)
            ->where(function (Builder $query) use ($case) {
                $query->where('quote_snapshot->delivery_request_id', $case->id);
                if ($case->public_booking_id) $query->orWhere('id', $case->public_booking_id);
            });
    }
}
