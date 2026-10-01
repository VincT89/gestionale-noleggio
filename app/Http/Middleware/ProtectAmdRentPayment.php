<?php
namespace App\Http\Middleware;
use App\Models\{Rental, PublicBooking};
use Closure;
use Illuminate\Http\Request;

class ProtectAmdRentPayment
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->isMethodSafe() && $request->user() && ($parameter = $request->route('rental'))) {
            $rental = $parameter instanceof Rental ? $parameter : (is_scalar($parameter) ? Rental::find($parameter) : null);
            if ($rental?->booking_channel === 'amd_rent' && PublicBooking::where('rental_id', $rental->id)->whereIn('payment_status', ['pending', 'review'])->exists()) {
                abort(409, 'La prenotazione AMD Rent ha un pagamento in attesa o da verificare. Gestiscila dalla sezione AMD Rent prima di modificare il noleggio.');
            }
        }
        return $next($request);
    }
}
