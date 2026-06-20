<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin;

use App\Modules\RepeatNumberBingo\Application\Actions\PublishGameAction;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\AdminGameResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class PublishGameController
{
    public function __invoke(
        Request $request,
        Game $game,
        PublishGameAction $action,
    ): AdminGameResource {
        Gate::authorize('publish', $game);

        return new AdminGameResource(
            $action->execute($game->getKey(), $request->user()?->getKey()),
        );
    }
}
