<?php

namespace App\Http\Requests;

use App\Models\AmdRentEnquiry;
use App\Services\AmdRent\DriverAgePricing;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class PublicBookingRequest extends PublicCarSearchRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        foreach (['billing_country', 'tax_code', 'vat_number', 'sdi_code'] as $field) {
            if (is_string($this->input($field))) $this->merge([$field => mb_strtoupper(trim($this->input($field)))]);
        }
        foreach (['company_name', 'billing_address', 'billing_city', 'billing_postal_code', 'pec'] as $field) {
            if (is_string($this->input($field))) $this->merge([$field => trim($this->input($field))]);
        }
    }

    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'first_name' => ['required', 'string', 'max:90'],
            'last_name' => ['required', 'string', 'max:90'],
            'email' => ['required', 'email:rfc', 'max:191'],
            'phone' => ['required', 'string', 'min:6', 'max:32', 'regex:/^[+0-9().\s-]+$/'],
            'birthdate' => ['bail', 'required', 'string', 'date_format:Y-m-d', 'before_or_equal:today'],
            'checkout_token' => ['required', 'string', 'max:8192'],
            'extras' => ['sometimes', 'array', 'list', 'max:100'],
            'extras.*' => ['required', 'integer', 'min:1', 'distinct'],
            'displayed_total_cents' => ['nullable', 'integer', 'min:0', 'max:9999999999'],
            'accept_summary' => ['accepted'],
            'accept_privacy' => ['accepted'],
            'driver_age_band' => ['required', \Illuminate\Validation\Rule::in(array_keys(\App\Services\AmdRent\DriverAgePricing::BANDS))],
            'booking_customer_type' => ['required', 'in:private,company'],
            'company_name' => ['exclude_unless:booking_customer_type,company', 'required', 'string', 'max:191'],
            'vat_number' => ['exclude_unless:booking_customer_type,company', 'required', 'string', 'max:32', 'regex:/^[A-Z0-9 .-]+$/D'],
            'tax_code' => [\Illuminate\Validation\Rule::requiredIf($this->input('booking_customer_type') === 'private' && $this->input('billing_country') === 'IT'), 'nullable', 'string', 'max:32', 'regex:/^[A-Z0-9]+$/D'],
            'billing_address' => ['required', 'string', 'max:191'],
            'billing_city' => ['required', 'string', 'max:128'],
            'billing_postal_code' => ['required', 'string', 'max:20'],
            'billing_country' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/D'],
            'sdi_code' => ['exclude_unless:booking_customer_type,company', 'nullable', 'string', 'size:7', 'regex:/^[A-Z0-9]+$/D'],
            'pec' => ['exclude_unless:booking_customer_type,company', 'nullable', 'email:rfc', 'max:191'],
            'website' => ['nullable', 'string', 'max:0'],
            'request_delivery' => ['nullable', 'boolean'],
        ]);
    }

    public function withValidator($validator): void
    {
        parent::withValidator($validator);
        $validator->after(function ($validator) {
            if ($validator->errors()->hasAny(['birthdate', 'pickup_at', 'driver_age_band'])) return;
            $age = DriverAgePricing::ageAtPickup($this->input('birthdate'), $this->input('pickup_at'));
            $band = DriverAgePricing::bandForAge($age);
            if ($band === null) {
                $validator->errors()->add('birthdate', 'Il conducente deve avere almeno 18 anni alla data del ritiro.');
            } elseif ($band !== $this->input('driver_age_band')) {
                $validator->errors()->add('driver_age_band', 'La data di nascita indica '.$age.' anni al ritiro: fascia «'.DriverAgePricing::BANDS[$band].'». Aggiorna la fascia e il preventivo prima di confermare.');
            }
        });
    }

    public function getRedirectUrl(): string
    {
        // Keep an accepted delivery proposal attached to its signed summary on errors.
        // Never trust a posted return URL or a checkout issued in another session.
        if ($summary = $this->deliverySummaryUrl()) return $summary;

        $intent = $this->checkoutIntent();
        $period = $intent['browse_filters'] ?? [];
        foreach (['pickup_at', 'return_at'] as $field) {
            $value = $this->input($field);
            if (!isset($period[$field]) && is_string($value) && strlen($value) < 30) $period[$field] = $value;
        }
        if (!isset($intent['browse_filters']) && is_int($this->input('place_id')) && $this->input('place_id') > 0) $period['place_id'] = $this->input('place_id');
        $selection = $this->input('delivery_place');
        if (is_string($selection) && $point = app(\App\Services\Geocoding\PlaceSelection::class)->resolve($selection)) {
            $period += ['request_delivery' => 1, 'delivery_address' => $point['label'], 'delivery_place' => $selection];
        }
        if (!empty($period['request_delivery']) && ($returnAddress = ($intent['return_address'] ?? null))) {
            $period += ['request_custom_return' => 1, 'return_address' => $returnAddress];
            if (!empty($intent['return_destination'])) {
                $places = app(\App\Services\Geocoding\PlaceSelection::class);
                $token = $this->input('return_place');
                $point = is_string($token) ? $places->resolve($token, $returnAddress, 'public-return') : null;
                $period['return_place'] = $point == $intent['return_destination'] ? $token
                    : $places->issue([$intent['return_destination']], 'public-return')[0]['token'];
            }
        }
        return route(($this->routeIs('public-cars.preview.*') ? 'public-cars.preview' : 'public-cars').'.booking.create', ['pricelist' => $this->route('pricelist')] + $period);
    }

    private function deliverySummaryUrl(): ?string
    {
        $intent = $this->checkoutIntent();
        if (!is_int($intent['delivery_request_id'] ?? null)) return null;
        $case = AmdRentEnquiry::where('type', 'delivery')->find($intent['delivery_request_id']);
        return $case && (int) ($case->booking_context['pricelist'] ?? 0) === (int) $this->route('pricelist')
            ? $case->publicUrl() : null;
    }

    private function checkoutIntent(): ?array
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
            || !$this->session()->get('public_booking_checkouts.'.$intent['nonce'])) return null;
        // Browsing state belongs to the issued checkout, never to posted return URLs.
        // Keep it in the session so long search filters do not inflate the checkout token.
        $known = $this->session()->get('public_booking_checkouts.'.$intent['nonce']);
        if (is_array($known) && isset($known['browse_filters'])) $intent['browse_filters'] = $known['browse_filters'];
        return $intent;
    }

    public function messages(): array
    {
        return array_replace(parent::messages(), [
            'first_name.*' => 'Inserisci il nome, fino a 90 caratteri.',
            'last_name.*' => 'Inserisci il cognome, fino a 90 caratteri.',
            'email.*' => 'Inserisci un indirizzo email valido.',
            'phone.*' => 'Inserisci un numero di telefono valido.',
            'birthdate.*' => 'Inserisci una data di nascita valida, non successiva a oggi.',
            'accept_summary.*' => 'Controlla e accetta il riepilogo prima di confermare.',
            'accept_privacy.*' => 'Conferma di aver preso visione dell’informativa privacy.',
            'booking_customer_type.*' => 'Scegli se prenotare come privato oppure come azienda.',
            'company_name.*' => 'Inserisci la ragione sociale dell’azienda.',
            'vat_number.*' => 'Inserisci la partita IVA o l’identificativo fiscale dell’azienda.',
            'tax_code.*' => 'Inserisci un codice fiscale valido.',
            'billing_address.*' => 'Inserisci l’indirizzo di fatturazione.',
            'billing_city.*' => 'Inserisci la città di fatturazione.',
            'billing_postal_code.*' => 'Inserisci il codice postale.',
            'billing_country.*' => 'Indica il codice paese di due lettere, per esempio IT.',
            'sdi_code.*' => 'Il codice destinatario deve contenere 7 lettere o numeri.',
            'pec.*' => 'Inserisci un indirizzo PEC valido oppure lascia vuoto il campo.',
            'checkout_token.*' => 'Riapri il riepilogo della prenotazione.',
            'extras.*' => 'Controlla gli extra selezionati.',
            'website.*' => 'Non è stato possibile inviare il modulo.',
        ]);
    }
}
