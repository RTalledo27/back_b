<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Resources\Admin;

use App\Modules\Commerce\Domain\Models\GameFinancialClosure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read GameFinancialClosure $resource */
final class AdminGameFinancialClosureResource extends JsonResource
{
    public static $wrap = 'data';

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'game_id' => $this->resource->game_id,
            'game_winner_id' => $this->resource->game_winner_id,
            'winner_payout_id' => $this->resource->winner_payout_id,
            'winner_payout_receipt_id' => $this->resource->winner_payout_receipt_id,
            'winner_payout_reconciliation_id' => $this->resource->winner_payout_reconciliation_id,
            'closure_basis' => $this->resource->closure_basis,
            'closed_by_user_id' => $this->resource->closed_by_user_id,
            'closed_at' => $this->resource->closed_at?->utc()->toIso8601String(),
            'safe_snapshot' => $this->resource->safe_snapshot,
        ];
    }
}
