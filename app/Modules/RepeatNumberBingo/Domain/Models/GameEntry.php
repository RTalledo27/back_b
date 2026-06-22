<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Models;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidEntryTransition;
use App\Modules\Shared\Domain\Exceptions\ImmutableModelException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Confirmed participation of a user in a game for a specific game_number.
 * Belongs to RepeatNumberBingo: the game engine resolves the winner from
 * this table without needing to know about Commerce entities.
 *
 * Immutability:
 *  - delete is blocked unconditionally
 *  - update is restricted: only status (via transitionTo) and updated_at
 *    may change. Any other dirty attribute throws ImmutableModelException.
 *
 * @property string $id
 * @property string $game_id
 * @property string $game_number_id
 * @property int $user_id
 * @property EntryStatus $status
 * @property Carbon $confirmed_at
 */
class GameEntry extends Model
{
    use HasUuids;

    protected $table = 'game_entries';

    protected $guarded = [];

    /**
     * Tracks whether the current pending status change went through the
     * authorised entry point (transitionTo) or was set by some other code
     * path. Reset by the `updated` hook after each save.
     */
    private bool $statusChangeAuthorized = false;

    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw ImmutableModelException::forModel(self::class, 'deleted');
        });

        static::updating(function (self $entry): void {
            $dirty = array_keys($entry->getDirty());
            $allowed = ['status', 'updated_at'];
            $disallowed = array_values(array_diff($dirty, $allowed));

            if ($disallowed !== []) {
                throw ImmutableModelException::forAttributes(self::class, $disallowed);
            }

            if (in_array('status', $dirty, true) && ! $entry->statusChangeAuthorized) {
                throw InvalidEntryTransition::uncontrolledStatusChange();
            }
        });

        static::updated(function (self $entry): void {
            $entry->statusChangeAuthorized = false;
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EntryStatus::class,
            'confirmed_at' => 'datetime',
        ];
    }

    public function transitionTo(EntryStatus $next): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw InvalidEntryTransition::from($this->status, $next);
        }

        $this->statusChangeAuthorized = true;
        $this->status = $next;
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
