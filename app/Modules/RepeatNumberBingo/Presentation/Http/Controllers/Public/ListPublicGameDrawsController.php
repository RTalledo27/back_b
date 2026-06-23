<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Public;

use App\Modules\RepeatNumberBingo\Application\Queries\GetPublicGameDetailQuery;
use App\Modules\RepeatNumberBingo\Application\Queries\ListGameDrawsQuery;
use App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Public\ListPublicGameDrawsRequest;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Public\PublicGameDrawResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ListPublicGameDrawsController
{
    public function __invoke(
        ListPublicGameDrawsRequest $request,
        string $slug,
        GetPublicGameDetailQuery $gameQuery,
        ListGameDrawsQuery $drawsQuery,
    ): AnonymousResourceCollection {
        $game = $gameQuery->findVisibleBySlug($slug);

        if ($game === null) {
            throw new NotFoundHttpException('Game not found.');
        }

        return PublicGameDrawResource::collection(
            $drawsQuery->paginate($game->getKey(), [], (int) $request->query('per_page', '50')),
        );
    }
}
