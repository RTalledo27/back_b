<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Gateway\Models;

use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\Payment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PaymentGatewayAttempt extends Model
{
    use HasUuids;

    protected $table = 'payment_gateway_attempts';

    protected $guarded = [];

    protected $hidden = [
        'idempotency_key_hash',
        'request_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return HasMany<PaymentGatewayTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentGatewayTransaction::class);
    }
}
