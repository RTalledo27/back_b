<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin;

use App\Modules\RepeatNumberBingo\Application\Queries\ListAdminGamesQuery;
use App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Admin\ListAdminGamesRequest;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin\AdminGameSummaryResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListAdminGamesController
{
    public function __invoke(
        ListAdminGamesRequest $request,
        ListAdminGamesQuery $query,
    ): AnonymousResourceCollection {
        $perPage = (int) $request->query('per_page', '20');
        $filters = $request->only(['search', 'status', 'created_from', 'created_to']);

        if ($request->has('published')) {
            $filters['published'] = $request->boolean('published');
        }

        if ($request->has('auto_draw_enabled')) {
            $filters['auto_draw_enabled'] = $request->boolean('auto_draw_enabled');
        }

        return AdminGameSummaryResource::collection(
            $query->paginate($filters, $perPage),
        );
    }
}
