<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Public;

use App\Modules\RepeatNumberBingo\Application\Queries\GetPublicGameDetailQuery;
use App\Modules\RepeatNumberBingo\Application\Queries\ListGameNumberCountersQuery;
use App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Public\ListPublicGameNumberCountersRequest;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Public\PublicGameNumberCounterResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ListPublicGameNumberCountersController
{
    public function __invoke(
        ListPublicGameNumberCountersRequest $request,
        string $slug,
        GetPublicGameDetailQuery $gameQuery,
        ListGameNumberCountersQuery $countersQuery,
    ): AnonymousResourceCollection {
        $game = $gameQuery->findVisibleBySlug($slug);

        if ($game === null) {
            throw new NotFoundHttpException('Game not found.');
        }

        return PublicGameNumberCounterResource::collection(
            $countersQuery->paginate($game->getKey(), [], (int) $request->query('per_page', '50')),
        );
    }
}
