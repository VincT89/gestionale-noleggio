<?php

namespace App\Http\Requests;

use App\Models\AmdRentEnquiry;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class PublicBookingRequest extends PublicCarSearchRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'first_name' => ['required', 'string', 'max:90'],
            'last_name' => ['required', 'string', 'max:90'],
            'email' => ['required', 'email:rfc', 'max:191'],
            'phone' => ['required', 'string', 'min:6', 'max:32', 'regex:/^[+0-9().\s-]+$/'],
            'checkout_token' => ['required', 'string', 'max:8192'],
            'accept_summary' => ['accepted'],
            'website' => ['nullable', 'string', 'max:0'],
            'request_delivery' => ['nullable', 'boolean'],
        ];
    }

    public function getRedirectUrl(): string
    {
        // Keep an accepted delivery proposal attached to its signed summary on errors.
        // Never trust a posted return URL or a checkout issued in another session.
        if ($summary = $this->deliverySummaryUrl()) return $summary;

        $period = [];
        foreach (['pickup_at', 'return_at'] as $field) {
            $value = $this->input($field);
            if (is_string($value) && strlen($value) < 30) $period[$field] = $value;
        }
        if (is_int($this->input('place_id')) && $this->input('place_id') > 0) $period['place_id'] = $this->input('place_id');
        $selection = $this->input('delivery_place');
        if (is_string($selection) && $point = app(\App\Services\Geocoding\PlaceSelection::class)->resolve($selection)) {
            $period += ['request_delivery' => 1, 'delivery_address' => $point['label'], 'delivery_place' => $selection];
        }
        return route(($this->routeIs('public-cars.preview.*') ? 'public-cars.preview' : 'public-cars').'.booking.create', ['pricelist' => $this->route('pricelist')] + $period);
    }

    private function deliverySummaryUrl(): ?string
    {
        $token = $this->input('checkout_token');
        if (!is_string($token) || strlen($token) > 8192) return null;
        try {
            $intent = json_decode(Crypt::decryptString($token), true, 32, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $exception) {
            return null;
        }
        if (!is_array($intent) || ($intent['source'] ?? null) !== 'pricelist'
            || ($intent['pricelist'] ?? null) !== (int) $this->route('pricelist')
            || ($intent['preview'] ?? null) !== $this->routeIs('public-cars.preview.*')
            || !is_string($intent['nonce'] ?? null)
            || !$this->session()->get('public_booking_checkouts.'.$intent['nonce'])
            || !is_int($intent['delivery_request_id'] ?? null)) return null;

        $case = AmdRentEnquiry::where('type', 'delivery')->find($intent['delivery_request_id']);
        return $case && (int) ($case->booking_context['pricelist'] ?? 0) === (int) $this->route('pricelist')
            ? $case->publicUrl() : null;
    }

    public function messages(): array
    {
        return parent::messages() + [
            'first_name.*' => 'Inserisci il nome, fino a 90 caratteri.',
            'last_name.*' => 'Inserisci il cognome, fino a 90 caratteri.',
            'email.*' => 'Inserisci un indirizzo email valido.',
            'phone.*' => 'Inserisci un numero di telefono valido.',
            'accept_summary.*' => 'Controlla e accetta il riepilogo prima di confermare.',
            'checkout_token.*' => 'Riapri il riepilogo della prenotazione.',
            'website.*' => 'Non è stato possibile inviare il modulo.',
        ];
    }
}
