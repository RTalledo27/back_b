<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Models\NumberReservation;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\OrderItem;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\RepeatNumberBingo\Application\Actions\StartGameAction;
use App\Modules\RepeatNumberBingo\Application\DTOs\StartGameData;
use App\Modules\RepeatNumberBingo\Application\DTOs\StartGameOutcome;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Events\GameStarted;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameAlreadyCompleted;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameHasNoScheduledStart;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameLifecycleIntegrityViolation;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameNotReadyForStart;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameStartTooEarly;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidGameTransition;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class StartGameActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function makeGame(GameStatus $status, ?\DateTimeInterface $scheduledStartAt): Game
    {
        return Game::create([
            'slug' => 'sg-'.fake()->unique()->lexify('?????'),
            'name' => 'SG', 'number_min' => 1, 'number_max' => 10, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => $status,
            'scheduled_start_at' => $scheduledStartAt,
        ]);
    }

    private function makeConfirmedEntry(Game $game, int $number = 1): GameEntry
    {
        $gn = GameNumber::create([
            'game_id' => $game->id, 'number' => $number, 'status' => GameNumberStatus::Sold,
        ]);

        return GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'user_id' => User::factory()->create()->id,
            'status' => EntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_starts_a_valid_game(): void
    {
        Event::fake([GameStarted::class]);

        $game = $this->makeGame(GameStatus::SalesClosed, now()->subMinute());
        $this->makeConfirmedEntry($game, 1);
        $this->makeConfirmedEntry($game, 2);
        $admin = $this->admin();

        $result = $this->app->make(StartGameAction::class)->execute(
            new StartGameData(gameId: $game->id, actorUserId: $admin->id),
        );

        $this->assertSame(StartGameOutcome::Started, $result->outcome);
        $this->assertSame(2, $result->confirmedEntriesCount);
        $this->assertTrue($result->outcome->wasTransitionApplied());

        $game->refresh();
        $this->assertSame(GameStatus::Running, $game->status);
        $this->assertNotNull($game->started_at);
        $this->assertSame(
            $game->started_at->toIso8601String(),
            $result->startedAt->toIso8601String(),
        );

        $audits = GameEvent::query()->where('game_id', $game->id)
            ->where('type', GameEventType::GameStarted)->get();
        $this->assertCount(1, $audits);
        $payload = $audits->first()->payload;
        $this->assertSame($admin->id, $payload['actor_user_id']);
        $this->assertSame(2, $payload['confirmed_entries_count']);
        $this->assertArrayNotHasKey('email', $payload);
        $this->assertArrayNotHasKey('payment_id', $payload);

        Event::assertDispatched(GameStarted::class, 1);
    }

    public function test_rejects_without_scheduled_start_at(): void
    {
        $game = $this->makeGame(GameStatus::SalesClosed, null);
        $this->makeConfirmedEntry($game, 1);

        $this->expectException(GameHasNoScheduledStart::class);
        $this->app->make(StartGameAction::class)->execute(
            new StartGameData($game->id, $this->admin()->id),
        );
    }

    public function test_rejects_when_now_is_before_scheduled(): void
    {
        $game = $this->makeGame(GameStatus::SalesClosed, now()->addHour());
        $this->makeConfirmedEntry($game, 1);

        $this->expectException(GameStartTooEarly::class);
        $this->app->make(StartGameAction::class)->execute(
            new StartGameData($game->id, $this->admin()->id),
        );
    }

    public function test_rejects_when_no_confirmed_entries(): void
    {
        $game = $this->makeGame(GameStatus::SalesClosed, now()->subMinute());

        try {
            $this->app->make(StartGameAction::class)->execute(
                new StartGameData($game->id, $this->admin()->id),
            );
            $this->fail('Expected GameNotReadyForStart.');
        } catch (GameNotReadyForStart $e) {
            $this->assertContains('no_confirmed_entries', $e->reasons);
        }
        $this->assertSame(GameStatus::SalesClosed, $game->refresh()->status);
        $this->assertNull($game->started_at);
    }

    /**
     * @return iterable<string, array{OrderStatus, PaymentStatus, list<string>}>
     */
    public static function readinessBlockers(): iterable
    {
        return [
            'pending order' => [OrderStatus::Pending, PaymentStatus::Pending, ['has_pending_orders', 'has_pending_payments']],
            'payment submitted' => [OrderStatus::PaymentSubmitted, PaymentStatus::UnderReview, ['has_payment_submitted_orders', 'has_under_review_payments']],
        ];
    }

    /**
     * @param  list<string>  $expectedReasons
     */
    #[DataProvider('readinessBlockers')]
    public function test_rejects_with_active_commerce_operations(OrderStatus $orderStatus, PaymentStatus $paymentStatus, array $expectedReasons): void
    {
        $game = $this->makeGame(GameStatus::SalesClosed, now()->subMinute());
        $this->makeConfirmedEntry($game, 1);

        $buyer = User::factory()->create();
        $order = Order::create([
            'user_id' => $buyer->id, 'game_id' => $game->id, 'status' => $orderStatus,
            'subtotal_cents' => 500, 'total_cents' => 500, 'currency' => 'PEN',
            'expires_at' => $orderStatus === OrderStatus::Pending ? now()->addHour() : null,
        ]);
        Payment::create([
            'order_id' => $order->id, 'amount_cents' => 500, 'currency' => 'PEN',
            'method' => PaymentMethod::Manual, 'status' => $paymentStatus,
            'submitted_at' => $paymentStatus === PaymentStatus::UnderReview ? now() : null,
        ]);

        try {
            $this->app->make(StartGameAction::class)->execute(
                new StartGameData($game->id, $this->admin()->id),
            );
            $this->fail('Expected GameNotReadyForStart.');
        } catch (GameNotReadyForStart $e) {
            foreach ($expectedReasons as $reason) {
                $this->assertContains($reason, $e->reasons);
            }
        }
    }

    public function test_rejects_with_active_reservation_and_reserved_number(): void
    {
        $game = $this->makeGame(GameStatus::SalesClosed, now()->subMinute());
        $this->makeConfirmedEntry($game, 1);

        $buyer = User::factory()->create();
        $order = Order::create([
            'user_id' => $buyer->id, 'game_id' => $game->id, 'status' => OrderStatus::Pending,
            'subtotal_cents' => 500, 'total_cents' => 500, 'currency' => 'PEN',
            'expires_at' => now()->addHour(),
        ]);
        $gn = GameNumber::create(['game_id' => $game->id, 'number' => 5, 'status' => GameNumberStatus::Reserved]);
        OrderItem::create(['order_id' => $order->id, 'game_number_id' => $gn->id, 'unit_price_cents' => 500]);
        NumberReservation::create(['order_id' => $order->id, 'game_number_id' => $gn->id]);
        Payment::create([
            'order_id' => $order->id, 'amount_cents' => 500, 'currency' => 'PEN',
            'method' => PaymentMethod::Manual, 'status' => PaymentStatus::Pending,
        ]);

        try {
            $this->app->make(StartGameAction::class)->execute(
                new StartGameData($game->id, $this->admin()->id),
            );
            $this->fail('Expected GameNotReadyForStart.');
        } catch (GameNotReadyForStart $e) {
            foreach (['has_active_reservations', 'has_reserved_numbers', 'has_pending_orders', 'has_pending_payments'] as $reason) {
                $this->assertContains($reason, $e->reasons);
            }
        }
    }

    public function test_repeated_start_returns_already_started_without_audit_or_event(): void
    {
        Event::fake([GameStarted::class]);

        $game = $this->makeGame(GameStatus::SalesClosed, now()->subMinute());
        $this->makeConfirmedEntry($game, 1);
        $admin = $this->admin();

        $first = $this->app->make(StartGameAction::class)->execute(
            new StartGameData($game->id, $admin->id),
        );
        $this->assertSame(StartGameOutcome::Started, $first->outcome);

        $game->refresh();
        $originalStartedAt = $game->started_at->toIso8601String();

        $second = $this->app->make(StartGameAction::class)->execute(
            new StartGameData($game->id, $admin->id),
        );
        $this->assertSame(StartGameOutcome::AlreadyStarted, $second->outcome);
        $this->assertFalse($second->outcome->wasTransitionApplied());
        $this->assertSame($originalStartedAt, $second->startedAt->toIso8601String());

        $this->assertSame($originalStartedAt, $game->refresh()->started_at->toIso8601String());

        $this->assertSame(
            1,
            GameEvent::query()->where('game_id', $game->id)
                ->where('type', GameEventType::GameStarted)->count(),
        );

        Event::assertDispatched(GameStarted::class, 1);
    }

    public function test_running_without_started_at_is_rejected_as_integrity(): void
    {
        $game = $this->makeGame(GameStatus::SalesClosed, now()->subMinute());
        $game->status = GameStatus::Running;
        $game->saveQuietly();    // bypass transitionTo for the corruption scenario

        $this->expectException(GameLifecycleIntegrityViolation::class);
        $this->app->make(StartGameAction::class)->execute(
            new StartGameData($game->id, $this->admin()->id),
        );
    }

    public function test_sales_closed_with_started_at_is_rejected_as_integrity(): void
    {
        $game = $this->makeGame(GameStatus::SalesClosed, now()->subMinute());
        $game->started_at = now()->subHour();
        $game->saveQuietly();

        $this->expectException(GameLifecycleIntegrityViolation::class);
        $this->app->make(StartGameAction::class)->execute(
            new StartGameData($game->id, $this->admin()->id),
        );
    }

    public function test_completed_at_before_start_is_rejected_as_integrity(): void
    {
        $game = $this->makeGame(GameStatus::SalesClosed, now()->subMinute());
        $game->completed_at = now()->subHour();
        $game->saveQuietly();

        $this->expectException(GameLifecycleIntegrityViolation::class);
        $this->app->make(StartGameAction::class)->execute(
            new StartGameData($game->id, $this->admin()->id),
        );
    }

    public function test_consistent_completed_status_raises_already_completed(): void
    {
        // Build a fully-finished game via raw state assignment.
        $game = $this->makeGame(GameStatus::SalesClosed, now()->subMinute());
        $game->status = GameStatus::Running;
        $game->started_at = now()->subHour();
        $game->saveQuietly();
        $game->status = GameStatus::Resolving;
        $game->saveQuietly();
        $game->status = GameStatus::Completed;
        $game->completed_at = now()->subMinute();
        $game->saveQuietly();

        // status=completed + started_at set + completed_at set → consistent.
        $this->expectException(GameAlreadyCompleted::class);
        $this->app->make(StartGameAction::class)->execute(
            new StartGameData($game->id, $this->admin()->id),
        );
    }

    public function test_completed_without_completed_at_is_corruption(): void
    {
        $game = $this->makeGame(GameStatus::SalesClosed, now()->subMinute());
        $game->status = GameStatus::Completed;
        $game->started_at = now()->subHour();
        $game->saveQuietly();

        // status=completed but completed_at is null → impossible state.
        $this->expectException(GameLifecycleIntegrityViolation::class);
        $this->app->make(StartGameAction::class)->execute(
            new StartGameData($game->id, $this->admin()->id),
        );
    }

    public function test_completed_without_started_at_is_corruption(): void
    {
        $game = $this->makeGame(GameStatus::SalesClosed, now()->subMinute());
        $game->status = GameStatus::Completed;
        $game->completed_at = now()->subMinute();
        $game->saveQuietly();

        $this->expectException(GameLifecycleIntegrityViolation::class);
        $this->app->make(StartGameAction::class)->execute(
            new StartGameData($game->id, $this->admin()->id),
        );
    }

    public function test_draft_state_uses_invalid_transition_exception(): void
    {
        $game = $this->makeGame(GameStatus::Draft, now()->subMinute());

        $this->expectException(InvalidGameTransition::class);
        $this->app->make(StartGameAction::class)->execute(
            new StartGameData($game->id, $this->admin()->id),
        );
    }
}
