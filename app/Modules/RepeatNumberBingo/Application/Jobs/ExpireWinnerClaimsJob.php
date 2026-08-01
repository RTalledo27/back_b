<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Jobs;

use App\Modules\RepeatNumberBingo\Application\Actions\ExpireWinnerClaimsAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ExpireWinnerClaimsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 60;

    public function uniqueId(): string
    {
        return 'repeat-number-bingo:expire-winner-claims';
    }

    public function handle(ExpireWinnerClaimsAction $action): void
    {
        $action->execute();
    }
}
