<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Models;

use App\Models\User;
use App\Modules\Shared\Domain\Exceptions\ImmutableModelException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only record of an admin-initiated full refund.
 *
 * @property string $id
 * @property string $order_id
 * @property string $payment_id
 * @property int $amount_cents
 * @property string $currency
 * @property string $reason
 * @property string $idempotency_key_hash
 * @property string $request_fingerprint
 * @property int $processed_by_user_id
 * @property Carbon $processed_at
 * @property Carbon $created_at
 */
class Refund extends Model
{
    use HasUuids;

    protected $table = 'refunds';

    protected $guarded = [];

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $hidden = ['idempotency_key_hash', 'request_fingerprint'];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw ImmutableModelException::forModel(self::class, 'updated');
        });

        static::deleting(function (): void {
            throw ImmutableModelException::forModel(self::class, 'deleted');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'processed_by_user_id' => 'integer',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_user_id');
    }
}
