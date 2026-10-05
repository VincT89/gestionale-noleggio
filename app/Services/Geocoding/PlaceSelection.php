<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Str;

class PlaceSelection
{
    public function issue(array $places, string $context = 'public'): array
    {
        $known = array_filter(session('amd_place_selections', []), fn ($item) => ($item['issued_at'] ?? 0) > now()->subHours(2)->timestamp);
        $choices = [];
        foreach ($places as $place) {
            $token = (string) Str::uuid();
            $known[$token] = ['point' => $place, 'context' => $context, 'issued_at' => now()->timestamp];
            $choices[] = ['token' => $token, 'label' => $place['label']];
        }
        session()->put('amd_place_selections', array_slice($known, -30, null, true));
        return $choices;
    }

    public function resolve(?string $token, ?string $label = null, string $context = 'public'): ?array
    {
        if (!$token || !Str::isUuid($token)) return null;
        $item = session('amd_place_selections.'.$token);
        if (!is_array($item) || ($item['context'] ?? null) !== $context
            || ($item['issued_at'] ?? 0) <= now()->subHours(2)->timestamp) return null;
        $point = $item['point'];
        return $label === null || hash_equals($point['label'], $label) ? $point : null;
    }
}
