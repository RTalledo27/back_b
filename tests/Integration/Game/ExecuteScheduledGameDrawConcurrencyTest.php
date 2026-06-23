<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\DrawCommand;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Services\EngineTickCommandIdGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ExecuteScheduledGameDrawConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        DB::statement('TRUNCATE TABLE game_events, game_entries, game_numbers, draw_commands, game_winners, game_draws, game_number_counters, purchase_allocations, payment_documents, payments, number_reservations, order_items, orders, idempotency_keys, games, users RESTART IDENTITY CASCADE');
        parent::tearDown();
    }

    public function test_two_processes_for_same_tick_persist_one_draw(): void
    {
        $scheduledAt = CarbonImmutable::now()->startOfSecond()->subSeconds(5);
        $game = Game::create([
            'slug' => 'sc-'.fake()->unique()->lexify('?????'),
            'name' => 'Scheduled concurrency',
            'number_min' => 1,
            'number_max' => 5,
            'hits_required' => 5,
            'ticket_price_cents' => 500,
            'prize_cents' => 2000,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => GameStatus::Running,
            'scheduled_start_at' => $scheduledAt->subHour(),
            'started_at' => $scheduledAt->subMinutes(10),
            'next_draw_at' => $scheduledAt,
        ]);

        for ($number = 1; $number <= 5; $number++) {
            GameNumber::create([
                'game_id' => $game->id,
                'number' => $number,
                'status' => GameNumberStatus::Available,
            ]);
        }

        $commandId = app(EngineTickCommandIdGenerator::class)
            ->generate($game->id, $scheduledAt)
            ->toString();

        $first = $this->spawn($game->id, $scheduledAt, $commandId);
        $second = $this->spawn($game->id, $scheduledAt, $commandId);

        try {
            $first->wait();
            $second->wait();

            $this->assertSame(0, $first->getExitCode(), $first->getOutput().$first->getErrorOutput());
            $this->assertSame(0, $second->getExitCode(), $second->getOutput().$second->getErrorOutput());

            $outcomes = [
                json_decode(trim($first->getOutput()), true)['outcome'],
                json_decode(trim($second->getOutput()), true)['outcome'],
            ];
            sort($outcomes);

            $this->assertSame(['executed', 'replayed'], $outcomes);
            $this->assertSame(1, GameDraw::query()->where('game_id', $game->id)->count());
            $this->assertSame(1, DrawCommand::query()->where('game_id', $game->id)->count());
            $this->getJson("/api/v1/public/games/{$game->slug}")
                ->assertOk()
                ->assertJsonPath('data.latest_draw.sequence', 1)
                ->assertJsonPath('data.latest_draw.number', 3);
        } finally {
            foreach ([$first, $second] as $process) {
                if ($process->isRunning()) {
                    $process->stop(2);
                }
            }
        }
    }

    public function test_two_integrity_failures_auto_pause_once(): void
    {
        $scheduledAt = CarbonImmutable::now()->startOfSecond()->subSeconds(5);
        $game = $this->makeGame($scheduledAt);
        $corruptedNumber = GameNumber::query()
            ->where('game_id', $game->id)
            ->where('number', 3)
            ->firstOrFail();
        $corruptedNumber->status = GameNumberStatus::Sold;
        $corruptedNumber->save();

        $commandId = app(EngineTickCommandIdGenerator::class)
            ->generate($game->id, $scheduledAt)
            ->toString();

        $first = $this->spawn($game->id, $scheduledAt, $commandId, 'scheduled-job');
        $second = $this->spawn($game->id, $scheduledAt, $commandId, 'scheduled-job');

        try {
            $first->wait();
            $second->wait();

            $this->assertSame(0, $first->getExitCode(), $first->getOutput().$first->getErrorOutput());
            $this->assertSame(0, $second->getExitCode(), $second->getOutput().$second->getErrorOutput());

            $game->refresh();
            $this->assertSame(GameStatus::Paused, $game->status);
            $this->assertSame(
                1,
                GameEvent::query()
                    ->where('game_id', $game->id)
                    ->where('type', GameEventType::GameAutoPaused)
                    ->count(),
            );
            $this->assertSame(0, GameDraw::query()->where('game_id', $game->id)->count());
        } finally {
            foreach ([$first, $second] as $process) {
                if ($process->isRunning()) {
                    $process->stop(2);
                }
            }
        }
    }

    private function makeGame(CarbonImmutable $scheduledAt): Game
    {
        $game = Game::create([
            'slug' => 'sc-'.fake()->unique()->lexify('?????'),
            'name' => 'Scheduled concurrency',
            'number_min' => 1,
            'number_max' => 5,
            'hits_required' => 5,
            'ticket_price_cents' => 500,
            'prize_cents' => 2000,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => GameStatus::Running,
            'scheduled_start_at' => $scheduledAt->subHour(),
            'started_at' => $scheduledAt->subMinutes(10),
            'next_draw_at' => $scheduledAt,
        ]);

        for ($number = 1; $number <= 5; $number++) {
            GameNumber::create([
                'game_id' => $game->id,
                'number' => $number,
                'status' => GameNumberStatus::Available,
            ]);
        }

        return $game;
    }

    private function spawn(
        string $gameId,
        CarbonImmutable $scheduledAt,
        string $commandId,
        string $action = 'scheduled-draw',
    ): Process {
        $config = config('database.connections.pgsql');
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Support/run-engine-action.php'),
            $action,
            'GAME_ID='.$gameId,
            'SCHEDULED_AT='.$scheduledAt->toIso8601String(),
            'COMMAND_ID='.$commandId,
            'STRATEGY=deterministic',
            'STRATEGY_SEQUENCE=3',
        ]);
        $process->setWorkingDirectory(base_path());
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
}
