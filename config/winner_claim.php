<?php

declare(strict_types=1);

return [
    'ttl_days' => (int) env('WINNER_CLAIM_TTL_DAYS', 30),

    'identity' => [
        'document_types' => ['dni', 'ce', 'passport', 'other'],
        'max_documents' => 3,
        'max_size_kb' => (int) env('WINNER_IDENTITY_MAX_SIZE_KB', 5120),
        'disk' => env('WINNER_IDENTITY_DISK', 'winner_identity_documents'),
        'mime_to_extension' => [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
        ],
    ],

    'rejection_reason_codes' => [
        'identity_mismatch',
        'document_unreadable',
        'document_incomplete',
        'duplicate_claim',
        'other_review_reason',
    ],
];
