<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Resources\Admin;

use App\Modules\Commerce\Domain\Models\WinnerPayoutReconciliation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read WinnerPayoutReconciliation $resource */
final class AdminWinnerPayoutReconciliationResource extends JsonResource
{
    public static $wrap = 'data';

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'winner_payout_id' => $this->resource->winner_payout_id,
            'execution_attempt_id' => $this->resource->execution_attempt_id,
            'status' => $this->resource->status->value,
            'result_code' => $this->resource->result_code,
            'reconciled_by_user_id' => $this->resource->reconciled_by_user_id,
            'reconciled_at' => $this->resource->reconciled_at?->utc()->toIso8601String(),
            'created_at' => $this->resource->created_at?->utc()->toIso8601String(),
        ];
    }
}
