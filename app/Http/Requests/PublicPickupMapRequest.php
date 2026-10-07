<?php

namespace App\Http\Requests;

class PublicPickupMapRequest extends PublicCarSearchRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        // This explicit choice creates a new session selection; do not reuse an old token.
        $this->merge(['delivery_place' => null]);
    }

    public function rules(): array
    {
        $rules = parent::rules();
        foreach (['pickup_at', 'return_at'] as $field) {
            $rules[$field] = array_merge(['required'], array_diff($rules[$field], ['nullable']));
        }
        return array_replace($rules, [
            'request_delivery' => ['required', 'accepted'],
            'delivery_address' => ['required', 'string', 'min:8', 'max:500'],
            'map_lat' => ['required', 'numeric', 'between:-85.05112878,85.05112878'],
            'map_lng' => ['required', 'numeric', 'between:-180,180'],
            'map_zoom' => ['required', 'integer', 'between:16,19'],
            'map_confirmed' => ['required', 'accepted'],
        ]);
    }

    public function messages(): array
    {
        return array_replace(parent::messages(), [
            'delivery_address.*' => 'Completa l’indirizzo di ritiro con almeno 8 caratteri, includendo il comune.',
            'map_lat.*' => 'Indica un punto valido sulla mappa.',
            'map_lng.*' => 'Indica un punto valido sulla mappa.',
            'map_zoom.*' => 'Ingrandisci la mappa fino a distinguere la strada e scegli il punto di ritiro.',
            'map_confirmed.*' => 'Conferma il punto di ritiro sulla mappa.',
        ]);
    }
}
