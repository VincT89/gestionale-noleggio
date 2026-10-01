<?php

namespace App\Http\Controllers;

use App\Models\PublicBooking;
use App\Services\AmdRent\BookingPayments;
use Illuminate\Http\Request;
use Stripe\Webhook;
use App\Models\Rental;
use App\Support\AmdRentAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StripeBookingController extends Controller
{
    public function webhook(Request $request, BookingPayments $payments)
    {
        abort_unless(filled(config('amd_rent.stripe_webhook_secret')), 503);
        try {
            $event = Webhook::constructEvent($request->getContent(), $request->header('Stripe-Signature', ''), config('amd_rent.stripe_webhook_secret'));
        } catch (\UnexpectedValueException|\Stripe\Exception\SignatureVerificationException $e) { abort(400, 'Firma Stripe non valida.'); }
        abort_unless($event->livemode === (bool) config('amd_rent.stripe_live'), 400);
        if (in_array($event->type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.expired'])) $payments->settle($event->data->object->toArray());
        if ($event->type === 'charge.refunded') $payments->refunded($event->data->object->toArray());
        return response()->json(['received' => true]);
    }
    public function resume(string $reference, BookingPayments $payments)
    {
        $booking = PublicBooking::where('reference', $reference)->firstOrFail();
        try { $url = $payments->checkout($booking); }
        catch (\Throwable $e) { return redirect()->to($booking->confirmationUrl())->with('payment_error', $booking->fresh()->payment_status === 'failed' ? 'Stripe non ha potuto aprire il pagamento. Torna alla ricerca per riprovare.' : 'Pagamento non disponibile in questo momento. La prenotazione non risulta confermata; riprova da questa pagina.'); }
        return redirect()->to($url ?: $booking->confirmationUrl(), 303);
    }

    public function review(Request $request, PublicBooking $booking)
    {
        AmdRentAccess::admin($request->user());
        $data = $request->validate(['action' => ['required', 'in:keep,cancel'], 'verified_in_stripe' => ['accepted'],
            'refund_snapshot' => ['required', 'integer', 'min:0'], 'note' => ['required', 'string', 'min:10', 'max:1000']]);
        DB::transaction(function () use ($booking, $data, $request) {
            $rental = Rental::withTrashed()->lockForUpdate()->findOrFail($booking->rental_id);
            $locked = PublicBooking::lockForUpdate()->findOrFail($booking->id);
            abort_unless($locked->payment_status === 'review' && $locked->refunded_cents === (int) $data['refund_snapshot'], 409, 'Il pagamento è cambiato. Aggiorna la pagina.');
            $error = fn ($message) => ValidationException::withMessages(['payment' => $message]);
            if ($data['action'] === 'cancel') {
                if ($locked->online_paid_cents <= 0 || $locked->refunded_cents !== $locked->online_paid_cents) throw $error('Il rimborso completo deve prima risultare riconciliato da Stripe.');
                if (!in_array($rental->status, ['reserved', 'cancelled']) || $rental->actual_pickup_at) throw $error('Un noleggio già iniziato richiede la gestione del rientro prima della chiusura.');
                $rental->forceFill(['status' => 'cancelled'])->save();
                $status = 'refunded';
            } else {
                if ($rental->trashed() || !in_array($rental->status, ['reserved', 'in_use', 'checked_out', 'checked_in', 'closed'])
                    || $locked->online_paid_cents <= $locked->refunded_cents
                    || !$rental->charges()->where('payment_reference', 'stripe:'.$locked->stripe_session_id)->exists()) throw $error('Questa prenotazione non può essere riconfermata: occorre una nuova verifica della disponibilità.');
                $status = 'paid';
            }
            $history = $locked->payment_review_history ?? [];
            $history[] = ['at' => now()->toIso8601String(), 'user_id' => $request->user()->id, 'action' => $data['action'],
                'note' => $data['note'], 'online_paid_cents' => $locked->online_paid_cents, 'refunded_cents' => $locked->refunded_cents];
            $locked->update(['payment_status' => $status, 'payment_review_history' => $history]);
        }, 3);
        return back()->with('status', 'Verifica del pagamento registrata.');
    }
}
