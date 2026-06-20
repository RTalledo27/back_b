<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin;

use App\Modules\RepeatNumberBingo\Application\Actions\CreateGameAction;
use App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Admin\CreateGameRequest;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\AdminGameResource;

final class CreateGameController
{
    public function __invoke(
        CreateGameRequest $request,
        CreateGameAction $action,
    ): AdminGameResource {
        $game = $action->execute($request->toDto());

        return (new AdminGameResource($game))->additional(['status' => 'created']);
    }
}
