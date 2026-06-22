<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin;

use App\Modules\RepeatNumberBingo\Application\Queries\ListGameNumberCountersQuery;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Admin\ListGameCountersRequest;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin\AdminGameCounterResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListGameCountersController
{
    public function __invoke(
        ListGameCountersRequest $request,
        Game $game,
        ListGameNumberCountersQuery $query,
    ): AnonymousResourceCollection {
        $perPage = (int) $request->query('per_page', '50');
        $filters = $request->only(['number_from', 'number_to', 'min_hits', 'max_hits', 'status']);

        return AdminGameCounterResource::collection(
            $query->paginate($game->getKey(), $filters, $perPage),
        );
    }
}
