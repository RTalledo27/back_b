<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Application\Actions\CreateWinnerClaimAction;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\WinnerClaimStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaim;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class WinnerClaimConcurrencyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unique_winner_constraint_prevents_two_claims(): void
    {
        $winner = $this->winner();
        $action = $this->app->make(CreateWinnerClaimAction::class);

        DB::transaction(fn (): WinnerClaim => $action->executeWithinTransaction($winner->id));

        $this->expectException(QueryException::class);
        WinnerClaim::create([
            'game_winner_id' => $winner->id,
            'winner_user_id' => $winner->user_id,
            'claim_reference' => 'DUPLICATE-'.fake()->unique()->lexify('????????'),
            'status' => WinnerClaimStatus::PendingClaim,
        ]);
    }

    public function test_replaying_creation_does_not_duplicate_claim(): void
    {
        $winner = $this->winner();
        $action = $this->app->make(CreateWinnerClaimAction::class);

        $first = DB::transaction(fn (): WinnerClaim => $action->executeWithinTransaction($winner->id));
        $second = DB::transaction(fn (): WinnerClaim => $action->executeWithinTransaction($winner->id));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, WinnerClaim::query()->where('game_winner_id', $winner->id)->count());
    }

    private function winner(): GameWinner
    {
        $user = User::factory()->create();
        $game = Game::create([
            'slug' => 'winner-claim-concurrency-'.fake()->unique()->lexify('????'),
            'name' => 'Winner claim concurrency',
            'number_min' => 1,
            'number_max' => 10,
            'hits_required' => 2,
            'ticket_price_cents' => 500,
            'prize_cents' => 2000,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => false,
            'status' => GameStatus::Completed,
            'started_at' => now()->subMinutes(2),
            'completed_at' => now()->subMinute(),
        ]);
        $number = GameNumber::create(['game_id' => $game->id, 'number' => 4, 'status' => GameNumberStatus::Sold]);
        $entry = GameEntry::create([
            'game_id' => $game->id,
            'game_number_id' => $number->id,
            'user_id' => $user->id,
            'status' => EntryStatus::Winner,
            'confirmed_at' => now()->subMinutes(2),
        ]);
        $draw = GameDraw::create([
            'game_id' => $game->id,
            'game_number_id' => $number->id,
            'sequence' => 1,
            'drawn_number' => 4,
            'drawn_at' => now()->subMinute(),
            'strategy' => 'test',
        ]);

        return GameWinner::create([
            'game_id' => $game->id,
            'game_entry_id' => $entry->id,
            'game_draw_id' => $draw->id,
            'game_number_id' => $number->id,
            'user_id' => $user->id,
            'winning_hits' => 2,
            'won_at' => now()->subMinute(),
        ]);
    }
}
