<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Models;

use App\Modules\Shared\Domain\Exceptions\ImmutableModelException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Canonical append-only history of every number drawn for a game.
 *
 * @property string $id
 * @property string $game_id
 * @property string $game_number_id
 * @property int $sequence
 * @property int $drawn_number
 * @property Carbon $drawn_at
 * @property string $strategy
 * @property Carbon $created_at
 */
class GameDraw extends Model
{
    use HasUuids;

    protected $table = 'game_draws';

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
            'sequence' => 'integer',
            'drawn_number' => 'integer',
            'drawn_at' => 'datetime',
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
     * @return BelongsTo<GameNumber, $this>
     */
    public function gameNumber(): BelongsTo
    {
        return $this->belongsTo(GameNumber::class);
    }

    /**
     * @return HasOne<GameWinner, $this>
     */
    public function winner(): HasOne
    {
        return $this->hasOne(GameWinner::class);
    }
}
