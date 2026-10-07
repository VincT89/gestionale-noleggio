<?php

namespace App\Http\Requests;

use App\Http\Controllers\AmdRentEnquiryController;
use Illuminate\Foundation\Http\FormRequest;

class PublicLongTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return AmdRentEnquiryController::contactRules() + AmdRentEnquiryController::longTermRules() + [
            'request_token' => ['required', 'uuid'],
            'accept_contact' => ['accepted'],
            'website' => ['nullable', 'string', 'max:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Compila il campo :attribute.',
            'string' => 'Inserisci un testo valido nel campo :attribute.',
            'max' => 'Il campo :attribute non può superare :max caratteri.',
            'min' => 'Il campo :attribute deve contenere almeno :min caratteri.',
            'integer' => 'Inserisci un numero intero nel campo :attribute.',
            'between' => 'Il campo :attribute deve essere compreso tra :min e :max.',
            'email.email' => 'Inserisci un indirizzo email valido.',
            'phone.regex' => 'Inserisci un numero di telefono valido, con eventuale prefisso internazionale.',
            'customer_type.in' => 'Scegli se il noleggio è per un privato o un’azienda.',
            'company_name.required_if' => 'Inserisci la ragione sociale per una richiesta aziendale.',
            'request_token.required' => 'Il modulo è scaduto. Riapri la pagina per riprovare.',
            'request_token.uuid' => 'Il modulo è scaduto. Riapri la pagina per riprovare.',
            'accept_contact.accepted' => 'Conferma di voler essere ricontattato per questa richiesta.',
            'website.max' => 'Non è stato possibile inviare la richiesta. Riapri la pagina e riprova.',
        ];
    }

    public function attributes(): array
    {
        return [
            'customer_name' => 'nome e cognome', 'email' => 'email', 'phone' => 'telefono',
            'customer_type' => 'tipo di cliente', 'company_name' => 'ragione sociale',
            'vehicle_request' => 'auto desiderata', 'duration_months' => 'durata in mesi',
            'annual_km' => 'chilometri annui', 'notes' => 'informazioni aggiuntive',
            'website' => 'sito web',
        ];
    }
}
