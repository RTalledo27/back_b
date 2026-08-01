<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Models;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutExecutionAttemptStatus;
use App\Modules\Commerce\Domain\Exceptions\InvalidWinnerPayoutTransition;
use App\Modules\Shared\Domain\Exceptions\ImmutableModelException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WinnerPayoutExecutionAttempt extends Model
{
    use HasUuids;

    protected $table = 'winner_payout_execution_attempts';

    protected $guarded = [];

    public const UPDATED_AT = null;

    protected $hidden = ['external_reference_encrypted', 'idempotency_key_hash', 'request_fingerprint'];

    private bool $allowLifecycleUpdate = false;

    protected static function booted(): void
    {
        static::updating(function (self $attempt): void {
            if (! $attempt->allowLifecycleUpdate) {
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
            'status' => WinnerPayoutExecutionAttemptStatus::class,
            'attempt_number' => 'integer',
            'external_reference_encrypted' => 'encrypted',
            'started_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WinnerPayout, $this> */
    public function payout(): BelongsTo
    {
        return $this->belongsTo(WinnerPayout::class, 'winner_payout_id');
    }

    /** @return BelongsTo<WinnerPayoutDestination, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(WinnerPayoutDestination::class, 'destination_id');
    }

    /** @return BelongsTo<User, $this> */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    /** @return HasMany<WinnerPayoutDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(WinnerPayoutDocument::class, 'execution_attempt_id');
    }

    public function transitionTo(WinnerPayoutExecutionAttemptStatus $next): void
    {
        if ($this->status === $next) {
            return;
        }

        if (! $this->status->canTransitionTo($next)) {
            throw new InvalidWinnerPayoutTransition($this->status, $next);
        }

        $this->status = $next;
        $this->allowLifecycleUpdate = true;
    }

    public function allowLifecycleUpdate(): void
    {
        $this->allowLifecycleUpdate = true;
    }
}
