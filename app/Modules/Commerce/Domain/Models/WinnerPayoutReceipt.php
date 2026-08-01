<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Models;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutReceiptStatus;
use App\Modules\Shared\Domain\Exceptions\ImmutableModelException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

final class WinnerPayoutReceipt extends Model
{
    use HasUuids;

    protected $table = 'winner_payout_receipts';

    protected $guarded = [];

    protected $hidden = ['idempotency_key_hash', 'request_fingerprint'];

    private bool $allowLifecycleUpdate = false;

    protected static function booted(): void
    {
        static::updating(function (self $receipt): void {
            if (! $receipt->allowLifecycleUpdate) {
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
            'status' => WinnerPayoutReceiptStatus::class,
            'confirmation_window_started_at' => 'datetime',
            'confirmation_expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'is_legacy' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function transitionTo(WinnerPayoutReceiptStatus $next): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw new \LogicException('Invalid winner payout receipt transition.');
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
}
