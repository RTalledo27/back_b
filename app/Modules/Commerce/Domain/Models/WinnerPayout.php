<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Models;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutStatus;
use App\Modules\Commerce\Domain\Exceptions\InvalidWinnerPayoutTransition;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaim;
use App\Modules\Shared\Domain\Exceptions\ImmutableModelException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Auditable manual winner payout aggregate. Historical rows remain immutable
 * in the technical state legacy_registered.
 *
 * @property string $id
 * @property string $game_winner_id
 * @property string $game_id
 * @property int $user_id
 * @property int $amount_cents
 * @property string $currency
 * @property string $method
 * @property string|null $external_reference
 * @property string|null $notes
 * @property string|null $idempotency_key_hash
 * @property string|null $request_fingerprint
 * @property int|null $processed_by_user_id
 * @property Carbon|null $processed_at
 * @property Carbon $created_at
 * @property WinnerPayoutStatus $status
 */
class WinnerPayout extends Model
{
    use HasUuids;

    protected $table = 'winner_payouts';

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['idempotency_key_hash', 'request_fingerprint'];

    protected static function booted(): void
    {
        static::updating(function (self $payout): void {
            $originalStatus = $payout->getRawOriginal('status');

            if ($originalStatus === null || $originalStatus === WinnerPayoutStatus::LegacyRegistered->value) {
                throw ImmutableModelException::forModel(self::class, 'updated');
            }
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
            'status' => WinnerPayoutStatus::class,
            'processed_by_user_id' => 'integer',
            'user_id' => 'integer',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'processing_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function transitionTo(WinnerPayoutStatus $next): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw new InvalidWinnerPayoutTransition($this->status, $next);
        }

        $this->status = $next;
    }

    /**
     * @return BelongsTo<GameWinner, $this>
     */
    public function gameWinner(): BelongsTo
    {
        return $this->belongsTo(GameWinner::class, 'game_winner_id');
    }

    /** @return BelongsTo<WinnerClaim, $this> */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(WinnerClaim::class, 'winner_claim_id');
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

    /** @return HasMany<WinnerPayoutDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(WinnerPayoutDocument::class, 'payout_id');
    }

    /** @return HasMany<WinnerPayoutDestination, $this> */
    public function destinations(): HasMany
    {
        return $this->hasMany(WinnerPayoutDestination::class, 'winner_payout_id');
    }

    /** @return BelongsTo<WinnerPayoutDestination, $this> */
    public function currentDestination(): BelongsTo
    {
        return $this->belongsTo(WinnerPayoutDestination::class, 'current_destination_id');
    }

    /** @return HasMany<WinnerPayoutExecutionAttempt, $this> */
    public function executionAttempts(): HasMany
    {
        return $this->hasMany(WinnerPayoutExecutionAttempt::class, 'winner_payout_id');
    }

    /** @return BelongsTo<WinnerPayoutExecutionAttempt, $this> */
    public function currentExecutionAttempt(): BelongsTo
    {
        return $this->belongsTo(WinnerPayoutExecutionAttempt::class, 'current_execution_attempt_id');
    }

    /** @return HasMany<WinnerPayoutEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(WinnerPayoutEvent::class, 'winner_payout_id');
    }
}
