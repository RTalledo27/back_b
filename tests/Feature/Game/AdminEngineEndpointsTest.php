<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Application\Contracts\DrawNumberStrategy;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\DeterministicDrawNumberStrategy;
use Tests\TestCase;

final class AdminEngineEndpointsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function makeSalesClosedReadyGame(): Game
    {
        $game = Game::create([
            'slug' => 'aee-'.fake()->unique()->lexify('?????'),
            'name' => 'AEE', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => false, 'status' => GameStatus::SalesClosed,
            'scheduled_start_at' => now()->subMinute(),
        ]);
        for ($i = 1; $i <= 5; $i++) {
            GameNumber::create(['game_id' => $game->id, 'number' => $i, 'status' => GameNumberStatus::Available]);
        }
        $gn = GameNumber::query()->where('game_id', $game->id)->where('number', 1)->firstOrFail();
        $gn->status = GameNumberStatus::Sold;
        $gn->save();
        GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'user_id' => User::factory()->create()->id,
            'status' => EntryStatus::Confirmed, 'confirmed_at' => now(),
        ]);

        return $game;
    }

    private function makeRunningGame(): Game
    {
        $game = $this->makeSalesClosedReadyGame();
        $game->status = GameStatus::Running;
        $game->started_at = now()->subSecond();
        $game->saveQuietly();

        return $game;
    }

    public function test_start_returns_200_and_resource_without_commerce_fields(): void
    {
        $game = $this->makeSalesClosedReadyGame();
        Sanctum::actingAs($this->admin());

        $response = $this->postJson("/api/v1/admin/games/{$game->id}/start");

        $response->assertOk()
            ->assertJsonPath('data.outcome', 'started')
            ->assertJsonPath('data.status', 'running')
            ->assertJsonStructure([
                'data' => ['game_id', 'status', 'outcome', 'scheduled_start_at', 'started_at', 'confirmed_entries_count'],
            ]);

        $body = $response->json();
        $json = json_encode($body);
        foreach (['email', 'order_id', 'payment_id', 'amount', 'price'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    public function test_start_replay_returns_200_already_started(): void
    {
        $game = $this->makeSalesClosedReadyGame();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/admin/games/{$game->id}/start")->assertOk();
        $this->postJson("/api/v1/admin/games/{$game->id}/start")
            ->assertOk()
            ->assertJsonPath('data.outcome', 'already_started');
    }

    public function test_start_with_readiness_failure_returns_422(): void
    {
        // No confirmed entries â†’ readiness rejects.
        $game = Game::create([
            'slug' => 'no-'.fake()->unique()->lexify('?????'),
            'name' => 'NO', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => false, 'status' => GameStatus::SalesClosed,
            'scheduled_start_at' => now()->subMinute(),
        ]);
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/admin/games/{$game->id}/start")
            ->assertStatus(422)
            ->assertJsonPath('error', 'game_not_ready_for_start')
            ->assertJsonStructure(['reasons']);
    }

    public function test_draw_missing_header_returns_422(): void
    {
        $game = $this->makeRunningGame();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/admin/games/{$game->id}/draws")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['X-Draw-Command-Id']);
    }

    public function test_draw_invalid_uuid_returns_422(): void
    {
        $game = $this->makeRunningGame();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/admin/games/{$game->id}/draws", [], [
            'X-Draw-Command-Id' => 'not-a-uuid',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['X-Draw-Command-Id']);
    }

    public function test_draw_new_execution_returns_201(): void
    {
        $game = $this->makeRunningGame();
        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([2]));
        Sanctum::actingAs($this->admin());

        $cmd = (string) Str::uuid7();
        $this->postJson("/api/v1/admin/games/{$game->id}/draws", [], ['X-Draw-Command-Id' => $cmd])
            ->assertStatus(201)
            ->assertJsonPath('data.drawn_number', 2)
            ->assertJsonPath('data.sequence', 1)
            ->assertJsonPath('data.replay', false)
            ->assertJsonStructure(['data' => [
                'game_id', 'draw_id', 'game_number_id', 'sequence', 'drawn_number',
                'current_hits', 'hits_required', 'number_is_sold', 'winner_created',
                'winner_entry_id', 'game_status', 'drawn_at', 'replay',
            ]])
            ->assertJsonMissingPath('data.result_payload');
    }

    public function test_draw_replay_returns_200(): void
    {
        $game = $this->makeRunningGame();
        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([3, 3]));
        Sanctum::actingAs($this->admin());

        $cmd = (string) Str::uuid7();
        $this->postJson("/api/v1/admin/games/{$game->id}/draws", [], ['X-Draw-Command-Id' => $cmd])
            ->assertStatus(201);
        $this->postJson("/api/v1/admin/games/{$game->id}/draws", [], ['X-Draw-Command-Id' => $cmd])
            ->assertOk()
            ->assertJsonPath('data.replay', true);
    }

    public function test_rebuild_corrupt_then_consistent(): void
    {
        $game = $this->makeRunningGame();
        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([2]));
        Sanctum::actingAs($this->admin());

        // Produce one real draw so a counter exists; then poison it.
        $this->postJson("/api/v1/admin/games/{$game->id}/draws", [], ['X-Draw-Command-Id' => (string) Str::uuid7()])
            ->assertStatus(201);
        \DB::table('game_number_counters')->where('game_id', $game->id)
            ->update(['hits_count' => 999, 'last_draw_sequence' => 999]);

        $this->postJson("/api/v1/admin/games/{$game->id}/counters/rebuild")
            ->assertOk()
            ->assertJsonPath('data.outcome', 'rebuilt');

        $this->postJson("/api/v1/admin/games/{$game->id}/counters/rebuild")
            ->assertOk()
            ->assertJsonPath('data.outcome', 'already_consistent');
    }

    public function test_list_draws_ordered_and_paginated(): void
    {
        $game = $this->makeRunningGame();
        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([1, 2, 3]));
        Sanctum::actingAs($this->admin());

        for ($i = 0; $i < 3; $i++) {
            $this->postJson("/api/v1/admin/games/{$game->id}/draws", [], ['X-Draw-Command-Id' => (string) Str::uuid7()])
                ->assertStatus(201);
        }

        $resp = $this->getJson("/api/v1/admin/games/{$game->id}/draws?per_page=2")
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta']);
        $data = $resp->json('data');
        $this->assertCount(2, $data);
        $this->assertSame(1, $data[0]['sequence']);
        $this->assertSame(2, $data[1]['sequence']);
    }

    public function test_list_counters_returns_all_numbers_with_zero_default(): void
    {
        $game = $this->makeRunningGame();
        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([2]));
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/admin/games/{$game->id}/draws", [], ['X-Draw-Command-Id' => (string) Str::uuid7()])
            ->assertStatus(201);

        $resp = $this->getJson("/api/v1/admin/games/{$game->id}/counters")
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta']);
        $data = collect($resp->json('data'));
        $this->assertCount(5, $data);  // every game_number row
        $byNumber = $data->keyBy('number');
        $this->assertSame(0, $byNumber[1]['hits_count']);
        $this->assertSame(1, $byNumber[2]['hits_count']);
        $this->assertSame(0, $byNumber[3]['hits_count']);
    }

    public function test_list_counters_invalid_status_returns_422(): void
    {
        $game = $this->makeRunningGame();
        Sanctum::actingAs($this->admin());

        $this->getJson("/api/v1/admin/games/{$game->id}/counters?status=__bogus__")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_winner_returns_404_when_absent(): void
    {
        $game = $this->makeRunningGame();
        Sanctum::actingAs($this->admin());

        $this->getJson("/api/v1/admin/games/{$game->id}/winner")
            ->assertStatus(404)
            ->assertJsonPath('message', 'game_winner_not_found');
    }

    public function test_winner_returns_resource_without_pii(): void
    {
        $game = $this->makeSalesClosedReadyGame();
        // hits_required=2, sell number 1 already done; reach threshold.
        $game->hits_required = 2;
        $game->status = GameStatus::Running;
        $game->started_at = now()->subSecond();
        $game->saveQuietly();

        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([1, 1]));
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/admin/games/{$game->id}/draws", [], ['X-Draw-Command-Id' => (string) Str::uuid7()])
            ->assertStatus(201);
        $this->postJson("/api/v1/admin/games/{$game->id}/draws", [], ['X-Draw-Command-Id' => (string) Str::uuid7()])
            ->assertStatus(201);

        $resp = $this->getJson("/api/v1/admin/games/{$game->id}/winner")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'winner_id', 'game_id', 'game_entry_id', 'game_number_id',
                    'winning_number', 'game_draw_id', 'winning_draw_sequence',
                    'winning_hits', 'user_id', 'won_at',
                ],
            ]);

        $body = json_encode($resp->json());
        foreach (['email', 'name', 'phone', 'amount', 'price', 'order_id', 'payment_id', 'document'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }
}
