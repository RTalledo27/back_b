<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumberCounter;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PublicGameReadApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);

        parent::tearDown();
    }

    public function test_running_game_exposes_public_state_latest_draw_and_next_execution_in_utc(): void
    {
        $game = $this->createGame(GameStatus::Running, [
            'slug' => 'running-public',
            'started_at' => Carbon::parse('2026-06-23 09:00:00-05:00')->utc(),
            'next_draw_at' => Carbon::parse('2026-06-23 10:00:00-05:00')->utc(),
            'settings' => ['engine_secret' => 'hidden'],
        ]);
        $number = $this->createNumber($game, 7);
        $this->createDraw($game, $number, 1, '2026-06-23 09:59:30-05:00');

        $data = $this->getJson('/api/v1/public/games/running-public')
            ->assertOk()
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.latest_draw.sequence', 1)
            ->assertJsonPath('data.latest_draw.number', 7)
            ->assertJsonPath('data.schedule.next_draw_at', '2026-06-23T15:00:00+00:00')
            ->assertJsonPath('data.lifecycle.started_at', '2026-06-23T14:00:00+00:00')
            ->json('data');

        $this->assertSame(
            ['sequence', 'number', 'drawn_at'],
            array_keys($data['latest_draw']),
        );
        $this->assertNull($data['winner']);

        $json = json_encode($data, JSON_THROW_ON_ERROR);
        foreach ([
            'settings',
            'created_by',
            'auto_draw_enabled',
            'last_consumed_tick_at',
            'strategy',
            'command_id',
            'game_draw_id',
            'game_number_id',
            'user_id',
            'engine_secret',
            'error',
        ] as $internalField) {
            $this->assertStringNotContainsString($internalField, $json);
        }
    }

    public function test_paused_game_has_no_next_execution(): void
    {
        $game = $this->createGame(GameStatus::Paused, [
            'slug' => 'paused-public',
            'started_at' => now()->subHour(),
            'paused_at' => Carbon::parse('2026-06-23 10:05:00-05:00')->utc(),
            'next_draw_at' => now()->addMinute(),
        ]);

        $this->getJson("/api/v1/public/games/{$game->slug}")
            ->assertOk()
            ->assertJsonPath('data.status', 'paused')
            ->assertJsonPath('data.schedule.next_draw_at', null)
            ->assertJsonPath('data.lifecycle.paused_at', '2026-06-23T15:05:00+00:00');
    }

    public function test_completed_game_exposes_anonymized_winner_and_final_state(): void
    {
        $completedAt = Carbon::parse('2026-06-23 11:00:00-05:00')->utc();
        $game = $this->createGame(GameStatus::Completed, [
            'slug' => 'completed-public',
            'started_at' => $completedAt->copy()->subHour(),
            'completed_at' => $completedAt,
            'next_draw_at' => $completedAt->copy()->addMinute(),
        ]);
        $number = $this->createNumber($game, 9);
        $draw = $this->createDraw($game, $number, 4, '2026-06-23 10:59:59-05:00');
        $user = User::factory()->create();
        $entry = GameEntry::create([
            'game_id' => $game->id,
            'game_number_id' => $number->id,
            'user_id' => $user->id,
            'status' => EntryStatus::Winner,
            'confirmed_at' => $completedAt->copy()->subDay(),
        ]);

        GameWinner::create([
            'game_id' => $game->id,
            'game_entry_id' => $entry->id,
            'game_draw_id' => $draw->id,
            'game_number_id' => $number->id,
            'user_id' => $user->id,
            'winning_hits' => 5,
            'won_at' => $completedAt,
        ]);

        $winner = $this->getJson('/api/v1/public/games/completed-public')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.schedule.next_draw_at', null)
            ->assertJsonPath('data.lifecycle.completed_at', '2026-06-23T16:00:00+00:00')
            ->assertJsonPath('data.winner.number', 9)
            ->assertJsonPath('data.winner.draw_sequence', 4)
            ->assertJsonPath('data.winner.hits', 5)
            ->assertJsonPath('data.winner.won_at', '2026-06-23T16:00:00+00:00')
            ->json('data.winner');

        $this->assertSame(['number', 'draw_sequence', 'hits', 'won_at'], array_keys($winner));
        $this->assertStringNotContainsString($user->email, json_encode($winner, JSON_THROW_ON_ERROR));
    }

    public function test_private_cancelled_and_missing_games_return_the_same_404(): void
    {
        $this->createGame(GameStatus::Draft, ['slug' => 'private-game']);
        $this->createGame(GameStatus::Cancelled, ['slug' => 'cancelled-game']);

        foreach (['private-game', 'cancelled-game', 'missing-game'] as $slug) {
            foreach (['', '/draws', '/number-counters'] as $suffix) {
                $this->getJson("/api/v1/public/games/{$slug}{$suffix}")
                    ->assertNotFound()
                    ->assertJsonPath('message', 'Game not found.');
            }
        }
    }

    public function test_draw_history_is_ordered_and_contains_only_public_fields(): void
    {
        $game = $this->createGame(GameStatus::Running, ['slug' => 'draw-history']);
        $numberTwo = $this->createNumber($game, 2);
        $numberEight = $this->createNumber($game, 8);

        $this->createDraw($game, $numberEight, 2, '2026-06-23 10:00:02-05:00');
        $this->createDraw($game, $numberTwo, 1, '2026-06-23 10:00:01-05:00');

        $response = $this->getJson('/api/v1/public/games/draw-history/draws')
            ->assertOk()
            ->assertJsonPath('data.0.sequence', 1)
            ->assertJsonPath('data.0.number', 2)
            ->assertJsonPath('data.0.drawn_at', '2026-06-23T15:00:01+00:00')
            ->assertJsonPath('data.1.sequence', 2)
            ->assertJsonPath('data.1.number', 8);

        foreach ($response->json('data') as $draw) {
            $this->assertSame(['sequence', 'number', 'drawn_at'], array_keys($draw));
        }
    }

    public function test_number_counters_are_ordered_and_include_zero_hit_numbers(): void
    {
        $game = $this->createGame(GameStatus::Running, [
            'slug' => 'public-counters',
            'number_min' => 1,
            'number_max' => 3,
        ]);
        $numberThree = $this->createNumber($game, 3);
        $numberOne = $this->createNumber($game, 1);
        $this->createNumber($game, 2);

        GameNumberCounter::create([
            'game_id' => $game->id,
            'game_number_id' => $numberThree->id,
            'hits_count' => 2,
            'last_draw_sequence' => 4,
        ]);
        GameNumberCounter::create([
            'game_id' => $game->id,
            'game_number_id' => $numberOne->id,
            'hits_count' => 1,
            'last_draw_sequence' => 1,
        ]);

        $this->getJson('/api/v1/public/games/public-counters/number-counters')
            ->assertOk()
            ->assertJsonPath('data.0', [
                'number' => 1,
                'hits_count' => 1,
                'last_draw_sequence' => 1,
            ])
            ->assertJsonPath('data.1', [
                'number' => 2,
                'hits_count' => 0,
                'last_draw_sequence' => null,
            ])
            ->assertJsonPath('data.2', [
                'number' => 3,
                'hits_count' => 2,
                'last_draw_sequence' => 4,
            ]);
    }

    public function test_public_read_endpoints_do_not_require_redis_or_lazy_load_relations(): void
    {
        $game = $this->createGame(GameStatus::Running, ['slug' => 'no-redis']);
        $number = $this->createNumber($game, 4);
        $this->createDraw($game, $number, 1, now()->subSecond()->toIso8601String());

        Config::set('cache.default', 'unavailable-store');
        Model::preventLazyLoading(true);

        $selects = 0;
        DB::listen(function ($query) use (&$selects): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $selects++;
            }
        });

        $this->getJson('/api/v1/public/games/no-redis')->assertOk();
        $this->getJson('/api/v1/public/games/no-redis/draws')->assertOk();
        $this->getJson('/api/v1/public/games/no-redis/number-counters')->assertOk();

        $this->assertLessThanOrEqual(11, $selects);
    }

    public function test_public_game_routes_are_read_only(): void
    {
        $this->createGame(GameStatus::Running, ['slug' => 'read-only']);

        foreach ([
            '/api/v1/public/games/read-only',
            '/api/v1/public/games/read-only/draws',
            '/api/v1/public/games/read-only/number-counters',
        ] as $uri) {
            $this->postJson($uri)->assertMethodNotAllowed();
            $this->putJson($uri)->assertMethodNotAllowed();
            $this->patchJson($uri)->assertMethodNotAllowed();
            $this->deleteJson($uri)->assertMethodNotAllowed();
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createGame(GameStatus $status, array $overrides = []): Game
    {
        return Game::create(array_merge([
            'slug' => 'public-game',
            'name' => 'Public game',
            'description' => 'Visible game',
            'number_min' => 1,
            'number_max' => 10,
            'hits_required' => 5,
            'ticket_price_cents' => 500,
            'prize_cents' => 2000,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => $status,
        ], $overrides));
    }

    private function createNumber(Game $game, int $number): GameNumber
    {
        return GameNumber::create([
            'game_id' => $game->id,
            'number' => $number,
            'status' => GameNumberStatus::Sold,
        ]);
    }

    private function createDraw(
        Game $game,
        GameNumber $number,
        int $sequence,
        string $drawnAt,
    ): GameDraw {
        return GameDraw::create([
            'game_id' => $game->id,
            'game_number_id' => $number->id,
            'sequence' => $sequence,
            'drawn_number' => $number->number,
            'drawn_at' => Carbon::parse($drawnAt)->utc(),
            'strategy' => 'test_strategy',
        ]);
    }
}
