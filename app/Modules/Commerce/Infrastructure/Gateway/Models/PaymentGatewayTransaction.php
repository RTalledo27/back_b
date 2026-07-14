<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Gateway\Models;

use App\Modules\Commerce\Domain\Models\Payment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PaymentGatewayTransaction extends Model
{
    use HasUuids;

    protected $table = 'payment_gateway_transactions';

    protected $guarded = [];

    protected $hidden = [
        'raw_reference_hash',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'authorized_at' => 'datetime',
            'captured_at' => 'datetime',
            'failed_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return BelongsTo<PaymentGatewayAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaymentGatewayAttempt::class, 'payment_gateway_attempt_id');
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
