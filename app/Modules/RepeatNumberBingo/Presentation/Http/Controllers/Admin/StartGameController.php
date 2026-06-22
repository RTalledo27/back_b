<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin;

use App\Modules\RepeatNumberBingo\Application\Actions\StartGameAction;
use App\Modules\RepeatNumberBingo\Application\DTOs\StartGameData;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Admin\StartGameRequest;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin\AdminStartGameResource;
use Symfony\Component\HttpFoundation\Response;

final class StartGameController
{
    public function __invoke(
        StartGameRequest $request,
        Game $game,
        StartGameAction $action,
    ): Response {
        $result = $action->execute(new StartGameData(
            gameId: $game->getKey(),
            actorUserId: (int) $request->user()?->getKey(),
        ));

        return (new AdminStartGameResource($result))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
