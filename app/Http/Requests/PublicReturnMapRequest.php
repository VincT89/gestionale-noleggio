<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PublicReturnMapRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'return_address' => ['required', 'string', 'min:8', 'max:500'],
            'map_lat' => ['required', 'numeric', 'between:-85.05112878,85.05112878'],
            'map_lng' => ['required', 'numeric', 'between:-180,180'],
            'map_zoom' => ['required', 'integer', 'between:16,19'],
            'map_confirmed' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'return_address.*' => 'Completa l’indirizzo di riconsegna con il comune, da 8 a 500 caratteri.',
            'map_lat.*' => 'Indica un punto valido sulla mappa.',
            'map_lng.*' => 'Indica un punto valido sulla mappa.',
            'map_zoom.*' => 'Ingrandisci fino a distinguere la strada e scegli il punto di riconsegna.',
            'map_confirmed.*' => 'Conferma il punto di riconsegna sulla mappa.',
        ];
    }
}
