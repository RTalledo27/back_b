<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Models;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Domain\Enums\WinnerClaimStatus;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidWinnerClaimTransition;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Claim lifecycle separated from GameWinner, funding and payout aggregates.
 *
 * @property string $id
 * @property string $game_winner_id
 * @property int $winner_user_id
 * @property string $claim_reference
 * @property WinnerClaimStatus $status
 * @property Carbon|null $claim_window_started_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $claimed_at
 * @property Carbon|null $identity_submitted_at
 * @property Carbon|null $verified_at
 * @property Carbon|null $rejected_at
 * @property Carbon|null $expired_at
 * @property int|null $reviewed_by_user_id
 * @property bool $is_legacy
 */
final class WinnerClaim extends Model
{
    use HasUuids;

    protected $table = 'winner_claims';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => WinnerClaimStatus::class,
            'claim_window_started_at' => 'datetime',
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
            'identity_submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'rejected_at' => 'datetime',
            'expired_at' => 'datetime',
            'is_legacy' => 'boolean',
        ];
    }

    public function transitionTo(WinnerClaimStatus $next): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw new InvalidWinnerClaimTransition($this->status, $next);
        }

        $this->status = $next;
    }

    public function gameWinner(): BelongsTo
    {
        return $this->belongsTo(GameWinner::class);
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function identityProfile(): HasOne
    {
        return $this->hasOne(WinnerIdentityProfile::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(WinnerIdentityDocument::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(WinnerClaimEvent::class);
    }
}
