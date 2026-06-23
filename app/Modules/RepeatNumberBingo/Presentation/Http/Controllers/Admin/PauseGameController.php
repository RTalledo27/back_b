<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin;

use App\Modules\RepeatNumberBingo\Application\Actions\PauseGameAction;
use App\Modules\RepeatNumberBingo\Application\DTOs\PauseGameData;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\GameActionActor;
use App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Admin\PauseGameRequest;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin\AdminPauseGameResource;
use Symfony\Component\HttpFoundation\Response;

final class PauseGameController
{
    public function __invoke(
        PauseGameRequest $request,
        Game $game,
        PauseGameAction $action,
    ): Response {
        $result = $action->execute(new PauseGameData(
            gameId: $game->getKey(),
            actor: GameActionActor::admin((int) $request->user()?->getKey()),
        ));

        return (new AdminPauseGameResource($result))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
