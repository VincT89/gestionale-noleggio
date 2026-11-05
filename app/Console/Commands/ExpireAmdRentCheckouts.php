<?php
namespace App\Console\Commands;
use App\Models\PublicBooking;
use App\Services\AmdRent\BookingPayments;
use Illuminate\Console\Command;
class ExpireAmdRentCheckouts extends Command {
    protected $signature = 'amd-rent:expire-checkouts';
    protected $description = 'Riconcilia i pagamenti Stripe e libera le prenotazioni scadute confermate da Stripe.';
    public function handle(BookingPayments $payments): int {
        $errors = 0;
        PublicBooking::where('payment_status', 'pending')->where('payment_expires_at', '<=', now())->chunkById(50, function ($bookings) use ($payments, &$errors) {
            foreach ($bookings as $booking) {
                try { $payments->expireDue($booking); }
                catch (\Throwable $e) { $errors++; $this->error($booking->reference.': verifica richiesta; '.$booking->fresh()->status_label); }
            }
        });
        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
