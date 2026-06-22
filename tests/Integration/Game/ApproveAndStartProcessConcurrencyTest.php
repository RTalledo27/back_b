<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Models\NumberReservation;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\OrderItem;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real Approve ↔ Start race using two PHP processes. Each opens its own
 * PostgreSQL connection and competes for the Game FOR UPDATE lock.
 *
 * Two admissible final states (neither is wrong):
 *
 *  A) Approve wins the lock first:
 *     - Approve succeeds (payment approved, order paid, entries +
 *       allocations created BEFORE game.started_at).
 *     - Start then re-runs readiness: nothing pending → game running.
 *     - One PaymentApproved audit, one GameStarted audit.
 *
 *  B) Start wins the lock first:
 *     - Readiness sees the under_review payment → GameNotReadyForStart.
 *     - Game stays sales_closed.
 *     - Approve then proceeds normally (game is still sales_closed) and
 *       approves.
 *     - One PaymentApproved audit, zero GameStarted audits.
 *
 * The invariant the test asserts: no sale (GameEntry confirmed_at) is
 * ever created after game.started_at when the game finally lands in
 * running. And there is never more than one of each audit.
 */
final class ApproveAndStartProcessConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        DB::statement('TRUNCATE TABLE game_events, game_entries, game_numbers, draw_commands, game_winners, game_draws, game_number_counters, purchase_allocations, payment_documents, payments, number_reservations, order_items, orders, idempotency_keys, games, users RESTART IDENTITY CASCADE');
        parent::tearDown();
    }

    private function setupScenario(): array
    {
        $buyer = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $game = Game::create([
            'slug' => 'av-'.fake()->unique()->lexify('?????'),
            'name' => 'AV', 'number_min' => 1, 'number_max' => 10, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::SalesClosed,
            'scheduled_start_at' => now()->subMinute(),
        ]);
        // One sold number with confirmed entry — readiness passes only
        // because at least one Confirmed entry exists.
        $soldGn = GameNumber::create([
            'game_id' => $game->id, 'number' => 1, 'status' => GameNumberStatus::Sold,
        ]);
        GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $soldGn->id,
            'user_id' => User::factory()->create()->id,
            'status' => EntryStatus::Confirmed, 'confirmed_at' => now(),
        ]);

        // The order being raced — number 7 reserved, payment under_review.
        $reservedGn = GameNumber::create([
            'game_id' => $game->id, 'number' => 7, 'status' => GameNumberStatus::Reserved,
        ]);
        $order = Order::create([
            'user_id' => $buyer->id, 'game_id' => $game->id,
            'status' => OrderStatus::PaymentSubmitted,
            'subtotal_cents' => 500, 'total_cents' => 500,
            'currency' => 'PEN', 'expires_at' => null,
        ]);
        OrderItem::create(['order_id' => $order->id, 'game_number_id' => $reservedGn->id, 'unit_price_cents' => 500]);
        NumberReservation::create(['order_id' => $order->id, 'game_number_id' => $reservedGn->id]);
        $payment = Payment::create([
            'order_id' => $order->id, 'amount_cents' => 500, 'currency' => 'PEN',
            'method' => PaymentMethod::Manual, 'status' => PaymentStatus::UnderReview,
            'submitted_at' => now()->subMinute(),
        ]);

        return [$game, $admin, $payment, $reservedGn];
    }

    private function spawn(array $args): Process
    {
        $base = base_path();
        $config = config('database.connections.pgsql');

        $process = new Process(array_merge(
            [PHP_BINARY, $base.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'run-engine-action.php'],
            $args,
        ));
        $process->setWorkingDirectory($base);
        $process->setEnv([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => $config['host'],
            'DB_PORT' => (string) $config['port'],
            'DB_DATABASE' => $config['database'],
            'DB_USERNAME' => $config['username'],
            'DB_PASSWORD' => $config['password'],
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'MAIL_MAILER' => 'array',
        ]);
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    public function test_approve_and_start_compete_for_game_lock(): void
    {
        [$game, $admin, $payment, $reservedGn] = $this->setupScenario();

        $approve = null;
        $start = null;
        try {
            $approve = $this->spawn(['approve', 'PAYMENT_ID='.$payment->id, 'REVIEWER_USER_ID='.$admin->id]);
            $start = $this->spawn(['start', 'GAME_ID='.$game->id, 'ACTOR_USER_ID='.$admin->id]);

            $approve->wait();
            $start->wait();

            $approveOut = json_decode(trim($approve->getOutput()), true) ?? [];
            $startOut = json_decode(trim($start->getOutput()), true) ?? [];

            $this->assertIsArray($approveOut, 'Approve output: '.$approve->getOutput().$approve->getErrorOutput());
            $this->assertIsArray($startOut, 'Start output: '.$start->getOutput().$start->getErrorOutput());

            // Approve must always succeed (its only blocker is game status,
            // and game is sales_closed in both admissible branches).
            $this->assertTrue($approveOut['ok'] ?? false, 'Approve unexpectedly failed: '.json_encode($approveOut));
            $this->assertSame('approved', $approveOut['payment_status']);

            $game->refresh();

            if ($startOut['ok'] ?? false) {
                // Branch A: Approve won, Start succeeded after.
                $this->assertSame(GameStatus::Running, $game->status);
                $this->assertNotNull($game->started_at);

                // Invariant: no entry can be created AFTER game.started_at.
                $latestEntry = GameEntry::query()
                    ->where('game_id', $game->id)
                    ->orderByDesc('confirmed_at')
                    ->first();
                $this->assertNotNull($latestEntry);
                $this->assertTrue(
                    $latestEntry->confirmed_at->lessThanOrEqualTo($game->started_at),
                    'Found a GameEntry whose confirmed_at is later than game.started_at.',
                );
            } else {
                // Branch B: Start lost the race, readiness rejected it.
                $this->assertSame(GameStatus::SalesClosed, $game->status);
                $this->assertNull($game->started_at);
                $this->assertSame(
                    'App\\Modules\\RepeatNumberBingo\\Domain\\Exceptions\\GameNotReadyForStart',
                    $startOut['class'],
                );
            }

            // Audits never duplicate, regardless of branch.
            $this->assertSame(
                1,
                GameEvent::query()->where('game_id', $game->id)
                    ->where('type', GameEventType::PaymentApproved)->count(),
            );
            $expectedGameStarted = ($startOut['ok'] ?? false) ? 1 : 0;
            $this->assertSame(
                $expectedGameStarted,
                GameEvent::query()->where('game_id', $game->id)
                    ->where('type', GameEventType::GameStarted)->count(),
            );
        } finally {
            foreach ([$approve, $start] as $proc) {
                if ($proc instanceof Process && $proc->isRunning()) {
                    $proc->stop(2);
                }
            }
        }
    }
}
