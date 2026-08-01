<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Player;

use App\Modules\RepeatNumberBingo\Application\Queries\ListPlayerWinnerClaimsQuery;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Player\PlayerWinnerClaimResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListMyWinnerClaimsController
{
    public function __invoke(Request $request, ListPlayerWinnerClaimsQuery $query): AnonymousResourceCollection
    {
        return PlayerWinnerClaimResource::collection(
            $query->paginate(
                (int) $request->user()?->getKey(),
                (int) $request->integer('per_page', 15),
            ),
        );
    }
}
