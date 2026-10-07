<?php

namespace App\Http\Controllers;

use App\Domain\Rentals\{PublicBookingService, PublicVehicleSearch};
use App\Http\Requests\{PublicBookingRequest, PublicCarSearchRequest};
use App\Models\{PublicBooking, VehiclePricelist};
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
        if (!$preview) return VehiclePricelist::forPublicRental();
        Gate::authorize('vehicle_pricing.update');
        abort_unless($request->user()?->is_active && $request->user()?->organization?->is_active, 403);
        return VehiclePricelist::forPublicRental()->when(!$request->user()->hasRole('admin'),
            fn ($q) => $q->where('renter_org_id', $request->user()->organization_id));
    }

    public function create(PublicCarSearchRequest $request, PublicVehicleSearch $search, int $pricelist)
    {
        $preview = $request->routeIs('public-cars.preview.*');
        $scope = $this->scope($request, $preview)->whereKey($pricelist);
        abort_unless((clone $scope)->exists(), 404);
        $period = array_filter($request->safe()->only(['pickup_at', 'return_at', 'place_id']), fn ($value) => $value !== null);
        $filters = $period + $request->safe()->only(['request_delivery', 'delivery_address', 'delivery_place', 'request_custom_return', 'return_address', 'return_place']);
        $customReturn = !empty($filters['request_custom_return']);
        // A requested return address is confirmed by the supplier. It is not an
        // existing public return point, nor a reason to change pickup coverage.
        $car = $search->search($scope, $customReturn ? \Illuminate\Support\Arr::except($filters, ['place_id']) : $filters)->first();
        $prefix = $preview ? 'public-cars.preview' : 'public-cars';
        if (!empty($filters['request_delivery']) && !$customReturn && empty($period['place_id'])) {
            return redirect()->route($prefix.'.show', ['pricelist' => $pricelist] + $filters)
                ->withErrors(['place_id' => 'Scegli dove riconsegnare l’auto prima di continuare.']);
        }
        if (!$car) return $this->page('public-cars.show', ['car' => null, 'filters' => $filters, 'preview' => $preview, 'routePrefix' => $prefix]);

        if ($customReturn) {
            // This id identifies the serving business internally; the customer's
            // actual return appointment is stored independently below.
            $filters['place_id'] = $period['place_id'] = $car['place_id'];
            $car['return_address'] = $filters['return_address'];
            $car['return_destination'] = app(\App\Services\Geocoding\PlaceSelection::class)
                ->resolve($filters['return_place'], $filters['return_address'], 'public-return');
        }

        $intent = ['nonce' => (string) Str::uuid(), 'source' => 'pricelist', 'pricelist' => $pricelist, 'period' => $period, 'preview' => $preview,
            'fingerprint' => PublicBookingService::fingerprint($car), 'expires_at' => now()->addMinutes(30)->timestamp];
        if (!empty($car['delivery_destination'])) $intent['delivery_destination'] = $car['delivery_destination'];
        if ($customReturn) {
            $intent['return_address'] = $car['return_address'];
            $intent['return_destination'] = $car['return_destination'];
        }
        $known = $request->session()->get('public_booking_checkouts', []);
        $known[$intent['nonce']] = true;
        $request->session()->put('public_booking_checkouts', array_slice($known, -20, null, true));
        return $this->page('public-cars.booking', [
            'car' => $car, 'filters' => $filters, 'preview' => $preview, 'routePrefix' => $prefix,
            'checkoutToken' => Crypt::encryptString(json_encode($intent, JSON_THROW_ON_ERROR)),
        ]);
    }

    public function store(PublicBookingRequest $request, PublicBookingService $service, PublicVehicleSearch $search, int $pricelist)
    {
        try {
            return $this->submitBooking($request, $service, $search, $pricelist);
        } catch (ValidationException $exception) {
            // Quote/payment checks run after FormRequest validation. An image request can
            // overwrite the session's previous URL, so always return to the booking flow.
            throw $exception->redirectTo($request->getRedirectUrl());
        }
    }

    private function submitBooking(PublicBookingRequest $request, PublicBookingService $service, PublicVehicleSearch $search, int $pricelist)
    {
        $preview = $request->routeIs('public-cars.preview.*');
        $scope = $this->scope($request, $preview)->whereKey($pricelist);
        abort_unless((clone $scope)->exists(), 404);
        try {
            $intent = json_decode(Crypt::decryptString($request->validated('checkout_token')), true, 32, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $exception) {
            throw ValidationException::withMessages(['checkout_token' => 'Il riepilogo non è valido. Riaprilo e riprova.']);
        }
        // MySQL reorders JSON object keys in saved delivery proposals. Compare the same
        // fields and types without treating their storage order as a changed period.
        $submittedPeriod = array_filter($request->safe()->only(['pickup_at', 'return_at', 'place_id']), fn ($value) => $value !== null);
        $issuedPeriod = $intent['period'] ?? [];
        ksort($submittedPeriod);
        if (is_array($issuedPeriod)) ksort($issuedPeriod);
        if (($intent['source'] ?? null) !== 'pricelist' || ($intent['pricelist'] ?? null) !== $pricelist || ($intent['preview'] ?? null) !== $preview
            || !$request->session()->get('public_booking_checkouts.'.($intent['nonce'] ?? 'missing'))
            || $issuedPeriod !== $submittedPeriod) {
            throw ValidationException::withMessages(['checkout_token' => 'La sessione, il luogo o le date sono cambiati. Riapri il riepilogo e riprova.']);
        }
        $contact = $request->safe()->only(['first_name', 'last_name', 'email', 'phone']);
        if (empty($intent['delivery_request_id'])) {
            $submittedReturn = $request->boolean('request_custom_return') ? $request->validated('return_address') : null;
            if (($intent['return_address'] ?? null) !== $submittedReturn) {
                throw ValidationException::withMessages(['return_address' => 'Il luogo di riconsegna è cambiato. Torna all’auto e riapri il riepilogo.']);
            }
            $submittedPoint = $submittedReturn ? app(\App\Services\Geocoding\PlaceSelection::class)
                ->resolve($request->validated('return_place'), $submittedReturn, 'public-return') : null;
            if (($intent['return_destination'] ?? null) != $submittedPoint) {
                throw ValidationException::withMessages(['return_place' => 'Il punto di riconsegna è cambiato. Torna all’auto e riapri il riepilogo.']);
            }
        }
        if (!empty($intent['delivery_destination']) && empty($intent['delivery_request_id'])
            && (!$request->boolean('request_delivery') || $request->input('delivery_address') !== $intent['delivery_destination']['label'])) {
            throw ValidationException::withMessages(['delivery_address' => 'Il luogo di ritiro è cambiato. Torna alla ricerca e conferma la nuova posizione.']);
        }
        if ($request->boolean('request_delivery') && empty($intent['delivery_request_id'])) {
            return app(PublicEnquiryController::class)->delivery($request, $intent, $contact, $search);
        }
        $booking = $service->reserve($intent, $contact);
        if ($booking->payment_method === 'stripe') {
            try { $url = app(\App\Services\AmdRent\BookingPayments::class)->checkout($booking); }
            catch (\Throwable $e) {
                $retry = !empty($booking->quote_snapshot['delivery_request_id'])
                    ? 'Torna alla richiesta di consegna per riprovare'
                    : 'Torna alla ricerca per riprovare';
                return redirect()->to($booking->confirmationUrl(), 303)->with('payment_error', $booking->fresh()->payment_status === 'failed'
                    ? 'Stripe non ha potuto aprire il pagamento. '.$retry.'; la prenotazione non è confermata.'
                    : 'Il pagamento non è ancora stato confermato. Riprova da questa pagina; non inviare una seconda prenotazione.');
            }
            if ($url) return redirect()->away($url, 303);
        }
        return redirect()->to($booking->confirmationUrl(), 303);
    }

    public function confirmation(string $reference)
    {
        $booking = PublicBooking::with('rental')->where('reference', $reference)->firstOrFail();
        $deliveryRequest = in_array($booking->payment_status, ['failed', 'expired'], true)
            ? \App\Models\AmdRentEnquiry::where('type', 'delivery')->where('organization_id', $booking->organization_id)
                ->find($booking->quote_snapshot['delivery_request_id'] ?? null)
            : null;
        return $this->page('public-cars.booking-confirmation', [
            'booking' => $booking, 'car' => $booking->quote_snapshot, 'deliveryRequest' => $deliveryRequest,
            'filters' => array_filter(['pickup_at' => $booking->pickup_at->format('Y-m-d\TH:i'), 'return_at' => $booking->return_at->format('Y-m-d\TH:i'),
                'place_id' => $booking->quote_snapshot['place_id'] ?? null], fn ($value) => $value !== null),
            'preview' => false, 'routePrefix' => 'public-cars',
        ]);
    }

    public function legacyConfirmation(string $reference)
    {
        $booking = PublicBooking::where('reference', $reference)->firstOrFail();

        return redirect()->to($booking->confirmationUrl())
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function legacyPdf(Request $request, string $reference)
    {
        $booking = PublicBooking::where('reference', $reference)->firstOrFail();

        return redirect()->to($booking->pdfUrl($request->boolean('download')))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
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
        \App\Support\AmdRentAccess::check($request->user());
        abort_unless($request->user()?->is_active && $request->user()?->organization?->is_active, 403);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'payment' => ['nullable', \Illuminate\Validation\Rule::in(['pending', 'paid', 'expired', 'failed', 'review', 'refunded', 'pickup'])]]);
        $query = PublicBooking::with(['rental.vehicle', 'organization'])
            ->when(!$request->user()->hasRole('admin'), fn ($q) => $q->where('organization_id', $request->user()->organization_id));
        if (!empty($filters['payment'])) $query->where('payment_status', $filters['payment']);
        if (!empty($filters['q'])) {
            $query->where(fn ($q) => $q->where('reference', 'like', '%'.$filters['q'].'%')
                ->orWhere('email', 'like', '%'.$filters['q'].'%')->orWhere('last_name', 'like', '%'.$filters['q'].'%'));
        }
        return response()->view('public-cars.bookings-manage', ['bookings' => $query->latest()->paginate(20)->withQueryString(), 'filters' => $filters])
            ->header('Cache-Control', 'private, no-store');
    }
}
