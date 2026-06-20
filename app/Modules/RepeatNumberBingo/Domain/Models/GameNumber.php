<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Models;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
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
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
