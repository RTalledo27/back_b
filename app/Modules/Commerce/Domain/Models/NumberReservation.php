<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Models;

use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Active-hold record for a game_number. Owner and expiration live on the
 * parent Order — single source of truth, no duplication here.
 *
 * @property string $id
 * @property string $order_id
 * @property string $game_number_id
 */
class NumberReservation extends Model
{
    use HasUuids;

    protected $table = 'number_reservations';

    protected $guarded = [];

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
