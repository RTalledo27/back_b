<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin;

use App\Modules\RepeatNumberBingo\Application\Queries\GetGameWinnerQuery;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Admin\ShowGameWinnerRequest;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin\AdminGameWinnerResource;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ShowGameWinnerController
{
    public function __invoke(
        ShowGameWinnerRequest $request,
        Game $game,
        GetGameWinnerQuery $query,
    ): AdminGameWinnerResource {
        $winner = $query->findForGame($game->getKey());

        if ($winner === null) {
            throw new NotFoundHttpException('game_winner_not_found');
        }

        return new AdminGameWinnerResource($winner);
    }
}
