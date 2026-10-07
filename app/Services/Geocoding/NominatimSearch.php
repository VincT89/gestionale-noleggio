<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Facades\{Cache, Http};

class NominatimSearch
{
    public function search(string $query): array
    {
        return $this->lookup($query, false);
    }

    /** Area results position the map only; they are never confirmed delivery points. */
    public function searchArea(string $query): array
    {
        return $this->lookup($query, true);
    }

    private function lookup(string $query, bool $includeAreas): array
    {
        $query = preg_replace('/\s+/u', ' ', trim($query));
        if (mb_strlen($query) < 3 || mb_strlen($query) > 500) return [];
        $endpoint = (string) config('geocoding.endpoint');
        $key = ($includeAreas ? 'amd-geocoding:areas:v1:' : 'amd-geocoding:v1:')
            .hash('sha256', $endpoint.'|'.mb_strtolower($query));
        return $this->fetch($endpoint, ['q' => $query, 'format' => 'jsonv2', 'addressdetails' => 1,
            'limit' => 5, 'accept-language' => 'it'], $key, $includeAreas);
    }

    /** Suggest the nearest address without replacing the customer's chosen coordinates. */
    public function reverse(float $lat, float $lng): ?string
    {
        if (!is_finite($lat) || !is_finite($lng) || abs($lat) > 85.05112878 || abs($lng) > 180) return null;
        $endpoint = (string) config('geocoding.reverse_endpoint');
        $coordinates = ['lat' => number_format($lat, 7, '.', ''), 'lon' => number_format($lng, 7, '.', '')];
        $key = 'amd-geocoding:reverse:v1:'.hash('sha256', $endpoint.'|'.implode('|', $coordinates));
        $places = $this->fetch($endpoint, $coordinates + ['format' => 'jsonv2', 'addressdetails' => 1,
            'zoom' => 18, 'accept-language' => 'it'], $key, false, true);
        return $places[0]['label'] ?? null;
    }

    private function fetch(string $endpoint, array $parameters, string $key, bool $includeAreas = false, bool $reverse = false): array
    {
        if (!str_starts_with($endpoint, 'https://') || !config('geocoding.user_agent')) {
            throw new PlaceSearchUnavailable('La ricerca dei luoghi non è configurata. Riprova più tardi.');
        }
        $storeName = config('geocoding.cache_store') ?: config('cache.default');
        if (!app()->environment('testing') && in_array(config('cache.stores.'.$storeName.'.driver'), ['array', 'null', 'octane'], true)) {
            throw new PlaceSearchUnavailable('La ricerca dei luoghi non è disponibile. Riprova più tardi.');
        }
        try {
            $cache = Cache::store(config('geocoding.cache_store'));
            if (is_array($cached = $cache->get($key))) return $cached;
            // A shared lock and clock gate cover all visitors, workers and both portals.
            $lock = $cache->lock('amd-geocoding:outbound', 15);
            if (!$lock->get()) throw PlaceSearchUnavailable::busy();
            try {
                if (is_array($cached = $cache->get($key))) return $cached;
                $wait = (float) $cache->get('amd-geocoding:next-request', 0) - microtime(true);
                if ($wait > 0) {
                    // A map click often immediately follows an area search. Wait only for
                    // the short shared interval, never through a provider cooldown.
                    if (!$reverse || $wait > 1.2) throw PlaceSearchUnavailable::busy();
                    usleep((int) ceil($wait * 1000000));
                }
                $cache->put('amd-geocoding:next-request', microtime(true) + 1.1, 60);
                // Send only the location query or coordinates, never contacts or booking data.
                $response = Http::acceptJson()->withUserAgent(config('geocoding.user_agent'))
                    ->connectTimeout(2)->timeout(5)->withoutRedirecting()->get($endpoint, $parameters);
                if ($response->status() === 429 || $response->status() === 503) {
                    $cache->put('amd-geocoding:next-request', microtime(true) + 60, 120);
                }
                $data = $response->json();
                $notMapped = $reverse && ($response->successful() || $response->status() === 404) && is_string($data['error'] ?? null);
                if (!$notMapped && (!$response->successful() || !is_array($data)
                    || (!$reverse && !array_is_list($data)) || ($reverse && array_is_list($data)))) {
                    throw new PlaceSearchUnavailable('La ricerca dei luoghi non è disponibile. Riprova più tardi.');
                }
                $places = [];
                foreach ($notMapped ? [] : ($reverse ? [$data] : array_slice($data, 0, 5)) as $row) {
                    if (!is_array($row) || !is_string($row['display_name'] ?? null)
                        || !is_numeric($row['lat'] ?? null) || !is_numeric($row['lon'] ?? null)
                        || !is_numeric($row['place_rank'] ?? null) || (int) $row['place_rank'] < ($includeAreas ? 8 : 26)) continue;
                    $lat = (float) $row['lat']; $lng = (float) $row['lon'];
                    if (!is_finite($lat) || !is_finite($lng) || abs($lat) > 90 || abs($lng) > 180) continue;
                    $label = mb_substr(preg_replace('/\s+/u', ' ', trim($row['display_name'])), 0, 500);
                    if (mb_strlen($label) < 8) continue;
                    $place = ['label' => $label, 'lat' => round($lat, 7), 'lng' => round($lng, 7)];
                    if ($includeAreas) {
                        $rank = (int) $row['place_rank'];
                        $place['zoom'] = $rank >= 26 ? 17 : ($rank >= 16 ? 13 : ($rank >= 12 ? 11 : 8));
                    }
                    $places[] = $place;
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
