<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Jobs;

use App\Modules\Shared\Infrastructure\Outbox\OutboxEventProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Thin queueable wrapper around OutboxEventProcessor.
 *
 * The job itself has tries = 1: it does not rely on Laravel's job retry
 * mechanism.  Retry logic lives inside the outbox table (attempts,
 * next_attempt_at, max_attempts) so that each polling cycle can pick up
 * whichever events are ready, regardless of previous runs.
 *
 * Scheduled every minute via routes/console.php; withoutOverlapping(2)
 * prevents two simultaneous invocations at the scheduler level.  Multiple
 * workers could still run the same job concurrently from the queue, but
 * FOR UPDATE SKIP LOCKED + locked_at ensure each event is processed by
 * exactly one worker at a time.
 */
final class ProcessOutboxEventsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 55;

    public function handle(OutboxEventProcessor $processor): void
    {
        $processor->processBatch(batchSize: 50);
    }
}
