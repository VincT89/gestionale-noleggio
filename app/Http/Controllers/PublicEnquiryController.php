<?php

namespace App\Http\Controllers;

use App\Domain\Rentals\{PublicBookingService, PublicVehicleSearch};
use App\Models\{AmdRentEnquiry, VehiclePricelist};
use App\Services\AmdRent\DeliveryQuotes;
use App\Services\AmdRent\DeliveryBookingRecovery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublicEnquiryController extends Controller
{
    public function create(Request $request)
    {
        $token = (string) Str::uuid();
        $tokens = $request->session()->get('amd_enquiry_tokens', []);
        $tokens[$token] = now()->timestamp;
        $request->session()->put('amd_enquiry_tokens', array_slice($tokens, -20, null, true));
        return $this->page('public-cars.long-term', compact('token'));
    }
    public function store(Request $request)
    {
        $data = $request->validate(AmdRentEnquiryController::contactRules() + AmdRentEnquiryController::longTermRules() + [
            'request_token' => ['required', 'uuid'], 'accept_contact' => ['accepted'], 'website' => ['nullable', 'string', 'max:0'],
        ]);
        $issued = $request->session()->get('amd_enquiry_tokens.'.$data['request_token']);
        abort_unless($issued && $issued > now()->subHours(2)->timestamp, 419, 'Il modulo è scaduto. Riapri la pagina.');
        $hash = hash('sha256', 'long_term:'.$data['request_token']);
        $case = AmdRentEnquiry::firstOrCreate(['request_hash' => $hash], collect($data)->except(['request_token', 'accept_contact', 'website'])->all()
            + ['type' => 'long_term', 'reference' => 'LT-'.Str::upper(Str::random(12))]);
        return redirect()->to($case->publicUrl(), 303);
    }
    public function delivery(Request $request, array $intent, array $contact, PublicVehicleSearch $search)
    {
        $data = $request->validate(['delivery_address' => ['required', 'string', 'min:8', 'max:500'], 'delivery_notes' => ['nullable', 'string', 'max:2000']]);
        $hash = hash('sha256', 'delivery:'.$intent['nonce']);
        if ($known = AmdRentEnquiry::where('request_hash', $hash)->first()) return redirect()->to($known->publicUrl(), 303);
        $searchPeriod = $intent['period'];
        if (!empty($intent['delivery_destination'])) $searchPeriod += ['request_delivery' => 1, 'delivery_point' => $intent['delivery_destination']];
        $car = $search->search(VehiclePricelist::forPublicRental()->whereKey($intent['pricelist']), $searchPeriod)->first();
        app(PublicBookingService::class)->assertQuote($intent, $car);
        abort_unless($car['custom_delivery_enabled'], 422, 'Il noleggiatore non accetta richieste di consegna personalizzata per questo luogo.');
        $case = AmdRentEnquiry::firstOrCreate(['request_hash' => $hash], [
            'type' => 'delivery', 'reference' => 'CON-'.Str::upper(Str::random(12)), 'organization_id' => $car['supplier_id'],
            'customer_name' => trim($contact['first_name'].' '.$contact['last_name']), 'email' => $contact['email'], 'phone' => $contact['phone'],
            'delivery_address' => $data['delivery_address'], 'notes' => $data['delivery_notes'] ?? null,
            'vehicle_request' => $car['title'], 'booking_context' => ['pricelist' => $intent['pricelist'], 'period' => array_replace($intent['period'], ['place_id' => $car['place_id']]), 'contact' => $contact, 'car' => $car,
                'delivery_destination' => $intent['delivery_destination'] ?? null],
        ]);
        return redirect()->to($case->publicUrl(), 303);
    }
    public function show(string $reference)
    {
        $case = AmdRentEnquiry::with('quotes')->where('reference', $reference)->firstOrFail();
        return $this->page('public-cars.enquiry', compact('case'));
    }
    public function reopenDelivery(Request $request, string $reference, DeliveryBookingRecovery $recovery)
    {
        $case = AmdRentEnquiry::where('reference', $reference)->where('type', 'delivery')->firstOrFail();
        $data = $request->validate(['booking_id' => ['required', 'integer', 'min:1'], 'revision' => ['required', 'integer', 'min:1']]);
        try {
            $reopened = $recovery->reopen($case, (int) $data['booking_id'], (int) $data['revision']);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($case->publicUrl());
        }
        return redirect()->to($case->publicUrl(), 303)->with('status', $reopened
            ? 'Richiesta riaperta. Prima di riprovare controlla validità della proposta, prezzo e disponibilità. Nessun pagamento è stato avviato.'
            : 'La richiesta è già stata aggiornata. Controlla il riepilogo attuale.');
    }
    public function acceptDelivery(Request $request, string $reference, PublicVehicleSearch $search)
    {
        $request->validate(['accept_quote' => ['accepted']]);
        $case = AmdRentEnquiry::where('reference', $reference)->where('type', 'delivery')->firstOrFail();
        if ($case->public_booking_id) return redirect()->to($case->booking->confirmationUrl(), 303);
        $context = $case->booking_context;
        $searchPeriod = $context['period'];
        if (!empty($context['delivery_destination'])) $searchPeriod += ['request_delivery' => 1, 'delivery_point' => $context['delivery_destination']];
        $car = $search->search(VehiclePricelist::forPublicRental()->whereKey($context['pricelist']), $searchPeriod)->first();
        if (!$car) throw ValidationException::withMessages(['booking' => 'L’auto non è più disponibile. Contatta il noleggiatore per una nuova proposta.']);
        $car = app(DeliveryQuotes::class)->apply($car, $case);
        $intent = ['nonce' => (string) Str::uuid(), 'source' => 'pricelist', 'pricelist' => $context['pricelist'], 'period' => $context['period'],
            'preview' => false, 'delivery_request_id' => $case->id, 'fingerprint' => PublicBookingService::fingerprint($car), 'expires_at' => now()->addMinutes(30)->timestamp];
        if (!empty($context['delivery_destination'])) $intent['delivery_destination'] = $context['delivery_destination'];
        $known = $request->session()->get('public_booking_checkouts', []);
        $known[$intent['nonce']] = true;
        $request->session()->put('public_booking_checkouts', array_slice($known, -20, null, true));
        return $this->page('public-cars.booking', ['car' => $car, 'filters' => $context['period'], 'preview' => false, 'routePrefix' => 'public-cars',
            'contact' => $context['contact'],
            'checkoutToken' => Crypt::encryptString(json_encode($intent, JSON_THROW_ON_ERROR))]);
    }
    private function page(string $view, array $data)
    {
        return response()->view($view, $data)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
