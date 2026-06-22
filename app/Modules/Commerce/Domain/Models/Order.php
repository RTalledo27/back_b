<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Models;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Exceptions\InvalidOrderTransition;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property string $game_id
 * @property OrderStatus $status
 * @property int $subtotal_cents
 * @property int $total_cents
 * @property string $currency
 * @property ?Carbon $expires_at
 * @property ?Carbon $paid_at
 * @property ?Carbon $cancelled_at
 * @property ?Carbon $expired_at
 * @property ?array<string,mixed> $metadata
 */
class Order extends Model
{
    use HasUuids;

    protected $table = 'orders';

    protected $guarded = [];

    /**
     * Defaults mirroring the migration. Keeps the in-memory model consistent
     * with the DB row right after Model::create() without needing refresh().
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subtotal_cents' => 'integer',
            'total_cents' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expired_at' => 'datetime',
            'metadata' => 'array',
            'status' => OrderStatus::class,
        ];
    }

    public function transitionTo(OrderStatus $next): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw InvalidOrderTransition::from($this->status, $next);
        }

        $this->status = $next;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<NumberReservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(NumberReservation::class);
    }

    /**
     * @return HasOne<Payment, $this>
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }
}
