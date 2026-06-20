<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin;

use App\Modules\RepeatNumberBingo\Application\Actions\SetScheduledStartAtAction;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Admin\ScheduleGameRequest;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\AdminGameResource;
use Illuminate\Support\Facades\Gate;

final class ScheduleGameController
{
    public function __invoke(
        ScheduleGameRequest $request,
        Game $game,
        SetScheduledStartAtAction $action,
    ): AdminGameResource {
        Gate::authorize('schedule', $game);

        return new AdminGameResource(
            $action->execute(
                $game->getKey(),
                $request->scheduledStartAt(),
                $request->user()?->getKey(),
            ),
        );
    }
}
