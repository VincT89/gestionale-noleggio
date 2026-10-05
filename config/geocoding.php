<?php

return [
    // Public Nominatim: explicit searches only; never use this endpoint for autocomplete.
    // https://operations.osmfoundation.org/policies/nominatim/
    'endpoint' => env('AMD_RENT_GEOCODING_URL', 'https://nominatim.openstreetmap.org/search'),
    'user_agent' => env('AMD_RENT_GEOCODING_USER_AGENT', 'AMD-Rent/1.0 (pickup and delivery place search)'),
    // All application instances must share this cache and its atomic locks.
    'cache_store' => env('AMD_RENT_GEOCODING_CACHE_STORE'),
];
