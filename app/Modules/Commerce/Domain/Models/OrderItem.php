<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Models;

use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $order_id
 * @property string $game_number_id
 * @property int $unit_price_cents
 */
class OrderItem extends Model
{
    use HasUuids;

    protected $table = 'order_items';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price_cents' => 'integer',
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
     * @return BelongsTo<GameNumber, $this>
     */
    public function gameNumber(): BelongsTo
    {
        return $this->belongsTo(GameNumber::class);
    }
}
