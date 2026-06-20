<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin;

use App\Modules\RepeatNumberBingo\Application\Actions\CancelGameAction;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Admin\CancelGameRequest;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\AdminGameResource;
use Illuminate\Support\Facades\Gate;

final class CancelGameController
{
    public function __invoke(
        CancelGameRequest $request,
        Game $game,
        CancelGameAction $action,
    ): AdminGameResource {
        Gate::authorize('cancel', $game);

        return new AdminGameResource(
            $action->execute(
                $game->getKey(),
                $request->input('reason'),
                $request->user()?->getKey(),
            ),
        );
    }
}
