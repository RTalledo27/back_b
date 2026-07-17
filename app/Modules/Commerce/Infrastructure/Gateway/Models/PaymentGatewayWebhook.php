<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Gateway\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PaymentGatewayWebhook extends Model
{
    use HasUuids;

    protected $table = 'payment_gateway_webhooks';

    protected $guarded = [];

    protected $hidden = [
        'payload_hash',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'signature_verified' => 'boolean',
            'normalized_status' => 'string',
            'amount_cents' => 'integer',
            'occurred_at' => 'datetime',
            'processing_attempts' => 'integer',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}
