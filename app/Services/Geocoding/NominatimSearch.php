<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Facades\{Cache, Http};

class NominatimSearch
{
    public function search(string $query): array
    {
        $query = preg_replace('/\s+/u', ' ', trim($query));
        if (mb_strlen($query) < 3 || mb_strlen($query) > 500) return [];
        $endpoint = (string) config('geocoding.endpoint');
        if (!str_starts_with($endpoint, 'https://') || !config('geocoding.user_agent')) {
            throw new PlaceSearchUnavailable('La ricerca dei luoghi non è configurata. Riprova più tardi.');
        }
        $storeName = config('geocoding.cache_store') ?: config('cache.default');
        if (!app()->environment('testing') && in_array(config('cache.stores.'.$storeName.'.driver'), ['array', 'null', 'octane'], true)) {
            throw new PlaceSearchUnavailable('La ricerca dei luoghi non è disponibile. Riprova più tardi.');
        }
        $key = 'amd-geocoding:v1:'.hash('sha256', $endpoint.'|'.mb_strtolower($query));
        try {
            $cache = Cache::store(config('geocoding.cache_store'));
            if (is_array($cached = $cache->get($key))) return $cached;
            // A shared lock and clock gate cover all visitors, workers and both portals.
            $lock = $cache->lock('amd-geocoding:outbound', 15);
            if (!$lock->get()) throw PlaceSearchUnavailable::busy();
            try {
                if (is_array($cached = $cache->get($key))) return $cached;
                if (microtime(true) < (float) $cache->get('amd-geocoding:next-request', 0)) {
                    throw PlaceSearchUnavailable::busy();
                }
                $cache->put('amd-geocoding:next-request', microtime(true) + 1.1, 60);
                // Send only the location query. Never forward contacts, booking data or client IP.
                $response = Http::acceptJson()->withUserAgent(config('geocoding.user_agent'))
                    ->connectTimeout(2)->timeout(5)->withoutRedirecting()->get($endpoint, [
                        'q' => $query, 'format' => 'jsonv2', 'addressdetails' => 1,
                        'limit' => 5, 'accept-language' => 'it',
                    ]);
                if ($response->status() === 429 || $response->status() === 503) {
                    $cache->put('amd-geocoding:next-request', microtime(true) + 60, 120);
                }
                $data = $response->json();
                if (!$response->successful() || !is_array($data) || !array_is_list($data)) {
                    throw new PlaceSearchUnavailable('La ricerca dei luoghi non è disponibile. Riprova più tardi.');
                }
                $places = [];
                foreach (array_slice($data, 0, 5) as $row) {
                    if (!is_array($row) || !is_string($row['display_name'] ?? null)
                        || !is_numeric($row['lat'] ?? null) || !is_numeric($row['lon'] ?? null)
                        || !is_numeric($row['place_rank'] ?? null) || (int) $row['place_rank'] < 26) continue;
                    $lat = (float) $row['lat']; $lng = (float) $row['lon'];
                    if (!is_finite($lat) || !is_finite($lng) || abs($lat) > 90 || abs($lng) > 180) continue;
                    $label = mb_substr(preg_replace('/\s+/u', ' ', trim($row['display_name'])), 0, 500);
                    if (mb_strlen($label) < 8) continue;
                    $places[] = ['label' => $label, 'lat' => round($lat, 7), 'lng' => round($lng, 7)];
                }
                $cache->put($key, $places, $places ? now()->addDays(7) : now()->addMinutes(15));
                return $places;
            } finally {
                $lock->release();
            }
        } catch (PlaceSearchUnavailable $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            // Fail closed if the cache/lock or connection fails; do not leak queries in errors.
            throw new PlaceSearchUnavailable('La ricerca dei luoghi non è disponibile. Riprova più tardi.');
        }
    }
}
