<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Events\GameCreated;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CreateGameTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, mixed> */
    private function validPayload(array $overrides = []): array
    {
        return array_replace([
            'slug' => 'rifa-junio',
            'name' => 'Rifa de Junio',
            'description' => 'Premio en efectivo.',
            'number_min' => 1,
            'number_max' => 30,
            'hits_required' => 5,
            'ticket_price_cents' => 500,
            'prize_cents' => 2000,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'sales_opens_at' => null,
            'sales_closes_at' => null,
            'scheduled_start_at' => null,
            'settings' => ['notes' => 'test'],
        ], $overrides);
    }

    public function test_admin_creates_game_generates_numbers_and_writes_audit_event(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        Event::fake([GameCreated::class]);

        $response = $this->postJson('/api/v1/admin/games', $this->validPayload());

        $response->assertCreated();

        $game = Game::query()->where('slug', 'rifa-junio')->firstOrFail();

        $this->assertSame(GameStatus::Draft, $game->status);
        $this->assertSame(1, $game->number_min);
        $this->assertSame(30, $game->number_max);
        $this->assertSame(5, $game->hits_required);
        $this->assertSame('PEN', $game->currency);
        $this->assertSame($admin->getKey(), $game->created_by);

        $this->assertSame(30, GameNumber::query()->where('game_id', $game->id)->count());

        $this->assertSame(
            1,
            GameEvent::query()
                ->where('game_id', $game->id)
                ->where('type', GameEventType::GameCreated)
                ->count(),
            'A single game_created audit row must exist.'
        );

        Event::assertDispatched(GameCreated::class, fn (GameCreated $e) => $e->gameId === $game->id);
    }

    public function test_rejects_payload_when_minimum_is_not_less_than_maximum(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/games', $this->validPayload(['number_min' => 30, 'number_max' => 30]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('number_max');
    }

    public function test_rejects_payload_when_hits_required_below_two(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/games', $this->validPayload(['hits_required' => 1]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('hits_required');
    }

    public function test_rejects_duplicate_slug(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/games', $this->validPayload())->assertCreated();

        $this->postJson('/api/v1/admin/games', $this->validPayload(['name' => 'Otra rifa']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    }
}
