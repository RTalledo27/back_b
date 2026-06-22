<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Models;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidGameNumberTransition;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $game_id
 * @property int $number
 * @property GameNumberStatus $status
 */
class GameNumber extends Model
{
    use HasUuids;

    protected $table = 'game_numbers';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => GameNumberStatus::class,
        ];
    }

    /**
     * Single source of truth for valid status changes lives in
     * GameNumberStatus::canTransitionTo. Actions must use this method
     * instead of assigning $status directly.
     */
    public function transitionTo(GameNumberStatus $next): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw InvalidGameNumberTransition::from($this->status, $next);
        }

        $this->status = $next;
    }

    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
