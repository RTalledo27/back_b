<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Models;

use App\Modules\Shared\Domain\Exceptions\ImmutableModelException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only idempotency record for the engine's draw command. The row
 * is INSERTed in its final form inside the draw transaction; if anything
 * fails before COMMIT the row never exists. Therefore there is no
 * "pending" state and no recovery path.
 *
 * @property string $id
 * @property string $game_id
 * @property string $command_id
 * @property string $game_draw_id
 * @property array<string,mixed> $result_payload
 * @property Carbon $completed_at
 * @property Carbon $created_at
 */
class DrawCommand extends Model
{
    use HasUuids;

    protected $table = 'draw_commands';

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
            'result_payload' => 'array',
            'completed_at' => 'datetime',
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
     * @return BelongsTo<GameDraw, $this>
     */
    public function draw(): BelongsTo
    {
        return $this->belongsTo(GameDraw::class, 'game_draw_id');
    }
}
