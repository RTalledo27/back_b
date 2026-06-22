<?php

declare(strict_types=1);

use App\Modules\Commerce\Application\Jobs\ExpirePendingOrdersJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Commerce — pending order expiration
|--------------------------------------------------------------------------
|
| Two TTLs, two layers, two different units:
|
|   - Schedule::...->withoutOverlapping(2)
|       Scheduler-level mutex. Unit: MINUTES. Prevents two `schedule:run`
|       passes from dispatching the same Job within the next 2 minutes.
|
|   - ExpirePendingOrdersJob::$uniqueFor = 60
|       Queue-level uniqueness (ShouldBeUnique). Unit: SECONDS. Prevents
|       two queued copies of the Job from running simultaneously.
|
| Distributed uniqueness depends on the cache driver, not the queue
| driver. In production:
|
|   QUEUE_CONNECTION=redis   # where the Job executes
|   CACHE_STORE=redis        # where the ShouldBeUnique lock and the
|                            # scheduler mutex are stored
|
| Both Redis-backed components must point at the SAME shared Redis
| instance for the locks to be correctly visible to every worker /
| scheduler host. If a future deployment scales out to multiple
| `schedule:run` boxes, evaluate `->onOneServer()` (requires the shared
| cache to already be in place). Not needed for single-server deployments.
|
| Tests intentionally keep QUEUE_CONNECTION=sync and CACHE_STORE=array
| (see phpunit.xml) so the standard suite never depends on a live Redis.
| Redis-specific behaviour belongs in a separate optional suite.
|
*/
Schedule::job(ExpirePendingOrdersJob::class)
    ->everyMinute()
    ->withoutOverlapping(2);
