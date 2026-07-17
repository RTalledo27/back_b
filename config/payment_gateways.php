<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Internal payment gateway foundation
    |--------------------------------------------------------------------------
    |
    | The only implementation currently available is the deterministic fake.
    | Real providers are intentionally not registered in this phase.
    |
    */
    'provider' => env('PAYMENT_GATEWAY_PROVIDER', 'fake'),
    'environment' => env('PAYMENT_GATEWAY_ENV', 'sandbox'),
    'http_enabled' => (bool) env('PAYMENT_GATEWAY_HTTP_ENABLED', false),
    'webhook_tolerance_seconds' => (int) env('PAYMENT_GATEWAY_WEBHOOK_TOLERANCE_SECONDS', 300),
    'webhook_max_body_bytes' => (int) env('PAYMENT_GATEWAY_WEBHOOK_MAX_BODY_BYTES', 65536),

    'credentials' => [
        'public_key' => env('PAYMENT_GATEWAY_PUBLIC_KEY'),
        'secret_key' => env('PAYMENT_GATEWAY_SECRET_KEY'),
        'webhook_secret' => env('PAYMENT_GATEWAY_WEBHOOK_SECRET'),
    ],

];
