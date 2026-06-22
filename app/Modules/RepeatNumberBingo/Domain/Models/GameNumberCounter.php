<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mutable projection over game_draws. Rebuildable from scratch by
 * RebuildGameNumberCountersAction (Block 3.7).
 *
 * @property string $id
 * @property string $game_id
 * @property string $game_number_id
 * @property int $hits_count
 * @property ?int $last_draw_sequence
 */
class GameNumberCounter extends Model
{
    use HasUuids;

    protected $table = 'game_number_counters';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hits_count' => 'integer',
            'last_draw_sequence' => 'integer',
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
}
