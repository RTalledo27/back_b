<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Jobs;

use App\Modules\Commerce\Application\Actions\ExpireWinnerPayoutReceiptAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ExpireWinnerPayoutReceiptsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 55;

    public function handle(ExpireWinnerPayoutReceiptAction $action): void
    {
        $action->executeBatch();
    }
}
