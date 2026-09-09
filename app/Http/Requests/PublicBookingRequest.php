<?php

namespace App\Http\Requests;

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
        ];
    }

    protected function getRedirectUrl(): string
    {
        $period = [];
        foreach (['pickup_at', 'return_at'] as $field) {
            $value = $this->input($field);
            if (is_string($value) && strlen($value) < 30) $period[$field] = $value;
        }
        return route(($this->routeIs('public-cars.preview.*') ? 'public-cars.preview' : 'public-cars').'.booking.create', ['offer' => $this->route('offer')] + $period);
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
