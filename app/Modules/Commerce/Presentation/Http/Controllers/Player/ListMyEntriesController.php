<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Player;

use App\Modules\Commerce\Application\Queries\ListMyEntriesQuery;
use App\Modules\Commerce\Presentation\Http\Resources\Player\PlayerEntryResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListMyEntriesController
{
    public function __invoke(Request $request, ListMyEntriesQuery $query): AnonymousResourceCollection
    {
        $gameId = $request->query('game_id');
        $gameId = is_string($gameId) ? $gameId : null;

        return PlayerEntryResource::collection(
            $query->paginate((int) $request->user()?->getKey(), $gameId),
        );
    }
}
