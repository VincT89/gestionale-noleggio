<?php

namespace App\Services\AmdRent;

use App\Models\{PublicBooking, Rental, RentalCharge};
use Illuminate\Support\Facades\DB;

class BookingPayments
{
    public function __construct(private StripeCheckout $stripe) {}

    public function checkout(PublicBooking $booking): ?string
    {
        if ($booking->payment_method !== 'stripe') return null;
        $this->stripe->requireReady();
        if ($booking->payment_status !== 'pending') return null;
        app(DeliveryBookingRecovery::class)->assertCanPay($booking);
        // Persist an immutable payload before contacting Stripe: retries use exactly the same idempotency key and parameters.
        $booking = DB::transaction(function () use ($booking) {
            $locked = PublicBooking::lockForUpdate()->findOrFail($booking->id);
            if (!$locked->checkout_payload) {
                $locked->update(['checkout_payload' => [
                    'mode' => 'payment', 'payment_method_types' => ['card'], 'locale' => 'it',
                    'client_reference_id' => $locked->reference, 'customer_email' => $locked->email,
                    'success_url' => $locked->confirmationUrl(), 'cancel_url' => $locked->confirmationUrl(),
                    'expires_at' => $locked->payment_expires_at->timestamp,
                    'metadata' => ['booking_reference' => $locked->reference],
                    'payment_intent_data' => ['metadata' => ['booking_reference' => $locked->reference]],
                    'line_items' => [['quantity' => 1, 'price_data' => ['currency' => 'eur', 'unit_amount' => $locked->online_due_cents,
                        'product_data' => ['name' => 'Quota online AMD Rent · '.$locked->reference]]]],
                ]]);
            }
            return $locked;
        });
        if ($booking->stripe_session_id) {
            $session = $this->stripe->retrieve($booking->stripe_session_id);
        } else {
            try {
                $session = $this->stripe->create($booking->checkout_payload, 'amd-rent-checkout-'.$booking->reference);
            } catch (CheckoutRejected $e) {
                DB::transaction(function () use ($booking) {
                    $rental = Rental::withTrashed()->lockForUpdate()->findOrFail($booking->rental_id);
                    $locked = PublicBooking::lockForUpdate()->findOrFail($booking->id);
                    if ($locked->payment_status === 'pending' && !$locked->stripe_session_id) {
                        if ($rental->status === 'reserved') $rental->forceFill(['status' => 'cancelled'])->save();
                        $locked->update(['payment_status' => 'failed', 'checkout_url' => null]);
                    }
                }, 3);
                throw $e;
            }
            $this->assertSession($booking, $session);
            DB::transaction(function () use ($booking, $session) {
                $locked = PublicBooking::lockForUpdate()->findOrFail($booking->id);
                if ($locked->stripe_session_id && $locked->stripe_session_id !== $session['id']) throw new \RuntimeException('Sessione Stripe inattesa.');
                $locked->update(['stripe_session_id' => $session['id'], 'checkout_url' => $session['url'] ?? null]);
            });
        }
        $this->settle($session);
        if (($session['status'] ?? null) !== 'open') return null;
        $url = $session['url'] ?? '';
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'checkout.stripe.com') throw new \RuntimeException('Indirizzo Stripe inatteso.');
        return $url;
    }

    private function assertSession(PublicBooking $booking, array $session): void
    {
        if (($session['client_reference_id'] ?? null) !== $booking->reference
            || ($session['metadata']['booking_reference'] ?? null) !== $booking->reference
            || ($session['mode'] ?? null) !== 'payment' || ($session['currency'] ?? null) !== 'eur'
            || (int) ($session['amount_total'] ?? -1) !== (int) $booking->online_due_cents
            || ($session['livemode'] ?? null) !== (bool) config('amd_rent.stripe_live')
            || !str_starts_with($session['id'] ?? '', 'cs_')
            || ($booking->stripe_session_id && $booking->stripe_session_id !== $session['id'])) {
            throw new \RuntimeException('Il pagamento Stripe non corrisponde alla prenotazione.');
        }
    }

    public function settle(array $session): void
    {
        $reference = $session['metadata']['booking_reference'] ?? null;
        $candidate = PublicBooking::where('reference', $reference)->where('payment_method', 'stripe')->first();
        if (!$candidate) return;
        // Same lock order as rental lifecycle actions: rental, then booking.
        DB::transaction(function () use ($candidate, $session) {
            $rental = Rental::withTrashed()->lockForUpdate()->findOrFail($candidate->rental_id);
            $booking = PublicBooking::lockForUpdate()->findOrFail($candidate->id);
            $this->assertSession($booking, $session);
            if (($session['payment_status'] ?? null) === 'paid') {
                if ($booking->online_paid_cents > 0) return;
                if (!$rental->trashed() && $rental->status === 'reserved' && $booking->payment_status === 'pending') {
                    RentalCharge::create(['rental_id' => $rental->id, 'kind' => RentalCharge::KIND_ACCONTO,
                        'amount' => number_format($booking->online_due_cents / 100, 2, '.', ''), 'is_commissionable' => false,
                        'payment_method' => 'other', 'payment_reference' => 'stripe:'.$session['id'],
                        'request_key' => \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, 'stripe:'.$session['id'])->toString(), 'description' => 'Quota online incassata da AMD Rent tramite Stripe. Commissione già assolta.',
                        'payment_recorded' => true, 'payment_recorded_at' => now(), 'created_by' => null]);
                    $rental->forceFill(['admin_fee_collected_amount' => number_format($booking->online_due_cents / 100, 2, '.', '')])->save();
                    $status = 'paid';
                } else {
                    // Never resurrect a cancelled rental or allocate a car again after releasing it.
                    $status = 'review';
                }
                $booking->update(['payment_status' => $status, 'online_paid_cents' => $booking->online_due_cents, 'paid_at' => now(),
                    'stripe_session_id' => $session['id'], 'stripe_payment_intent' => $session['payment_intent'] ?? null, 'checkout_url' => null]);
            } elseif (($session['status'] ?? null) === 'expired' && $booking->payment_status === 'pending') {
                if ($rental->status === 'reserved') $rental->forceFill(['status' => 'cancelled'])->save();
                $booking->update(['payment_status' => 'expired', 'stripe_session_id' => $session['id'], 'checkout_url' => null]);
            }
        }, 3);
    }

    public function expireDue(PublicBooking $booking): void
    {
        if ($booking->payment_status !== 'pending' || !$booking->payment_expires_at?->isPast()) return;
        if (!$booking->stripe_session_id) {
            // An ambiguous create response must not free an auto that might have been paid for.
            $this->checkout($booking);
            return;
        }
        $session = $this->stripe->retrieve($booking->stripe_session_id);
        if (($session['status'] ?? '') === 'open') $session = $this->stripe->expire($booking->stripe_session_id);
        $this->settle($session);
    }

    public function refunded(array $charge): void
    {
        if (($charge['livemode'] ?? null) !== (bool) config('amd_rent.stripe_live') || ($charge['currency'] ?? '') !== 'eur') throw new \RuntimeException('Rimborso non valido.');
        $intent = $charge['payment_intent'] ?? '';
        if (!is_string($intent) || !str_starts_with($intent, 'pi_')) throw new \RuntimeException('Riferimento Stripe del rimborso mancante.');
        $candidate = PublicBooking::where('payment_method', 'stripe')->where('stripe_payment_intent', $intent)->first();
        if (!$candidate && !empty($charge['metadata']['booking_reference'])) {
            $candidate = PublicBooking::where('payment_method', 'stripe')->where('reference', $charge['metadata']['booking_reference'])->first();
            if ($candidate) {
                // Stripe does not guarantee event order. Recover the original payment before applying its refund.
                if (!$candidate->stripe_session_id) throw new \RuntimeException('Sessione ancora da riconciliare: riprovare il webhook.');
                $session = $this->stripe->retrieve($candidate->stripe_session_id);
                $this->assertSession($candidate, $session);
                if (($session['payment_intent'] ?? '') !== $intent || ($session['payment_status'] ?? '') !== 'paid') throw new \RuntimeException('Rimborso da verificare con la sessione originale.');
                $this->settle($session);
                $candidate->refresh();
            }
        }
        if (!$candidate) return;
        DB::transaction(function () use ($candidate, $charge) {
            $rental = Rental::withTrashed()->lockForUpdate()->findOrFail($candidate->rental_id);
            $booking = PublicBooking::lockForUpdate()->findOrFail($candidate->id);
            $refunded = (int) ($charge['amount_refunded'] ?? 0);
            if ((int) ($charge['amount'] ?? -1) !== $booking->online_paid_cents || $refunded > $booking->online_paid_cents) throw new \RuntimeException('Importo del rimborso incoerente.');
            if ($refunded <= $booking->refunded_cents) return;
            // Separate negative entry preserves the original payment and makes partial refunds auditable.
            $recorded = $rental->charges()->where('payment_reference', 'stripe:'.$booking->stripe_session_id)->exists();
            if ($recorded) RentalCharge::create(['rental_id' => $rental->id, 'kind' => RentalCharge::KIND_ACCONTO, 'amount' => number_format(-($refunded - $booking->refunded_cents) / 100, 2, '.', ''),
                'is_commissionable' => false, 'payment_method' => 'other', 'payment_reference' => 'stripe-refund:'.$charge['id'],
                'request_key' => \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, 'stripe-refund:'.$charge['id'].':'.$refunded)->toString(), 'description' => 'Rimborso Stripe AMD Rent.', 'payment_recorded' => true, 'payment_recorded_at' => now()]);
            $booking->update(['refunded_cents' => $refunded, 'payment_status' => 'review']);
            $rental->forceFill(['admin_fee_collected_amount' => number_format($recorded ? ($booking->online_paid_cents - $refunded) / 100 : 0, 2, '.', '')])->save();
            if ($rental->closed_at) {
                $fee = app(\App\Domain\Fees\AdminFeeResolver::class)->calculateForRental($rental);
                $rental->forceFill(['admin_fee_amount' => $fee['amount']])->save();
            }
        }, 3);
    }
}
