<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Modules\RepeatNumberBingo\Application\Actions\DispatchDueGameDrawsAction;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\EngineTick;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * Two-connection concurrency tests for SKIP LOCKED behaviour.
 *
 * Data is committed (DatabaseTruncation, no wrapping transaction), so a raw
 * second PDO connection can see and independently lock the same rows. This
 * proves that concurrent dispatcher instances cannot process the same game
 * and that a blocked game does not stall the rest of the batch.
 */
final class DispatchDueGameDrawsConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        DB::statement('TRUNCATE TABLE game_events, game_entries, game_numbers, draw_commands, game_winners, game_draws, game_number_counters, purchase_allocations, payment_documents, payments, number_reservations, order_items, orders, idempotency_keys, games, users RESTART IDENTITY CASCADE');
        parent::tearDown();
    }

    private function makeDueGame(): Game
    {
        return Game::create([
            'slug' => 'dc-'.fake()->unique()->lexify('?????'),
            'name' => 'DC', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 2,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::Running,
            'scheduled_start_at' => CarbonImmutable::now()->subHour(),
            'started_at' => CarbonImmutable::now()->subMinutes(10),
            'next_draw_at' => CarbonImmutable::now()->subSeconds(5),
        ]);
    }

    private function openSecondConnection(): PDO
    {
        $cfg = config('database.connections.pgsql');

        return new PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=%s', $cfg['host'], (int) $cfg['port'], $cfg['database']),
            $cfg['username'],
            $cfg['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    public function test_game_locked_by_another_dispatcher_is_skipped(): void
    {
        $lockedGame = $this->makeDueGame();
        $otherGame = $this->makeDueGame();

        // Second connection holds FOR UPDATE on $lockedGame — simulates another dispatcher.
        $pdo = $this->openSecondConnection();
        $pdo->beginTransaction();
        $pdo->prepare('SELECT id FROM games WHERE id = ? FOR UPDATE')->execute([$lockedGame->id]);

        try {
            $result = app(DispatchDueGameDrawsAction::class)->execute();
        } finally {
            $pdo->rollBack();
        }

        $selectedIds = array_map(fn (EngineTick $t) => $t->gameId, $result->ticks);
        $this->assertNotContains($lockedGame->id, $selectedIds, 'Locked game must be skipped (SKIP LOCKED)');
        $this->assertContains($otherGame->id, $selectedIds, 'Unlocked game must still be selected');
    }

    public function test_blocked_game_does_not_prevent_others_from_being_dispatched(): void
    {
        $blockedGame = $this->makeDueGame();
        $game2 = $this->makeDueGame();
        $game3 = $this->makeDueGame();

        $pdo = $this->openSecondConnection();
        $pdo->beginTransaction();
        $pdo->prepare('SELECT id FROM games WHERE id = ? FOR UPDATE')->execute([$blockedGame->id]);

        try {
            $result = app(DispatchDueGameDrawsAction::class)->execute();
        } finally {
            $pdo->rollBack();
        }

        $selectedIds = array_map(fn (EngineTick $t) => $t->gameId, $result->ticks);
        $this->assertNotContains($blockedGame->id, $selectedIds);
        $this->assertContains($game2->id, $selectedIds);
        $this->assertContains($game3->id, $selectedIds);
        $this->assertCount(2, $result->ticks, 'Exactly the 2 unblocked games must be selected');
    }
}
