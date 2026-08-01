<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin;

use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaim;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read WinnerClaim $resource */
final class AdminWinnerClaimResource extends JsonResource
{
    public static $wrap = 'data';

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $claim = $this->resource;
        $winner = $claim->gameWinner;

        return [
            'claim_id' => $claim->id,
            'claim_reference' => $claim->claim_reference,
            'game_slug' => $winner?->game?->slug,
            'game_name' => $winner?->game?->name,
            'winner_user_id' => $claim->winner_user_id,
            'status' => $claim->status->value,
            'is_legacy' => $claim->is_legacy,
            'claim_window_started_at' => $claim->claim_window_started_at?->utc()->toIso8601String(),
            'expires_at' => $claim->expires_at?->utc()->toIso8601String(),
            'claimed_at' => $claim->claimed_at?->utc()->toIso8601String(),
            'identity_submitted_at' => $claim->identity_submitted_at?->utc()->toIso8601String(),
            'verified_at' => $claim->verified_at?->utc()->toIso8601String(),
            'rejected_at' => $claim->rejected_at?->utc()->toIso8601String(),
            'expired_at' => $claim->expired_at?->utc()->toIso8601String(),
            'rejection_reason_code' => $claim->rejection_reason_code,
            'created_at' => $claim->created_at?->utc()->toIso8601String(),
            'updated_at' => $claim->updated_at?->utc()->toIso8601String(),
        ];
    }
}
