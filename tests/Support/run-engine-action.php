<?php

declare(strict_types=1);

/**
 * Out-of-band entry point used by the concurrency tests to run engine
 * Actions in a separate PHP process. Each process opens its own
 * connection to PostgreSQL, so two simultaneous invocations actually
 * race on row locks at the database level.
 *
 * Usage:
 *   php tests/Support/run-engine-action.php <action> [arg=value ...]
 *
 * Supported actions:
 *   start    GAME_ID=<uuid>  ACTOR_USER_ID=<int>
 *   approve  PAYMENT_ID=<uuid>  REVIEWER_USER_ID=<int>
 *
 * The script prints a single line of JSON to stdout and exits 0 on
 * success or 1 on any thrown exception. Tests parse the JSON.
 */

use App\Modules\Commerce\Application\Actions\ApprovePaymentAction;
use App\Modules\Commerce\Application\DTOs\ApprovePaymentData;
use App\Modules\RepeatNumberBingo\Application\Actions\DrawGameNumberAction;
use App\Modules\RepeatNumberBingo\Application\Actions\ExecuteScheduledGameDrawAction;
use App\Modules\RepeatNumberBingo\Application\Actions\RebuildGameNumberCountersAction;
use App\Modules\RepeatNumberBingo\Application\Actions\StartGameAction;
use App\Modules\RepeatNumberBingo\Application\Contracts\DrawNumberStrategy;
use App\Modules\RepeatNumberBingo\Application\DTOs\DrawGameNumberData;
use App\Modules\RepeatNumberBingo\Application\DTOs\RebuildCountersData;
use App\Modules\RepeatNumberBingo\Application\DTOs\StartGameData;
use App\Modules\RepeatNumberBingo\Application\Jobs\ExecuteScheduledGameDrawJob;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\DrawCommandId;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\EngineTick;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Tests\Support\DeterministicDrawNumberStrategy;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

/** @var Application $app */
$app = require $root.'/bootstrap/app.php';

// Force the testing environment so we hit the same DB the test does.
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$app->detectEnvironment(static fn () => 'testing');

/** @var ConsoleKernel $kernel */
$kernel = $app->make(ConsoleKernel::class);
$kernel->bootstrap();

// ----- Safety guards -----
// This script executes Actions for real against a live PostgreSQL
// connection. Refuse to operate unless every signal indicates the
// testing environment. Bail with a generic message — never leak the
// connection details, credentials or computed paths.
$abort = static function (string $code): never {
    fwrite(STDERR, "run-engine-action.php: refused to run — $code\n");
    exit(3);
};

if ($app->environment() !== 'testing') {
    $abort('not_in_testing_environment');
}
if (config('database.default') !== 'pgsql') {
    $abort('wrong_default_connection');
}
$dbName = (string) (config('database.connections.pgsql.database') ?? '');
if (! str_contains($dbName, 'test')) {
    $abort('database_name_missing_test_suffix');
}

$argvCopy = $argv;
array_shift($argvCopy); // script
$action = array_shift($argvCopy);

$args = [];
foreach ($argvCopy as $pair) {
    if (str_contains($pair, '=')) {
        [$k, $v] = explode('=', $pair, 2);
        $args[$k] = $v;
    }
}

// Optional deterministic strategy override (testing only). Lives entirely
// inside tests/Support — never touches production code.
if (($args['STRATEGY'] ?? '') === 'deterministic') {
    $sequence = array_map('intval', explode(',', (string) ($args['STRATEGY_SEQUENCE'] ?? '')));
    $app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy($sequence));
}

