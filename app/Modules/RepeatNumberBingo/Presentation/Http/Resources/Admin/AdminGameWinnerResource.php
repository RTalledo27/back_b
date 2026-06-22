<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin;

use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read GameWinner $resource
 */
final class AdminGameWinnerResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $winner = $this->resource;

        return [
            'winner_id' => $winner->id,
            'game_id' => $winner->game_id,
            'game_entry_id' => $winner->game_entry_id,
            'game_number_id' => $winner->game_number_id,
            'winning_number' => $winner->gameNumber?->number !== null
                ? (int) $winner->gameNumber->number
                : null,
            'game_draw_id' => $winner->game_draw_id,
            'winning_draw_sequence' => $winner->draw?->sequence !== null
                ? (int) $winner->draw->sequence
                : null,
            'winning_hits' => (int) $winner->winning_hits,
            'user_id' => (int) $winner->user_id,
            'won_at' => $winner->won_at->toIso8601String(),
        ];
    }
}
