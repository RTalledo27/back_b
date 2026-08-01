<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Models;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutDisputeStatus;
use App\Modules\Shared\Domain\Exceptions\ImmutableModelException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WinnerPayoutDispute extends Model
{
    use HasUuids;

    protected $table = 'winner_payout_disputes';

    protected $guarded = [];

    protected $hidden = ['description_encrypted', 'idempotency_key_hash', 'request_fingerprint'];

    private bool $allowLifecycleUpdate = false;

    protected static function booted(): void
    {
        static::updating(function (self $dispute): void {
            if (! $dispute->allowLifecycleUpdate) {
                throw ImmutableModelException::forModel(self::class, 'updated');
            }
        });

        static::deleting(function (): void {
            throw ImmutableModelException::forModel(self::class, 'deleted');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => WinnerPayoutDisputeStatus::class,
            'description_encrypted' => 'encrypted',
            'opened_at' => 'datetime',
            'review_started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function transitionTo(WinnerPayoutDisputeStatus $next): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw new \LogicException('Invalid winner payout dispute transition.');
        }

        $this->status = $next;
        $this->allowLifecycleUpdate = true;
    }

    /** @return BelongsTo<WinnerPayout, $this> */
    public function payout(): BelongsTo
    {
        return $this->belongsTo(WinnerPayout::class, 'winner_payout_id');
    }

    /** @return BelongsTo<User, $this> */
    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
