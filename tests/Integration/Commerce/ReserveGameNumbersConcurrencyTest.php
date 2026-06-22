<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Models\User;
use App\Modules\Commerce\Application\Actions\ReserveGameNumbersAction;
use App\Modules\Commerce\Application\DTOs\ReserveGameNumbersData;
use App\Modules\Commerce\Domain\Exceptions\GameNotInSalesOpen;
use App\Modules\Commerce\Domain\Exceptions\NumberNotAvailableForReservation;
use App\Modules\Commerce\Domain\Models\NumberReservation;
use App\Modules\RepeatNumberBingo\Application\Actions\CloseGameSalesAction;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use PDO;
use Tests\Integration\Support\RawPdoConnection;
use Tests\TestCase;

/**
 * Real PostgreSQL concurrency via two independent PDO connections.
 * Uses DatabaseTruncation (not LazilyRefreshDatabase) so the setup data
 * committed by Laravel is visible to the second connection's transaction.
 */
final class ReserveGameNumbersConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        // Same reasoning as IdempotencyClaimConcurrencyTest: explicit CASCADE
        // truncate guarantees no row survives into LazilyRefreshDatabase
        // tests that follow alphabetically.
        \DB::statement(
            'TRUNCATE TABLE games, game_numbers, game_events, orders, '
            .'order_items, number_reservations, payments, payment_documents, '
            .'game_entries, purchase_allocations, idempotency_keys, users '
            .'RESTART IDENTITY CASCADE'
        );

        parent::tearDown();
    }

    /**
     * @return array{Game, list<GameNumber>}
     */
    private function setupOpenGame(): array
    {
        $game = Game::create([
            'slug' => 'cc-'.fake()->unique()->lexify('???????'),
            'name' => 'C',
            'number_min' => 1,
            'number_max' => 3,
            'hits_required' => 5,
            'ticket_price_cents' => 100,
            'prize_cents' => 500,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => GameStatus::SalesOpen,
        ]);

        $numbers = [];
        for ($i = 1; $i <= 3; $i++) {
            $numbers[] = GameNumber::create([
                'game_id' => $game->id,
                'number' => $i,
                'status' => GameNumberStatus::Available,
            ]);
        }

        return [$game, $numbers];
    }

    public function test_select_for_update_serializes_concurrent_reservations_of_same_number(): void
    {
        [$game, $numbers] = $this->setupOpenGame();
        $userA = User::factory()->create();

        $pdo = RawPdoConnection::open();

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT id FROM game_numbers WHERE id = ? FOR UPDATE');
            $stmt->execute([$numbers[0]->id]);

            // Laravel's reservation attempt must block on the same row lock.
            // Postgres lock_timeout aborts the wait so the test is deterministic.
            \DB::statement("SET lock_timeout = '500ms'");

            $blocked = false;
            try {
                $this->app->make(ReserveGameNumbersAction::class)->execute(
                    new ReserveGameNumbersData(
                        gameId: $game->id,
                        userId: $userA->id,
                        gameNumberIds: [$numbers[0]->id],
                    )
                );
            } catch (QueryException $e) {
                $blocked = str_contains($e->getMessage(), '55P03')
                    || str_contains($e->getMessage(), 'lock_timeout')
                    || str_contains($e->getMessage(), 'canceling statement');
            }

            $this->assertTrue(
                $blocked,
                'Second reservation must block on the row lock acquired by the first connection.'
            );

            // Other connection rolled back → number remains available.
            $this->assertSame(GameNumberStatus::Available, $numbers[0]->refresh()->status);
            $this->assertSame(0, NumberReservation::query()->count());
        } finally {
            \DB::statement('SET lock_timeout = DEFAULT');
            RawPdoConnection::teardown($pdo);
        }
    }

    public function test_second_attempt_after_first_commit_rejects_via_availability_check(): void
    {
        [$game, $numbers] = $this->setupOpenGame();
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->app->make(ReserveGameNumbersAction::class)->execute(new ReserveGameNumbersData(
            gameId: $game->id,
            userId: $userA->id,
            gameNumberIds: [$numbers[0]->id],
        ));

        $this->expectException(NumberNotAvailableForReservation::class);

        $this->app->make(ReserveGameNumbersAction::class)->execute(new ReserveGameNumbersData(
            gameId: $game->id,
            userId: $userB->id,
            gameNumberIds: [$numbers[0]->id],
        ));
    }

    public function test_close_sales_concurrent_with_reserve_makes_reservation_fail_cleanly(): void
    {
        [$game, $numbers] = $this->setupOpenGame();
        $admin = User::factory()->admin()->create();
        $player = User::factory()->create();

        $this->app->make(CloseGameSalesAction::class)->execute($game->id, $admin->id);

        $this->expectException(GameNotInSalesOpen::class);

        $this->app->make(ReserveGameNumbersAction::class)->execute(new ReserveGameNumbersData(
            gameId: $game->id,
            userId: $player->id,
            gameNumberIds: [$numbers[0]->id],
        ));
    }
}
