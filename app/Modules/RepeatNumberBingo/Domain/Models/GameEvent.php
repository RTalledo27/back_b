<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Models;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\Shared\Domain\Exceptions\ImmutableModelException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit row. Has only created_at — never updated.
 *
 * @property string $id
 * @property string $game_id
 * @property GameEventType $type
 * @property ?array<string,mixed> $payload
 * @property ?int $actor_user_id
 * @property \Illuminate\Support\Carbon $occurred_at
 * @property \Illuminate\Support\Carbon $created_at
 */
class GameEvent extends Model
{
    use HasUuids;

    protected $table = 'game_events';

    protected $guarded = [];

    public const UPDATED_AT = null;

    /**
     * Append-only enforcement at the ORM layer. Eloquent-mediated update or
     * delete throws ImmutableModelException. Direct SQL / Query Builder
     * bypasses this guard — a PostgreSQL trigger is documented as a future
     * defense-in-depth improvement.
     */
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
            'type' => GameEventType::class,
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
