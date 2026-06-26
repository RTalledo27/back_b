<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PublicGameNumbersReservationCompatibilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const IDEMPOTENCY_KEY = 'compat-key-aaaaaaaaaaaaaaaa';

    public function test_public_numbers_contract_returns_ids_accepted_by_reservation_write_contract(): void
    {
        $game = Game::create([
            'slug' => 'public-contract-compatible',
            'name' => 'Contrato compatible',
            'number_min' => 1,
            'number_max' => 3,
            'hits_required' => 5,
            'ticket_price_cents' => 500,
            'prize_cents' => 2000,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => GameStatus::SalesOpen,
        ]);

        $available = GameNumber::create([
            'game_id' => $game->id,
            'number' => 1,
            'status' => GameNumberStatus::Available,
        ]);
        GameNumber::create([
            'game_id' => $game->id,
            'number' => 2,
            'status' => GameNumberStatus::Reserved,
        ]);
        GameNumber::create([
            'game_id' => $game->id,
            'number' => 3,
            'status' => GameNumberStatus::Sold,
        ]);

        $publicResponse = $this->getJson("/api/v1/public/games/{$game->slug}/numbers")
            ->assertOk();

        $publicNumber = collect($publicResponse->json('data'))
            ->firstWhere('number', $available->number);

        $this->assertNotNull($publicNumber);
        $this->assertSame($available->id, $publicNumber['id']);
        $this->assertSame('available', $publicNumber['status']);

        Sanctum::actingAs(User::factory()->create());

        $reserveResponse = $this->postJson(
            "/api/v1/games/{$game->id}/reservations",
            ['game_number_ids' => [$publicNumber['id']]],
            ['Idempotency-Key' => self::IDEMPOTENCY_KEY],
        )->assertCreated();

        $reserveResponse->assertJsonPath('data.game_number_ids', [$publicNumber['id']]);
        $reserveResponse->assertJsonPath('data.numbers', [$available->number]);
        $this->assertSame(GameNumberStatus::Reserved, $available->refresh()->status);
    }
}
