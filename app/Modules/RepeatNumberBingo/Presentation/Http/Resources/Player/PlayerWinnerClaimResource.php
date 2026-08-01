<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Player;

use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaim;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read WinnerClaim $resource */
final class PlayerWinnerClaimResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $claim = $this->resource;
        $winner = $claim->gameWinner;
        $game = $winner?->game;
        $settlement = $claim->getAttribute('settlement') ?? [];

        return [
            'claim_reference' => $claim->claim_reference,
            'game_slug' => $game?->slug,
            'game_name' => $game?->name,
            'winning_number' => $winner?->gameNumber?->number,
            'won_at' => $winner?->won_at?->utc()->toIso8601String(),
            'claim_status' => $claim->status->value,
            'claim_expires_at' => $claim->expires_at?->utc()->toIso8601String(),
            'claim_submitted_at' => $claim->identity_submitted_at?->utc()->toIso8601String(),
            'identity_verified_at' => $claim->verified_at?->utc()->toIso8601String(),
            'public_prize_amount' => $game?->prize_cents,
            'currency' => $game?->currency,
            'payout_status' => $settlement['payout_status'] ?? null,
            'paid_at' => $settlement['paid_at']?->utc()->toIso8601String(),
            'receipt_status' => $settlement['receipt_status'] ?? null,
            'confirmation_expires_at' => $settlement['confirmation_expires_at']?->utc()->toIso8601String(),
            'confirmed_at' => $settlement['confirmed_at']?->utc()->toIso8601String(),
            'has_open_dispute' => $settlement['has_open_dispute'] ?? false,
            'dispute_status' => $settlement['dispute_status'] ?? null,
            'reconciliation_public_status' => $settlement['reconciliation_public_status'] ?? null,
            'financially_closed' => $settlement['financially_closed'] ?? false,
        ];
    }
}
