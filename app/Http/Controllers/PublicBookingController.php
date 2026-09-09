<?php

namespace App\Http\Controllers;

use App\Domain\Rentals\{PublicBookingService, PublicVehicleSearch};
use App\Http\Requests\{PublicBookingRequest, PublicCarSearchRequest};
use App\Models\{PublicBooking, PublicRentalOffer};
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, Gate};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublicBookingController extends Controller
{
    private function scope(Request $request, bool $preview): Builder
    {
        if (!$preview) return PublicRentalOffer::published();
        Gate::authorize('vehicle_pricing.update');
        abort_unless($request->user()?->is_active && $request->user()?->organization?->is_active, 403);
        return PublicRentalOffer::eligible()->when(!$request->user()->hasRole('admin'),
            fn ($q) => $q->where('organization_id', $request->user()->organization_id));
    }

    public function create(PublicCarSearchRequest $request, PublicVehicleSearch $search, int $offer)
    {
        $preview = $request->routeIs('public-cars.preview.*');
        $scope = $this->scope($request, $preview)->whereKey($offer);
        abort_unless((clone $scope)->exists(), 404);
        $period = $request->safe()->only(['pickup_at', 'return_at']);
        $car = $search->search($scope, $period)->first();
        $prefix = $preview ? 'public-cars.preview' : 'public-cars';
        if (!$car) return $this->page('public-cars.show', ['car' => null, 'filters' => $period, 'preview' => $preview, 'routePrefix' => $prefix]);

        $intent = ['nonce' => (string) Str::uuid(), 'offer' => $offer, 'period' => $period, 'preview' => $preview,
            'fingerprint' => PublicBookingService::fingerprint($car), 'expires_at' => now()->addMinutes(30)->timestamp];
        $known = $request->session()->get('public_booking_checkouts', []);
        $known[$intent['nonce']] = true;
        $request->session()->put('public_booking_checkouts', array_slice($known, -20, null, true));
        return $this->page('public-cars.booking', [
            'car' => $car, 'filters' => $period, 'preview' => $preview, 'routePrefix' => $prefix,
            'bookable' => PublicRentalOffer::published()->whereKey($offer)->exists(),
            'checkoutToken' => Crypt::encryptString(json_encode($intent, JSON_THROW_ON_ERROR)),
        ]);
    }

    public function store(PublicBookingRequest $request, PublicBookingService $service, PublicVehicleSearch $search, int $offer)
    {
        $preview = $request->routeIs('public-cars.preview.*');
        $scope = $this->scope($request, $preview)->whereKey($offer);
        abort_unless((clone $scope)->exists(), 404);
        try {
            $intent = json_decode(Crypt::decryptString($request->validated('checkout_token')), true, 32, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $exception) {
            throw ValidationException::withMessages(['checkout_token' => 'Il riepilogo non è valido. Riaprilo e riprova.']);
        }
        if (($intent['offer'] ?? null) !== $offer || ($intent['preview'] ?? null) !== $preview
            || !$request->session()->get('public_booking_checkouts.'.($intent['nonce'] ?? 'missing'))
            || ($intent['period'] ?? []) !== $request->safe()->only(['pickup_at', 'return_at'])) {
            throw ValidationException::withMessages(['checkout_token' => 'La sessione o le date sono cambiate. Riapri il riepilogo e riprova.']);
        }
        if ($preview) {
            if (!PublicRentalOffer::published()->whereKey($offer)->exists()) {
                throw ValidationException::withMessages(['booking' => 'Per confermare la prenotazione, l’offerta deve essere pubblicata con prezzi verificati.']);
            }
        }

        $booking = $service->reserve($intent, $request->safe()->only(['first_name', 'last_name', 'email', 'phone']));
        return redirect()->to($booking->confirmationUrl(), 303);
    }

    public function confirmation(string $reference)
    {
        $booking = PublicBooking::with('rental')->where('reference', $reference)->firstOrFail();
        return $this->page('public-cars.booking-confirmation', [
            'booking' => $booking, 'car' => $booking->quote_snapshot,
            'filters' => ['pickup_at' => $booking->pickup_at->format('Y-m-d\TH:i'), 'return_at' => $booking->return_at->format('Y-m-d\TH:i')],
            'preview' => false, 'routePrefix' => 'public-cars',
        ]);
    }

    public function pdf(string $reference)
    {
        $booking = PublicBooking::with('rental')->where('reference', $reference)->firstOrFail();
        $pdf = app('dompdf.wrapper')->loadView('pdfs.public-booking', ['booking' => $booking, 'car' => $booking->quote_snapshot])
            ->setPaper('a4')->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $filename = 'Prenotazione-'.$booking->reference.'.pdf';
        $response = request()->boolean('download') ? $pdf->download($filename) : $pdf->stream($filename);
        return $response
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    private function page(string $view, array $data)
    {
        return response()->view($view, $data)->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    public function index(Request $request)
    {
        Gate::authorize('rentals.viewAny');
        abort_unless($request->user()?->is_active && $request->user()?->organization?->is_active, 403);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $query = PublicBooking::with(['rental.vehicle', 'organization'])
            ->when(!$request->user()->hasRole('admin'), fn ($q) => $q->where('organization_id', $request->user()->organization_id));
        if (!empty($filters['q'])) {
            $query->where(fn ($q) => $q->where('reference', 'like', '%'.$filters['q'].'%')
                ->orWhere('email', 'like', '%'.$filters['q'].'%')->orWhere('last_name', 'like', '%'.$filters['q'].'%'));
        }
        return response()->view('public-cars.bookings-manage', ['bookings' => $query->latest()->paginate(20)->withQueryString(), 'filters' => $filters])
            ->header('Cache-Control', 'private, no-store');
    }
}
