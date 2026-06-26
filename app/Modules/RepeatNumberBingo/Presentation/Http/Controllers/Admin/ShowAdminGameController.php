<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin;

use App\Modules\RepeatNumberBingo\Application\Queries\GetAdminGameDetailQuery;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Admin\ShowAdminGameRequest;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin\AdminGameDetailResource;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ShowAdminGameController
{
    public function __invoke(
        ShowAdminGameRequest $request,
        Game $game,
        GetAdminGameDetailQuery $query,
    ): AdminGameDetailResource {
        $detail = $query->byId($game->getKey());

        if ($detail === null) {
            throw new NotFoundHttpException('game_not_found');
        }

        return new AdminGameDetailResource($detail);
    }
}
