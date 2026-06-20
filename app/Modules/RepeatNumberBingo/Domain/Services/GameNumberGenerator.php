<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Services;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\BingoNumberRange;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generates the full set of game_numbers rows for a freshly created game.
 *
 * Intentionally NOT exposed as a public Action: regeneration of numbers
 * after game creation is disallowed by the domain. This service is only
 * called from CreateGameAction inside the same transaction.
 */
final class GameNumberGenerator
{
    public function generateFor(Game $game, BingoNumberRange $range): void
    {
        $now = now();
        $rows = [];

        foreach ($range->toList() as $number) {
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'game_id' => $game->id,
                'number' => $number,
                'status' => GameNumberStatus::Available->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table((new GameNumber)->getTable())->insert($rows);
    }
}
