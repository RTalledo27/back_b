<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin;

use App\Modules\RepeatNumberBingo\Application\Actions\DrawGameNumberAction;
use App\Modules\RepeatNumberBingo\Application\DTOs\DrawGameNumberData;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Admin\DrawGameNumberRequest;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin\AdminDrawGameNumberResource;
use Symfony\Component\HttpFoundation\Response;

final class DrawGameNumberController
{
    public function __invoke(
        DrawGameNumberRequest $request,
        Game $game,
        DrawGameNumberAction $action,
    ): Response {
        $result = $action->execute(new DrawGameNumberData(
            gameId: $game->getKey(),
            commandId: $request->drawCommandId(),
            actorUserId: (int) $request->user()?->getKey(),
        ));

        return (new AdminDrawGameNumberResource($result))
            ->response()
            ->setStatusCode($result->wasReplay ? Response::HTTP_OK : Response::HTTP_CREATED);
    }
}
