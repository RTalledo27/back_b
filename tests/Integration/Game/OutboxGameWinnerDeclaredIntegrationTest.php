<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Application\Actions\DrawGameNumberAction;
use App\Modules\RepeatNumberBingo\Application\Contracts\DrawNumberStrategy;
use App\Modules\RepeatNumberBingo\Application\DTOs\DrawGameNumberData;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\DrawCommandId;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DeterministicDrawNumberStrategy;
use Tests\TestCase;

/**
 * Integration tests for the outbox game_winner_declared event (Phase 8.3).
 *
 * Uses hits_required=2 (DB constraint: >= 2) and draws number 1 twice:
 *  - Draw 1: hits=1, below threshold — no winner, no outbox.
 *  - Draw 2: hits=2, threshold met — winner resolved, outbox inserted.
 */
final class OutboxGameWinnerDeclaredIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Creates a Running game (hits_required=2) with number 1 Sold.
     * Also performs the first draw so the next draw triggers the winner.
     *
     * @return array{Game, GameNumber, GameEntry, User}
     */
    private function setupGameAlmostWon(): array
    {
        $buyer = User::factory()->create();
        $actor = User::factory()->admin()->create();

        $game = Game::create([
            'slug' => 'ogwd-'.fake()->unique()->lexify('?????'),
            'name' => 'OutboxWinnerDeclaredTest',
            'number_min' => 1, 'number_max' => 5, 'hits_required' => 2,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => false,
            'status' => GameStatus::Running,
            'scheduled_start_at' => now()->subHour(),
            'started_at' => now()->subMinute(),
        ]);

        for ($i = 1; $i <= 5; $i++) {
            GameNumber::create([
                'game_id' => $game->id, 'number' => $i,
                'status' => GameNumberStatus::Available,
            ]);
        }

        $gn = GameNumber::query()
            ->where('game_id', $game->id)
            ->where('number', 1)
            ->firstOrFail();

        $gn->status = GameNumberStatus::Sold;
        $gn->save();

        $entry = GameEntry::create([
            'game_id' => $game->id,
            'game_number_id' => $gn->id,
            'user_id' => $buyer->id,
            'status' => EntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        // First draw: number 1, hits=1 — below threshold, no winner.
        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([1]));
        $this->app->make(DrawGameNumberAction::class)->execute(
            new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $actor->id)
        );

        return [$game, $gn, $entry, $actor];
    }

    private function action(): DrawGameNumberAction
    {
        return $this->app->make(DrawGameNumberAction::class);
    }

    public function test_winning_draw_inserts_outbox_event(): void
    {
        [$game, , , $actor] = $this->setupGameAlmostWon();

        // Second draw: number 1, hits=2 — threshold met, winner resolved.
        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([1]));
        $result = $this->action()->execute(
            new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $actor->id)
        );

        $this->assertTrue($result->winnerCreated);
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'game_winner_declared',
            'aggregate_type' => 'game',
            'aggregate_id' => (string) $game->id,
            'deduplication_key' => 'game_winner_declared:'.(string) $game->id,
        ]);
    }

    public function test_non_winning_draw_does_not_insert_outbox_event(): void
    {
        [$game, , , $actor] = $this->setupGameAlmostWon();

        // Draw a different number (2) — not sold, hits are for an unowned number.
        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([2]));
        $result = $this->action()->execute(
            new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $actor->id)
        );

        $this->assertFalse($result->winnerCreated);
        // Only the setup draw row exists (for outbox: 0 because first draw was non-winning).
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_outbox_payload_contains_required_fields(): void
    {
        [$game, $gn, $entry, $actor] = $this->setupGameAlmostWon();

        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([1]));
        $this->action()->execute(
            new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $actor->id)
        );

        $row = DB::table('outbox_events')->where('event_type', 'game_winner_declared')->first();
        $this->assertNotNull($row);
        $payload = json_decode($row->payload, true);

        $this->assertSame(1, $payload['schema_version']);
        $this->assertArrayHasKey('game_winner_id', $payload);
        $this->assertSame((string) $game->id, $payload['game_id']);
        $this->assertArrayHasKey('game_draw_id', $payload);
        $this->assertSame((string) $gn->id, $payload['game_number_id']);
        $this->assertSame((int) $entry->user_id, $payload['winner_user_id']);
        $this->assertArrayHasKey('occurred_at', $payload);
    }

    public function test_outbox_payload_contains_no_pii(): void
    {
        [$game, , , $actor] = $this->setupGameAlmostWon();

        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([1]));
        $this->action()->execute(
            new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $actor->id)
        );

        $row = DB::table('outbox_events')->where('event_type', 'game_winner_declared')->first();
        $this->assertNotNull($row);
        $payload = json_decode($row->payload, true);

        foreach (['email', 'name', 'phone', 'reason', 'path', 'disk', 'sha256'] as $field) {
            $this->assertArrayNotHasKey($field, $payload, "Outbox payload must not contain '{$field}'.");
        }
    }

    public function test_replay_draw_does_not_duplicate_outbox(): void
    {
        [$game, , , $actor] = $this->setupGameAlmostWon();
        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([1]));

        $commandId = new DrawCommandId((string) Str::uuid7());
        $data = new DrawGameNumberData($game->id, $commandId, $actor->id);

        // First execution: creates winner and outbox row.
        $this->action()->execute($data);
        $this->assertDatabaseCount('outbox_events', 1);

        // Replay: same command_id → replay branch, no new outbox row.
        $r2 = $this->action()->execute($data);
        $this->assertTrue($r2->wasReplay);
        $this->assertDatabaseCount('outbox_events', 1);
    }

    public function test_rollback_removes_outbox_row(): void
    {
        [$game, , , $actor] = $this->setupGameAlmostWon();
        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([1]));

        try {
            DB::transaction(function () use ($game, $actor): void {
                $this->action()->executeWithinTransaction(
                    new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $actor->id)
                );
                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertDatabaseCount('outbox_events', 0);
    }
}
