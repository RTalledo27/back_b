<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Models;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Domain\Enums\GamePrizeFundingEventType;
use App\Modules\Shared\Domain\Exceptions\ImmutableModelException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GamePrizeFundingEvent extends Model
{
    use HasUuids;

    protected $table = 'game_prize_funding_events';

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

    protected function casts(): array
    {
        return [
            'event_type' => GamePrizeFundingEventType::class,
            'safe_metadata' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GamePrizeFunding, $this> */
    public function funding(): BelongsTo
    {
        return $this->belongsTo(GamePrizeFunding::class, 'game_prize_funding_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
