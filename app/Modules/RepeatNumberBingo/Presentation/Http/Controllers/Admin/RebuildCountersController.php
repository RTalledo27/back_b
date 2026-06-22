<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin;

use App\Modules\RepeatNumberBingo\Application\Actions\RebuildGameNumberCountersAction;
use App\Modules\RepeatNumberBingo\Application\DTOs\RebuildCountersData;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Admin\RebuildCountersRequest;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin\AdminRebuildCountersResource;
use Symfony\Component\HttpFoundation\Response;

final class RebuildCountersController
{
    public function __invoke(
        RebuildCountersRequest $request,
        Game $game,
        RebuildGameNumberCountersAction $action,
    ): Response {
        $result = $action->execute(new RebuildCountersData(
            gameId: $game->getKey(),
            actorUserId: (int) $request->user()?->getKey(),
        ));

        return (new AdminRebuildCountersResource($result))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
