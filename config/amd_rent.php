<?php

return [
    // Existing bookings retain their accepted payment method. New bookings use Stripe.
    'payment_mode' => env('AMD_RENT_PAYMENT_MODE', 'stripe'),
    'stripe_secret' => env('AMD_RENT_STRIPE_SECRET'),
    'stripe_webhook_secret' => env('AMD_RENT_STRIPE_WEBHOOK_SECRET'),
    'stripe_live' => (bool) env('AMD_RENT_STRIPE_LIVE', false),
];
