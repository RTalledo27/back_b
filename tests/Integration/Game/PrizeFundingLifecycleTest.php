<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Application\Actions\CancelGameAction;
use App\Modules\RepeatNumberBingo\Application\Actions\PublishGameAction;
use App\Modules\RepeatNumberBingo\Application\Actions\StartGameAction;
use App\Modules\RepeatNumberBingo\Application\DTOs\StartGameData;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GamePrizeFundingEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GamePrizeFundingStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GamePrizeFundingNotProcessable;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GamePrizeFunding;
use App\Modules\RepeatNumberBingo\Domain\Models\GamePrizeFundingEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class PrizeFundingLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unfunded_game_cannot_be_published(): void
    {
        $game = $this->game(GameStatus::Draft, GamePrizeFundingStatus::Unfunded);

        $this->expectException(GamePrizeFundingNotProcessable::class);

        $this->app->make(PublishGameAction::class)->execute($game->id);
    }

    public function test_funded_game_can_be_published(): void
    {
        $game = $this->game(GameStatus::Draft, GamePrizeFundingStatus::Funded);

        $published = $this->app->make(PublishGameAction::class)->execute($game->id);

        $this->assertSame(GameStatus::Published, $published->status);
    }

    public function test_start_reserves_funding_atomically_with_game_transition(): void
    {
        Event::fake();
        $game = $this->readyGame(GamePrizeFundingStatus::Funded);
        $admin = User::factory()->admin()->create();

        $this->app->make(StartGameAction::class)->execute(
            new StartGameData($game->id, $admin->id),
        );

        $game->refresh();
        $funding = GamePrizeFunding::query()->where('game_id', $game->id)->firstOrFail();

        $this->assertSame(GameStatus::Running, $game->status);
        $this->assertSame(GamePrizeFundingStatus::Reserved, $funding->status);
        $this->assertNotNull($funding->reserved_at);
        $this->assertSame(1, GamePrizeFundingEvent::query()
            ->where('game_prize_funding_id', $funding->id)
            ->where('event_type', GamePrizeFundingEventType::FundingReserved)
            ->count());
        $this->assertSame(1, GameEvent::query()
            ->where('game_id', $game->id)
            ->where('type', GameEventType::GameStarted)
            ->count());
    }

    public function test_game_cancellation_releases_funded_prize(): void
    {
        $game = $this->game(GameStatus::SalesClosed, GamePrizeFundingStatus::Funded);

        $this->app->make(CancelGameAction::class)->execute($game->id, 'cancelled by admin');

        $funding = GamePrizeFunding::query()->where('game_id', $game->id)->firstOrFail();

        $this->assertSame(GameStatus::Cancelled, $game->refresh()->status);
        $this->assertSame(GamePrizeFundingStatus::Released, $funding->refresh()->status);
        $this->assertSame('game_cancelled', $funding->release_reason_code);
        $this->assertSame(1, GamePrizeFundingEvent::query()
            ->where('game_prize_funding_id', $funding->id)
            ->where('event_type', GamePrizeFundingEventType::FundingReleased)
            ->count());
    }

    public function test_game_cancellation_releases_reserved_prize(): void
    {
        $game = $this->game(GameStatus::Paused, GamePrizeFundingStatus::Reserved);

        $this->app->make(CancelGameAction::class)->execute($game->id);

        $this->assertSame(
            GamePrizeFundingStatus::Released,
            GamePrizeFunding::query()->where('game_id', $game->id)->firstOrFail()->status,
        );
    }

    public function test_completed_game_does_not_release_prize_automatically(): void
    {
        $game = $this->game(GameStatus::Completed, GamePrizeFundingStatus::Reserved);

        $this->assertSame(
            GamePrizeFundingStatus::Reserved,
            GamePrizeFunding::query()->where('game_id', $game->id)->firstOrFail()->status,
        );
        $this->assertDatabaseMissing('game_prize_funding_events', [
            'game_prize_funding_id' => GamePrizeFunding::query()->where('game_id', $game->id)->value('id'),
            'event_type' => GamePrizeFundingEventType::FundingReleased->value,
        ]);
    }

    public function test_create_game_action_creates_unfunded_funding_in_same_business_flow(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/games', [
            'slug' => 'funding-created-'.fake()->unique()->lexify('????'),
            'name' => 'Funding created',
            'number_min' => 1,
            'number_max' => 10,
            'hits_required' => 2,
            'ticket_price_cents' => 500,
            'prize_cents' => 2000,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
        ]);

        $response->assertCreated();
        $gameId = (string) $response->json('data.id');

        $this->assertDatabaseHas('game_prize_fundings', [
            'game_id' => $gameId,
            'status' => GamePrizeFundingStatus::Unfunded->value,
            'amount_cents' => 2000,
            'currency' => 'PEN',
        ]);
        $this->assertDatabaseHas('game_prize_funding_events', [
            'game_prize_funding_id' => GamePrizeFunding::query()->where('game_id', $gameId)->value('id'),
            'event_type' => GamePrizeFundingEventType::FundingCreated->value,
        ]);
    }

    private function game(
        GameStatus $status,
        GamePrizeFundingStatus $fundingStatus,
    ): Game {
        $game = Game::create([
            'slug' => 'lifecycle-'.fake()->unique()->lexify('??????'),
            'name' => 'Lifecycle',
            'number_min' => 1,
            'number_max' => 10,
            'hits_required' => 2,
            'ticket_price_cents' => 500,
            'prize_cents' => 2000,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => $status,
            'scheduled_start_at' => $status === GameStatus::SalesClosed ? now()->subMinute() : null,
        ]);

        GamePrizeFunding::create([
            'game_id' => $game->id,
            'status' => $fundingStatus,
            'amount_cents' => 2000,
            'currency' => 'PEN',
        ]);

        return $game;
    }

    private function readyGame(GamePrizeFundingStatus $fundingStatus): Game
    {
        $game = $this->game(GameStatus::SalesClosed, $fundingStatus);
        $number = GameNumber::create([
            'game_id' => $game->id,
            'number' => 1,
            'status' => GameNumberStatus::Sold,
        ]);
        GameEntry::create([
            'game_id' => $game->id,
            'game_number_id' => $number->id,
            'user_id' => User::factory()->create()->id,
            'status' => EntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        return $game;
    }
}
