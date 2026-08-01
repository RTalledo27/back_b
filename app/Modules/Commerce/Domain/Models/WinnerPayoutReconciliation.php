<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Models;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutReconciliationStatus;
use App\Modules\Shared\Domain\Exceptions\ImmutableModelException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WinnerPayoutReconciliation extends Model
{
    use HasUuids;

    protected $table = 'winner_payout_reconciliations';

    protected $guarded = [];

    protected $hidden = ['reference_digest', 'notes_encrypted', 'idempotency_key_hash', 'request_fingerprint'];

    public const UPDATED_AT = 'updated_at';

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw ImmutableModelException::forModel(self::class, 'updated');
        });

        static::deleting(function (): void {
            throw ImmutableModelException::forModel(self::class, 'deleted');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => WinnerPayoutReconciliationStatus::class,
            'notes_encrypted' => 'encrypted',
            'reconciled_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WinnerPayout, $this> */
    public function payout(): BelongsTo
    {
        return $this->belongsTo(WinnerPayout::class, 'winner_payout_id');
    }

    /** @return BelongsTo<WinnerPayoutExecutionAttempt, $this> */
    public function executionAttempt(): BelongsTo
    {
        return $this->belongsTo(WinnerPayoutExecutionAttempt::class, 'execution_attempt_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by_user_id');
    }
}
