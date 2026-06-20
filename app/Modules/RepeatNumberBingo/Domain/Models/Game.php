<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Models;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidGameTransition;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $slug
 * @property string $name
 * @property ?string $description
 * @property int $number_min
 * @property int $number_max
 * @property int $hits_required
 * @property int $ticket_price_cents
 * @property int $prize_cents
 * @property string $currency
 * @property ?\Illuminate\Support\Carbon $sales_opens_at
 * @property ?\Illuminate\Support\Carbon $sales_closes_at
 * @property ?\Illuminate\Support\Carbon $scheduled_start_at
 * @property int $draw_interval_seconds
 * @property bool $auto_draw_enabled
 * @property GameStatus $status
 * @property ?array<string,mixed> $settings
 * @property ?int $created_by
 */
class Game extends Model
{
    use HasUuids;

    protected $table = 'games';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number_min' => 'integer',
            'number_max' => 'integer',
            'hits_required' => 'integer',
            'ticket_price_cents' => 'integer',
            'prize_cents' => 'integer',
            'draw_interval_seconds' => 'integer',
            'auto_draw_enabled' => 'boolean',
            'sales_opens_at' => 'datetime',
            'sales_closes_at' => 'datetime',
            'scheduled_start_at' => 'datetime',
            'settings' => 'array',
            'status' => GameStatus::class,
        ];
    }

    /**
     * Single source of truth for valid state changes lives in GameStatus.
     * The model only enforces the rule and lets the caller persist.
     */
    public function transitionTo(GameStatus $next): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw InvalidGameTransition::from($this->status, $next);
        }

        $this->status = $next;
    }

    /**
     * @return HasMany<GameNumber, $this>
     */
    public function numbers(): HasMany
    {
        return $this->hasMany(GameNumber::class);
    }

    /**
     * @return HasMany<GameEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(GameEvent::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
