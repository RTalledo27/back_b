<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin;

use App\Modules\RepeatNumberBingo\Application\Queries\ListAdminWinnerClaimsQuery;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin\AdminWinnerClaimResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListWinnerClaimsController
{
    public function __invoke(Request $request, ListAdminWinnerClaimsQuery $query): AnonymousResourceCollection
    {
        return AdminWinnerClaimResource::collection(
            $query->paginate(
                status: $request->string('status')->toString() ?: null,
                gameId: $request->string('game_id')->toString() ?: null,
                perPage: (int) $request->integer('per_page', 25),
            ),
        );
    }
}
