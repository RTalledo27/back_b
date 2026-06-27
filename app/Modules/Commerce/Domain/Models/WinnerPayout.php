<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Models;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\Shared\Domain\Exceptions\ImmutableModelException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Append-only record of an admin-initiated manual winner payout.
 *
 * @property string $id
 * @property string $game_winner_id
 * @property string $game_id
 * @property int $user_id
 * @property int $amount_cents
 * @property string $currency
 * @property string $method
 * @property string $external_reference
 * @property string|null $notes
 * @property string $idempotency_key_hash
 * @property string $request_fingerprint
 * @property int $processed_by_user_id
 * @property Carbon $processed_at
 * @property Carbon $created_at
 */
class WinnerPayout extends Model
{
    use HasUuids;

    protected $table = 'winner_payouts';

    protected $guarded = [];

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $hidden = ['idempotency_key_hash', 'request_fingerprint'];

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
            'amount_cents' => 'integer',
            'processed_by_user_id' => 'integer',
            'user_id' => 'integer',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<GameWinner, $this>
     */
    public function gameWinner(): BelongsTo
    {
        return $this->belongsTo(GameWinner::class, 'game_winner_id');
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
    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_user_id');
    }

    /**
     * @return HasOne<WinnerPayoutDocument, $this>
     */
    public function document(): HasOne
    {
        return $this->hasOne(WinnerPayoutDocument::class, 'payout_id');
    }
}
