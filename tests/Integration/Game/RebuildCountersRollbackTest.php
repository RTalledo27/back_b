<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Application\Actions\RebuildGameNumberCountersAction;
use App\Modules\RepeatNumberBingo\Application\DTOs\RebuildCountersData;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumberCounter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

/**
 * Verify the rebuild transaction rolls back atomically when PG-level
 * failures hit the projection or the audit insert.
 */
final class RebuildCountersRollbackTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array{Game, User}
     */
    private function makeRunningGame(): array
    {
        $game = Game::create([
            'slug' => 'rr-'.fake()->unique()->lexify('?????'),
            'name' => 'RR', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 5,
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

        return [$game, User::factory()->admin()->create()];
    }

    private function seedDraw(Game $game, int $number, int $sequence): void
    {
        $gn = GameNumber::query()->where('game_id', $game->id)->where('number', $number)->firstOrFail();
        DB::table('game_draws')->insert([
            'id' => (string) Str::uuid7(),
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'sequence' => $sequence, 'drawn_number' => $number,
            'drawn_at' => now()->subSeconds(50 - $sequence),
            'strategy' => 'crypto_secure',
            'created_at' => now(),
        ]);
    }

    private function dropTriggerSilently(string $sql): void
    {
        try {
            DB::statement($sql);
        } catch (Throwable) {
            // best-effort
        }
    }

    public function test_failure_during_bulk_insert_rolls_back_the_delete(): void
    {
        [$game, $admin] = $this->makeRunningGame();
        $this->seedDraw($game, 1, 1);
        $this->seedDraw($game, 2, 2);
        $gn1 = GameNumber::query()->where('game_id', $game->id)->where('number', 1)->firstOrFail();
        // Pre-seed a (wrong) counter so DELETE has something to remove.
        GameNumberCounter::create([
            'game_id' => $game->id, 'game_number_id' => $gn1->id,
            'hits_count' => 99, 'last_draw_sequence' => 99,
        ]);
        $countersBefore = GameNumberCounter::query()->where('game_id', $game->id)->get()->toArray();

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION block_counter_insert()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'simulated counter insert failure';
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::statement('CREATE TRIGGER block_counter_insert_t BEFORE INSERT ON game_number_counters FOR EACH ROW EXECUTE FUNCTION block_counter_insert()');

        try {
            try {
                $this->app->make(RebuildGameNumberCountersAction::class)->execute(
                    new RebuildCountersData($game->id, $admin->id),
                );
                $this->fail('Expected exception');
            } catch (Throwable $e) {
                $this->assertStringContainsString('simulated', $e->getMessage());
            }

            // DELETE rolled back together with the failing INSERT.
            $countersAfter = GameNumberCounter::query()->where('game_id', $game->id)->get()->toArray();
            $this->assertCount(count($countersBefore), $countersAfter, 'DELETE must roll back with the failed INSERT.');
            $this->assertSame(
                99,
                GameNumberCounter::query()->where('game_id', $game->id)
                    ->where('game_number_id', $gn1->id)->value('hits_count'),
            );
            $this->assertSame(0, GameEvent::query()->where('game_id', $game->id)
                ->where('type', GameEventType::CountersRebuilt)->count());
        } finally {
            $this->dropTriggerSilently('DROP TRIGGER IF EXISTS block_counter_insert_t ON game_number_counters');
            $this->dropTriggerSilently('DROP FUNCTION IF EXISTS block_counter_insert()');
        }
    }

    public function test_failure_inserting_audit_rolls_back_the_entire_rebuild(): void
    {
        [$game, $admin] = $this->makeRunningGame();
        $this->seedDraw($game, 1, 1);
        $gn1 = GameNumber::query()->where('game_id', $game->id)->where('number', 1)->firstOrFail();
        GameNumberCounter::create([
            'game_id' => $game->id, 'game_number_id' => $gn1->id,
            'hits_count' => 9, 'last_draw_sequence' => 9,
        ]);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION block_game_events_counters_rebuilt()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.type = 'counters_rebuilt' THEN
                    RAISE EXCEPTION 'simulated audit insert failure';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::statement('CREATE TRIGGER block_game_events_counters_rebuilt_t BEFORE INSERT ON game_events FOR EACH ROW EXECUTE FUNCTION block_game_events_counters_rebuilt()');

        try {
            try {
                $this->app->make(RebuildGameNumberCountersAction::class)->execute(
                    new RebuildCountersData($game->id, $admin->id),
                );
                $this->fail('Expected exception');
            } catch (Throwable $e) {
                $this->assertStringContainsString('simulated', $e->getMessage());
            }

            // Old (wrong) projection still intact.
            $counter = GameNumberCounter::query()->where('game_id', $game->id)->firstOrFail();
            $this->assertSame(9, $counter->hits_count);
            $this->assertSame(0, GameEvent::query()->where('game_id', $game->id)
                ->where('type', GameEventType::CountersRebuilt)->count());
        } finally {
            $this->dropTriggerSilently('DROP TRIGGER IF EXISTS block_game_events_counters_rebuilt_t ON game_events');
            $this->dropTriggerSilently('DROP FUNCTION IF EXISTS block_game_events_counters_rebuilt()');
        }
    }
}
