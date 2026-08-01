<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Resources\Admin;

use App\Modules\Commerce\Domain\Models\WinnerPayoutDispute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read WinnerPayoutDispute $resource */
class AdminWinnerPayoutDisputeResource extends JsonResource
{
    public static $wrap = 'data';

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $dispute = $this->resource;

        return [
            'id' => $dispute->id,
            'winner_payout_id' => $dispute->winner_payout_id,
            'winner_user_id' => $dispute->winner_user_id,
            'status' => $dispute->status->value,
            'reason_code' => $dispute->reason_code,
            'resolution_code' => $dispute->resolution_code,
            'opened_by_user_id' => $dispute->opened_by_user_id,
            'reviewed_by_user_id' => $dispute->reviewed_by_user_id,
            'resolved_by_user_id' => $dispute->resolved_by_user_id,
            'opened_at' => $dispute->opened_at?->utc()->toIso8601String(),
            'review_started_at' => $dispute->review_started_at?->utc()->toIso8601String(),
            'resolved_at' => $dispute->resolved_at?->utc()->toIso8601String(),
        ];
    }
}
