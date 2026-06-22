<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin;

use App\Modules\RepeatNumberBingo\Application\Queries\ListGameDrawsQuery;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Admin\ListGameDrawsRequest;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin\AdminGameDrawResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListGameDrawsController
{
    public function __invoke(
        ListGameDrawsRequest $request,
        Game $game,
        ListGameDrawsQuery $query,
    ): AnonymousResourceCollection {
        $perPage = (int) $request->query('per_page', '50');
        $filters = $request->only(['number', 'sequence_from', 'sequence_to', 'drawn_from', 'drawn_to']);

        return AdminGameDrawResource::collection(
            $query->paginate($game->getKey(), $filters, $perPage),
        );
    }
}
