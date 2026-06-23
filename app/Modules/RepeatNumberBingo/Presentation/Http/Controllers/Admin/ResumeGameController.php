<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin;

use App\Modules\RepeatNumberBingo\Application\Actions\ResumeGameAction;
use App\Modules\RepeatNumberBingo\Application\DTOs\ResumeGameData;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\GameActionActor;
use App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Admin\ResumeGameRequest;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin\AdminResumeGameResource;
use Symfony\Component\HttpFoundation\Response;

final class ResumeGameController
{
    public function __invoke(
        ResumeGameRequest $request,
        Game $game,
        ResumeGameAction $action,
    ): Response {
        $result = $action->execute(new ResumeGameData(
            gameId: $game->getKey(),
            actor: GameActionActor::admin((int) $request->user()?->getKey()),
        ));

        return (new AdminResumeGameResource($result))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
