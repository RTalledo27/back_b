<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Jobs;

use App\Modules\RepeatNumberBingo\Application\Actions\DispatchDueGameDrawsAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Thin queueable wrapper around DispatchDueGameDrawsAction.
 *
 * ShouldBeUnique prevents two queued copies from running simultaneously
 * (uniqueFor = 59 s covers the maximum poll interval).
 * The scheduler also uses withoutOverlapping(1 min) as an extra layer.
 *
 * Dispatches one ExecuteScheduledGameDrawJob per selected EngineTick.
 */
final class DispatchDueGameDrawsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 59;

    /**
     * Valid poll intervals — the set exposed by Laravel's public sub-minute
     * scheduling API (everySecond, everyTwoSeconds, …, everyThirtySeconds).
     *
     * @var list<int>
     */
    public const VALID_POLL_SECONDS = [1, 2, 5, 10, 15, 20, 30];

    /**
     * Validates that the configured poll cadence maps to a method in Laravel's
     * public sub-minute scheduling API. Called eagerly from routes/console.php
     * so a bad deployment fails fast before the first scheduler pass.
     *
     * @throws InvalidArgumentException when $seconds is not in VALID_POLL_SECONDS.
     */
    public static function validatePollSeconds(int $seconds): void
    {
        if (! in_array($seconds, self::VALID_POLL_SECONDS, true)) {
            throw new InvalidArgumentException(
                'engine.dispatch_poll_seconds must be one of ['.implode(', ', self::VALID_POLL_SECONDS)."], got {$seconds}."
            );
        }
    }

    public function uniqueId(): string
    {
        return 'engine:dispatch-due-draws';
    }

    public function handle(DispatchDueGameDrawsAction $action): void
    {
        $result = $action->execute();

        Log::info('engine.dispatch_due_draws.completed', [
            'candidates_found' => $result->candidatesFound,
            'ticks_selected' => count($result->ticks),
        ]);

        foreach ($result->ticks as $tick) {
            ExecuteScheduledGameDrawJob::dispatch($tick);
        }
    }
}
