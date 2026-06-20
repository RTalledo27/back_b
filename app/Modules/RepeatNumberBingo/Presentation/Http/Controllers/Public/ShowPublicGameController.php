<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Public;

use App\Modules\RepeatNumberBingo\Application\Queries\GetPublicGameDetailQuery;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\PublicGameResource;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ShowPublicGameController
{
    public function __invoke(string $slug, GetPublicGameDetailQuery $query): PublicGameResource
    {
        $game = $query->bySlug($slug);

        if ($game === null) {
            throw new NotFoundHttpException('Game not found.');
        }

        return new PublicGameResource($game);
    }
}
