<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Models;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Domain\Enums\GamePrizeFundingStatus;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidGamePrizeFundingTransition;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $game_id
 * @property GamePrizeFundingStatus $status
 * @property int $amount_cents
 * @property string $currency
 * @property ?int $funded_by_user_id
 * @property ?Carbon $funded_at
 * @property ?Carbon $reserved_at
 * @property ?Carbon $released_at
 * @property ?string $release_reason_code
 * @property ?string $idempotency_key_hash
 * @property ?string $request_fingerprint
 */
class GamePrizeFunding extends Model
{
    use HasUuids;

    protected $table = 'game_prize_fundings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => GamePrizeFundingStatus::class,
            'amount_cents' => 'integer',
            'funded_at' => 'datetime',
            'reserved_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function transitionTo(GamePrizeFundingStatus $next): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw InvalidGamePrizeFundingTransition::from($this->status, $next);
        }

        $this->status = $next;
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /** @return BelongsTo<User, $this> */
    public function fundedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'funded_by_user_id');
    }

    /** @return HasMany<GamePrizeFundingDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(GamePrizeFundingDocument::class);
    }

    /** @return HasMany<GamePrizeFundingEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(GamePrizeFundingEvent::class);
    }
}
