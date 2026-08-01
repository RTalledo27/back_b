<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Models;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\Shared\Domain\Exceptions\ImmutableModelException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class GameFinancialClosure extends Model
{
    use HasUuids;

    protected $table = 'game_financial_closures';

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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'safe_snapshot' => 'array',
            'closed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function game(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function winner(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(GameWinner::class, 'game_winner_id');
    }

    public function payout(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(WinnerPayout::class, 'winner_payout_id');
    }

    public function receipt(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(WinnerPayoutReceipt::class, 'winner_payout_receipt_id');
    }

    public function reconciliation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(WinnerPayoutReconciliation::class, 'winner_payout_reconciliation_id');
    }

    public function closedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }
}
