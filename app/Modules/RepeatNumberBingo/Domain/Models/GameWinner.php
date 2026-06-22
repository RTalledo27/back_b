<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Models;

use App\Models\User;
use App\Modules\Shared\Domain\Exceptions\ImmutableModelException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only winner declaration. Exactly one row per game enforced by
 * UNIQUE(game_id) plus the partial unique index on game_entries.status.
 *
 * @property string $id
 * @property string $game_id
 * @property string $game_entry_id
 * @property string $game_draw_id
 * @property string $game_number_id
 * @property int $user_id
 * @property int $winning_hits
 * @property Carbon $won_at
 * @property Carbon $created_at
 */
class GameWinner extends Model
{
    use HasUuids;

    protected $table = 'game_winners';

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
            'winning_hits' => 'integer',
            'won_at' => 'datetime',
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
     * @return BelongsTo<GameEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(GameEntry::class, 'game_entry_id');
    }

    /**
     * @return BelongsTo<GameDraw, $this>
     */
    public function draw(): BelongsTo
    {
        return $this->belongsTo(GameDraw::class, 'game_draw_id');
    }

    /**
     * @return BelongsTo<GameNumber, $this>
     */
    public function gameNumber(): BelongsTo
    {
        return $this->belongsTo(GameNumber::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
