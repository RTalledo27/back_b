<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Handlers;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Modules\Commerce\Domain\Models\WinnerPayout;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\Shared\Infrastructure\Outbox\Handlers\WinnerPayoutRegisteredNotificationHandler;
use App\Notifications\Domain\WinnerPayoutRegisteredNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class WinnerPayoutRegisteredNotificationHandlerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function makeHandler(): WinnerPayoutRegisteredNotificationHandler
    {
        return $this->app->make(WinnerPayoutRegisteredNotificationHandler::class);
    }

    public function test_sends_notification_and_creates_delivery(): void
    {
        Notification::fake();

        $winner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);

        $game = Game::create([
            'slug' => 'g-'.Str::random(6), 'name' => 'Test', 'number_min' => 1, 'number_max' => 10,
            'hits_required' => 2, 'ticket_price_cents' => 100, 'prize_cents' => 500,
            'currency' => 'PEN', 'draw_interval_seconds' => 30, 'auto_draw_enabled' => false,
            'status' => GameStatus::Completed,
        ]);

        $gameNumber = GameNumber::create(['game_id' => $game->id, 'number' => 5]);
        $gameEntry = GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gameNumber->id,
            'user_id' => $winner->id, 'confirmed_at' => now(),
        ]);
        $gameDraw = GameDraw::create(['game_id' => $game->id, 'game_number_id' => $gameNumber->id, 'sequence' => 1, 'drawn_number' => 5, 'drawn_at' => now(), 'strategy' => 'manual']);

        $gameWinner = GameWinner::create([
            'game_id' => $game->id, 'game_entry_id' => $gameEntry->id,
            'game_draw_id' => $gameDraw->id, 'game_number_id' => $gameNumber->id,
            'user_id' => $winner->id, 'winning_hits' => 1, 'won_at' => now(),
        ]);

        $payout = WinnerPayout::create([
            'game_winner_id' => $gameWinner->id, 'game_id' => $game->id,
            'user_id' => $winner->id, 'amount_cents' => 500, 'currency' => 'PEN',
            'method' => 'manual', 'external_reference' => 'REF001',
            'idempotency_key_hash' => hash('sha256', (string) Str::uuid7()),
            'request_fingerprint' => hash('sha256', (string) Str::uuid7()),
            'processed_by_user_id' => $admin->id, 'processed_at' => now(),
        ]);

        $outboxEventId = (string) Str::uuid7();
        $payload = [
            'schema_version' => 1,
            'winner_user_id' => $winner->id,
            'winner_payout_id' => $payout->id,
            'game_winner_id' => $gameWinner->id,
            'game_id' => $game->id,
        ];

        $this->makeHandler()->handle($outboxEventId, $payload);

        Notification::assertSentTo($winner, WinnerPayoutRegisteredNotification::class);
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

        $delivery = NotificationDelivery::claim($outboxEventId, 'winner_payout_registered', $winner->id, 'mail');
        $delivery->markQueued();

        $this->makeHandler()->handle($outboxEventId, ['winner_user_id' => $winner->id]);

        Notification::assertNothingSent();
    }

    public function test_throws_if_user_not_found(): void
    {
        $this->expectException(RuntimeException::class);

        $this->makeHandler()->handle((string) Str::uuid7(), [
            'winner_user_id' => 99999,
            'winner_payout_id' => (string) Str::uuid7(),
        ]);
    }

    public function test_marks_failed_if_payout_not_found(): void
    {
        Notification::fake();

        $winner = User::factory()->create();

        $this->makeHandler()->handle((string) Str::uuid7(), [
            'winner_user_id' => $winner->id,
            'winner_payout_id' => (string) Str::uuid7(),
            'game_id' => (string) Str::uuid7(),
        ]);

        Notification::assertNothingSent();
        $this->assertDatabaseHas('notification_deliveries', ['status' => NotificationDelivery::STATUS_FAILED]);
    }
}
