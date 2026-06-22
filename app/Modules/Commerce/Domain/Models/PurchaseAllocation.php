<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Models;

use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\Shared\Domain\Exceptions\ImmutableModelException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cross-module link allowed by the dependency direction Commerce -> RNB.
 *
 * Append-only: created at allocation time and never modified. Provides
 * traceability between an order line and its confirmed game entry, plus
 * the originating payment.
 *
 * @property string $id
 * @property string $order_item_id
 * @property string $game_entry_id
 * @property string $payment_id
 * @property Carbon $created_at
 */
class PurchaseAllocation extends Model
{
    use HasUuids;

    protected $table = 'purchase_allocations';

    protected $guarded = [];

    public const UPDATED_AT = null;

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
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * @return BelongsTo<GameEntry, $this>
     */
    public function gameEntry(): BelongsTo
    {
        return $this->belongsTo(GameEntry::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
