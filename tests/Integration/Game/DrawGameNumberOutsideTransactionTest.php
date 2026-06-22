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
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\DeterministicDrawNumberStrategy;
use Tests\TestCase;

/**
 * Behaviour assertion (not source inspection) that
 * DrawGameNumberAction::executeWithinTransaction() refuses to run when
 * DB::transactionLevel() === 0. Uses DatabaseTruncation so the test
 * itself does NOT open a transaction.
 */
final class DrawGameNumberOutsideTransactionTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        DB::statement('TRUNCATE TABLE game_events, game_entries, game_numbers, draw_commands, game_winners, game_draws, game_number_counters, purchase_allocations, payment_documents, payments, number_reservations, order_items, orders, idempotency_keys, games, users RESTART IDENTITY CASCADE');
        parent::tearDown();
    }

    public function test_execute_within_transaction_outside_transaction_throws_logic_exception(): void
    {
        $game = Game::create([
            'slug' => 'otx-'.fake()->unique()->lexify('?????'),
            'name' => 'OTX', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::Running,
            'scheduled_start_at' => now()->subHour(),
            'started_at' => now()->subMinute(),
        ]);
        for ($i = 1; $i <= 5; $i++) {
            GameNumber::create([
                'game_id' => $game->id, 'number' => $i, 'status' => GameNumberStatus::Available,
            ]);
        }
        $admin = User::factory()->admin()->create();

        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([1]));
        $action = $this->app->make(DrawGameNumberAction::class);

        $this->assertSame(0, DB::transactionLevel(), 'Test must run outside any DB transaction.');

        $this->expectException(LogicException::class);
        $action->executeWithinTransaction(
            new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id),
        );
    }
}
