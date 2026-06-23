<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Queries;

use App\Modules\RepeatNumberBingo\Application\DTOs\PublicGameUpdateReason;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Carbon\CarbonImmutable;

final class GetPublicGameBroadcastSnapshotQuery
{
    /**
     * @return array{
     *     schema_version: int,
     *     reason: string,
     *     game_slug: string,
     *     status: string,
     *     occurred_at: string,
     *     latest_draw: null|array{sequence: int, number: int, drawn_at: string},
     *     next_draw_at: ?string,
     *     winner: null|array{number: ?int, draw_sequence: ?int, hits: int, won_at: string}
     * }
     */
    public function forGame(
        string $gameId,
        PublicGameUpdateReason $reason,
        CarbonImmutable $occurredAt,
    ): array {
        $game = Game::query()
            ->with([
                'latestDraw',
                'winner.gameNumber:id,number',
                'winner.draw:id,sequence',
            ])
            ->findOrFail($gameId);

        return [
            'schema_version' => 1,
            'reason' => $reason->value,
            'game_slug' => $game->slug,
            'status' => $game->status->value,
            'occurred_at' => $occurredAt->utc()->toIso8601String(),
            'latest_draw' => $game->latestDraw === null ? null : [
                'sequence' => $game->latestDraw->sequence,
                'number' => $game->latestDraw->drawn_number,
                'drawn_at' => $game->latestDraw->drawn_at->utc()->toIso8601String(),
            ],
            'next_draw_at' => $game->auto_draw_enabled
                && $game->status === GameStatus::Running
                ? $game->next_draw_at?->utc()->toIso8601String()
                : null,
            'winner' => $game->winner === null ? null : [
                'number' => $game->winner->gameNumber?->number,
                'draw_sequence' => $game->winner->draw?->sequence,
                'hits' => $game->winner->winning_hits,
                'won_at' => $game->winner->won_at->utc()->toIso8601String(),
            ],
        ];
    }
}
