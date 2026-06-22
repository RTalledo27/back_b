<?php

declare(strict_types=1);

return [

    'reservation' => [

        /*
        |--------------------------------------------------------------------------
        | Reservation TTL (minutes)
        |--------------------------------------------------------------------------
        |
        | How long a pending order's reservations remain valid before the
        | expiration job releases them. Source of truth lives here — never
        | accept this from a client payload.
        |
        */
        'ttl_minutes' => (int) env('COMMERCE_RESERVATION_TTL_MINUTES', 10),
    ],

    'evidence' => [

        /*
        |--------------------------------------------------------------------------
        | Private storage disk for payment evidence
        |--------------------------------------------------------------------------
        */
        'disk' => env('COMMERCE_EVIDENCE_DISK', 'payment_evidences'),

        /*
        |--------------------------------------------------------------------------
        | Maximum upload size (KB)
        |--------------------------------------------------------------------------
        */
        'max_size_kb' => (int) env('COMMERCE_EVIDENCE_MAX_SIZE_KB', 5120),

        /*
        |--------------------------------------------------------------------------
        | Allowed MIME types and the canonical extension for each
        |--------------------------------------------------------------------------
        |
        | The keys are the MIME types accepted from real, server-side
        | detection (finfo). The values are the extensions used to build
        | the deterministic storage path: `{payment_id}/{uuid}.{ext}`.
        | Anything outside this whitelist is rejected with 422.
        |
        */
        'mime_to_extension' => [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
        ],
    ],

    'idempotency' => [

        /*
        |--------------------------------------------------------------------------
        | Total lifetime of an idempotency_keys row (hours)
        |--------------------------------------------------------------------------
        */
        'ttl_hours' => (int) env('COMMERCE_IDEMPOTENCY_TTL_HOURS', 24),

        /*
        |--------------------------------------------------------------------------
        | In-progress timeout (seconds)
        |--------------------------------------------------------------------------
        |
        | A claimed row whose `completed_at` is still NULL after this many
        | seconds is treated as abandoned and may be reclaimed by a retry.
        |
        */
        'in_progress_timeout_seconds' => (int) env('COMMERCE_IDEMPOTENCY_IN_PROGRESS_TIMEOUT', 60),

        /*
        |--------------------------------------------------------------------------
        | Idempotency-Key header validation
        |--------------------------------------------------------------------------
        */
        'key' => [
            'min_length' => 16,
            'max_length' => 80,
            'allowed_pattern' => '/^[A-Za-z0-9_\-]+$/',
        ],
    ],

];
