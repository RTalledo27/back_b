<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Application\Actions\DrawGameNumberAction;
use App\Modules\RepeatNumberBingo\Application\Contracts\DrawNumberStrategy;
use App\Modules\RepeatNumberBingo\Application\DTOs\DrawGameNumberData;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\DrawCommandId;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DeterministicDrawNumberStrategy;
use Tests\TestCase;

/**
 * The canonical source of truth (game_draws) MUST be persisted before
 * the projection (game_number_counters). Both still live in the same
 * transaction — but the order is meaningful for the design.
 */
final class DrawPersistenceOrderTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_game_draws_insert_precedes_game_number_counters_upsert(): void
    {
        $game = Game::create([
            'slug' => 'po-'.fake()->unique()->lexify('?????'),
            'name' => 'PO', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => false, 'status' => GameStatus::Running,
            'scheduled_start_at' => now()->subHour(),
            'started_at' => now()->subMinute(),
        ]);
        for ($i = 1; $i <= 5; $i++) {
            GameNumber::create([
                'game_id' => $game->id, 'number' => $i, 'status' => GameNumberStatus::Available,
            ]);
        }
        $admin = User::factory()->admin()->create();

        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([3]));

        $drawInsertAt = null;
        $counterUpsertAt = null;
        DB::listen(function ($query) use (&$drawInsertAt, &$counterUpsertAt): void {
            $sql = mb_strtolower((string) $query->sql);
            $now = hrtime(true);
            if ($drawInsertAt === null && str_contains($sql, 'insert into "game_draws"')) {
                $drawInsertAt = $now;
            }
            if ($counterUpsertAt === null && str_contains($sql, 'insert into game_number_counters')) {
                $counterUpsertAt = $now;
            }
        });

        $this->app->make(DrawGameNumberAction::class)->execute(
            new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id),
        );

        $this->assertNotNull($drawInsertAt, 'No INSERT INTO game_draws was captured.');
        $this->assertNotNull($counterUpsertAt, 'No UPSERT into game_number_counters was captured.');
        $this->assertLessThan(
            $counterUpsertAt,
            $drawInsertAt,
            'game_draws (canonical source of truth) must be persisted before the counter projection.',
        );
    }
}
