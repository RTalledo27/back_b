<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Handlers;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\Shared\Infrastructure\Outbox\Handlers\GameWinnerDeclaredNotificationHandler;
use App\Notifications\Domain\GameWinnerDeclaredNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class GameWinnerDeclaredNotificationHandlerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function makeHandler(): GameWinnerDeclaredNotificationHandler
    {
        return $this->app->make(GameWinnerDeclaredNotificationHandler::class);
    }

    public function test_sends_notification_and_creates_delivery(): void
    {
        Notification::fake();

        $winner = User::factory()->create();
        $game = Game::create([
            'slug' => 'g-'.Str::random(6), 'name' => 'Test', 'number_min' => 1, 'number_max' => 10,
            'hits_required' => 2, 'ticket_price_cents' => 100, 'prize_cents' => 500,
            'currency' => 'PEN', 'draw_interval_seconds' => 30, 'auto_draw_enabled' => false,
            'status' => GameStatus::Completed,
        ]);
        $gameNumber = GameNumber::create(['game_id' => $game->id, 'number' => 7]);
        $gameEntry = GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gameNumber->id,
            'user_id' => $winner->id, 'confirmed_at' => now(),
        ]);
        $gameDraw = GameDraw::create(['game_id' => $game->id, 'game_number_id' => $gameNumber->id, 'sequence' => 1, 'drawn_number' => 7, 'drawn_at' => now(), 'strategy' => 'manual']);
        $gameWinner = GameWinner::create([
            'game_id' => $game->id, 'game_entry_id' => $gameEntry->id,
            'game_draw_id' => $gameDraw->id, 'game_number_id' => $gameNumber->id,
            'user_id' => $winner->id, 'winning_hits' => 1, 'won_at' => now(),
        ]);

        $outboxEventId = (string) Str::uuid7();
        $payload = [
            'schema_version' => 1,
            'winner_user_id' => $winner->id,
            'game_winner_id' => $gameWinner->id,
            'game_id' => $game->id,
            'game_draw_id' => $gameDraw->id,
        ];

        $this->makeHandler()->handle($outboxEventId, $payload);

        Notification::assertSentTo($winner, GameWinnerDeclaredNotification::class);
        $this->assertDatabaseHas('notification_deliveries', [
            'outbox_event_id' => $outboxEventId,
            'status' => NotificationDelivery::STATUS_QUEUED,
        ]);
    }

    public function test_does_not_send_if_already_queued(): void
    {
        Notification::fake();

        $winner = User::factory()->create();
        $outboxEventId = (string) Str::uuid7();

        $delivery = NotificationDelivery::claim($outboxEventId, 'game_winner_declared', $winner->id, 'mail');
        $delivery->markQueued();

        $this->makeHandler()->handle($outboxEventId, ['winner_user_id' => $winner->id]);

        Notification::assertNothingSent();
    }

    public function test_throws_if_user_not_found(): void
    {
        $this->expectException(RuntimeException::class);

        $this->makeHandler()->handle((string) Str::uuid7(), [
            'winner_user_id' => 99999,
            'game_winner_id' => (string) Str::uuid7(),
        ]);
    }

    public function test_marks_failed_if_game_winner_not_found(): void
    {
        Notification::fake();

        $winner = User::factory()->create();

        $this->makeHandler()->handle((string) Str::uuid7(), [
            'winner_user_id' => $winner->id,
            'game_winner_id' => (string) Str::uuid7(),
            'game_id' => (string) Str::uuid7(),
        ]);

        Notification::assertNothingSent();
        $this->assertDatabaseHas('notification_deliveries', ['status' => NotificationDelivery::STATUS_FAILED]);
    }
}
