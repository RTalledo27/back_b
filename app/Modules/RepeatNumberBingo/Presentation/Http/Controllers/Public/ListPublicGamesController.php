<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Public;

use App\Modules\RepeatNumberBingo\Application\Queries\ListPublicGamesQuery;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\PublicGameResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListPublicGamesController
{
    public function __invoke(ListPublicGamesQuery $query): AnonymousResourceCollection
    {
        return PublicGameResource::collection($query->paginate(20));
    }
}
