<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Jobs;

use App\Modules\Commerce\Application\Actions\ExpirePendingOrdersAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Thin queueable wrapper around ExpirePendingOrdersAction. Contains zero
 * business logic — only DI + uniqueness configuration.
 *
 * Unit notes (different mechanisms, different scales — easy to confuse):
 *   - $uniqueFor             : SECONDS — Laravel ShouldBeUnique lock TTL.
 *     The job is treated as unique for this many seconds; a second
 *     dispatch with the same uniqueId is dropped.
 *   - Schedule::...->withoutOverlapping(N) : MINUTES — scheduler-level
 *     mutex TTL for the periodic invocation. See routes/console.php.
 */
final class ExpirePendingOrdersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 60;

    public function uniqueId(): string
    {
        return 'commerce:expire-pending-orders';
    }

    public function handle(ExpirePendingOrdersAction $action): void
    {
        $action->execute();
    }
}
