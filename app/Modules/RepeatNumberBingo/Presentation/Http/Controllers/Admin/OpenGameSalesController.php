<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin;

use App\Modules\RepeatNumberBingo\Application\Actions\OpenGameSalesAction;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\AdminGameResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class OpenGameSalesController
{
    public function __invoke(
        Request $request,
        Game $game,
        OpenGameSalesAction $action,
    ): AdminGameResource {
        Gate::authorize('openSales', $game);

        return new AdminGameResource(
            $action->execute($game->getKey(), $request->user()?->getKey()),
        );
    }
}