try {
    if ($action === 'start') {
        $result = $app->make(StartGameAction::class)->execute(
            new StartGameData(
                gameId: (string) ($args['GAME_ID'] ?? ''),
                actorUserId: (int) ($args['ACTOR_USER_ID'] ?? 0),
            ),
        );
        echo json_encode([
            'ok' => true,
            'action' => 'start',
            'outcome' => $result->outcome->value,
            'game_id' => $result->gameId,
            'started_at' => $result->startedAt->toIso8601String(),
        ]).PHP_EOL;
        exit(0);
    }

    if ($action === 'scheduled-draw') {
        $result = $app->make(ExecuteScheduledGameDrawAction::class)->execute(
            new EngineTick(
                gameId: (string) ($args['GAME_ID'] ?? ''),
                scheduledAt: CarbonImmutable::parse((string) ($args['SCHEDULED_AT'] ?? '')),
                commandId: new DrawCommandId((string) ($args['COMMAND_ID'] ?? '')),
            ),
        );
        echo json_encode([
            'ok' => true,
            'action' => 'scheduled-draw',
            'game_id' => $result->gameId,
            'outcome' => $result->outcome->value,
            'draw_id' => $result->drawResult?->drawId,
            'was_replay' => $result->drawResult?->wasReplay,
        ]).PHP_EOL;
        exit(0);
    }

    if ($action === 'scheduled-job') {
        $tick = new EngineTick(
            gameId: (string) ($args['GAME_ID'] ?? ''),
            scheduledAt: CarbonImmutable::parse((string) ($args['SCHEDULED_AT'] ?? '')),
            commandId: new DrawCommandId((string) ($args['COMMAND_ID'] ?? '')),
        );
        $app->call([new ExecuteScheduledGameDrawJob($tick), 'handle']);
        $game = Game::query()->findOrFail($tick->gameId);

        echo json_encode([
            'ok' => true,
            'action' => 'scheduled-job',
            'game_id' => $game->id,
            'status' => $game->status->value,
            'auto_pause_audits' => GameEvent::query()
                ->where('game_id', $game->id)
                ->where('type', GameEventType::GameAutoPaused)
                ->count(),
        ]).PHP_EOL;
        exit(0);
    }

    if ($action === 'draw') {
        $result = $app->make(DrawGameNumberAction::class)->execute(
            new DrawGameNumberData(
                gameId: (string) ($args['GAME_ID'] ?? ''),
                commandId: new DrawCommandId((string) ($args['COMMAND_ID'] ?? '')),
                actorUserId: (int) ($args['ACTOR_USER_ID'] ?? 0),
            ),
        );
        echo json_encode([
            'ok' => true,
            'action' => 'draw',
            'game_id' => $result->gameId,
            'draw_id' => $result->drawId,
            'sequence' => $result->sequence,
            'drawn_number' => $result->drawnNumber,
            'current_hits' => $result->currentHits,
            'number_is_sold' => $result->numberIsSold,
            'was_replay' => $result->wasReplay,
            'drawn_at' => $result->drawnAt->toIso8601String(),
        ]).PHP_EOL;
        exit(0);
    }

    if ($action === 'rebuild') {
        $result = $app->make(RebuildGameNumberCountersAction::class)->execute(
            new RebuildCountersData(
                gameId: (string) ($args['GAME_ID'] ?? ''),
                actorUserId: (int) ($args['ACTOR_USER_ID'] ?? 0),
            ),
        );
        echo json_encode([
            'ok' => true,
            'action' => 'rebuild',
            'game_id' => $result->gameId,
            'outcome' => $result->outcome->value,
            'previous_rows' => $result->previousRows,
            'rebuilt_rows' => $result->rebuiltRows,
            'total_draws' => $result->totalDraws,
            'rebuilt_at' => $result->rebuiltAt->toIso8601String(),
        ]).PHP_EOL;
        exit(0);
    }

    if ($action === 'approve') {
        $result = $app->make(ApprovePaymentAction::class)->execute(
            new ApprovePaymentData(
                paymentId: (string) ($args['PAYMENT_ID'] ?? ''),
                reviewerUserId: (int) ($args['REVIEWER_USER_ID'] ?? 0),
                notes: null,
            ),
        );
        echo json_encode([
            'ok' => true,
            'action' => 'approve',
            'payment_id' => $result->paymentId,
            'order_status' => $result->orderStatus,
            'payment_status' => $result->paymentStatus,
            'was_transition_applied' => $result->wasTransitionApplied,
            'paid_at' => $result->paidAt,
        ]).PHP_EOL;
        exit(0);
    }

    fwrite(STDERR, "Unknown action: $action\n");
    exit(2);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'action' => $action,
        'class' => $e::class,
        'message' => $e->getMessage(),
    ]).PHP_EOL;
    exit(1);
}
