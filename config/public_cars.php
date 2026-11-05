<?php

return [
    'max_rental_days' => 365,
    'per_page' => 12,
    // Host only, without protocol or path. Empty keeps the local /cerca-auto entry point.
    'domain' => env('AMD_RENT_DOMAIN') ?: null,
    'management_url' => env('AMD_RENT_MANAGEMENT_URL') ?: env('APP_URL', 'http://localhost'),
    'contact_email' => env('AMD_RENT_CONTACT_EMAIL'),
    'contact_phone' => env('AMD_RENT_CONTACT_PHONE'),
    'privacy_url' => env('AMD_RENT_PRIVACY_URL'),
    'legal_notice' => env('AMD_RENT_LEGAL_NOTICE'),
];
