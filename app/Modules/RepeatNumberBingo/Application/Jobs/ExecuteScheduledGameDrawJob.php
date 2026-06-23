<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Jobs;

use App\Modules\RepeatNumberBingo\Application\Actions\AutoPauseGameAfterIntegrityFailureAction;
use App\Modules\RepeatNumberBingo\Application\Actions\ExecuteScheduledGameDrawAction;
use App\Modules\RepeatNumberBingo\Application\DTOs\ScheduledGameDrawFailureType;
use App\Modules\RepeatNumberBingo\Application\Services\ScheduledGameDrawFailureClassifier;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\EngineTick;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ExecuteScheduledGameDrawJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    public int $timeout = 60;

    public int $uniqueFor = 120;

    public function __construct(public readonly EngineTick $tick) {}

    public function uniqueId(): string
    {
        return $this->tick->commandId->toString();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [1, 5, 10];
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('engine:game:'.$this->tick->gameId))
                ->releaseAfter(2)
                ->expireAfter(75),
        ];
    }

    public function handle(
        ExecuteScheduledGameDrawAction $action,
        ScheduledGameDrawFailureClassifier $classifier,
        AutoPauseGameAfterIntegrityFailureAction $autoPause,
    ): void {
        $startedAt = microtime(true);
        $context = $this->telemetryContext();

        Log::info('engine.scheduled_draw.started', $context);

        try {
            $result = $action->execute($this->tick);

            Log::info('engine.scheduled_draw.completed', [
                ...$context,
                'outcome' => $result->outcome->value,
                'skipped_ticks' => $result->skippedTicks,
                'next_draw_at' => $result->nextDrawAt?->toIso8601String(),
                'duration_ms' => $this->durationMilliseconds($startedAt),
            ]);
        } catch (Throwable $exception) {
            $failureType = $classifier->classify($exception);
            $failureContext = [
                ...$context,
                'failure_type' => $failureType->value,
                'failure_code' => $classifier->code($exception),
                'exception_class' => $exception::class,
                'sqlstate' => $classifier->sqlState($exception),
                'duration_ms' => $this->durationMilliseconds($startedAt),
            ];

            if ($failureType === ScheduledGameDrawFailureType::Expected) {
                Log::info('engine.scheduled_draw.expected_failure', $failureContext);

                return;
            }

            if ($failureType === ScheduledGameDrawFailureType::Transient) {
                Log::warning('engine.scheduled_draw.transient_failure', $failureContext);

                throw $exception;
            }

            report($exception);

            try {
                $pauseOutcome = $autoPause->execute(
                    $this->tick,
                    $exception,
                    $classifier->code($exception),
                );
            } catch (Throwable $pauseException) {
                report($pauseException);

                Log::error('engine.scheduled_draw.auto_pause_failed', [
                    ...$failureContext,
                    'pause_exception_class' => $pauseException::class,
                ]);

                throw $pauseException;
            }

            Log::error('engine.scheduled_draw.integrity_failure', [
                ...$failureContext,
                'auto_pause_outcome' => $pauseOutcome->value,
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('engine.scheduled_draw.failed', [
            ...$this->telemetryContext(),
            'exception_class' => $exception !== null ? $exception::class : null,
        ]);
    }

    /**
     * @return array<string, int|string>
     */
    private function telemetryContext(): array
    {
        return [
            'game_id' => $this->tick->gameId,
            'command_id' => $this->tick->commandId->toString(),
            'scheduled_at' => $this->tick->scheduledAt->toIso8601String(),
            'attempt' => $this->attempts(),
        ];
    }

    private function durationMilliseconds(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
