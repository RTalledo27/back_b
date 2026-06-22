<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Admin;

use App\Modules\Commerce\Application\Queries\ListAdminOrdersQuery;
use App\Modules\Commerce\Presentation\Http\Resources\Admin\AdminOrderResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListAdminOrdersController
{
    public function __invoke(Request $request, ListAdminOrdersQuery $query): AnonymousResourceCollection
    {
        $status = $request->query('status');
        $status = is_string($status) ? $status : null;

        $gameId = $request->query('game_id');
        $gameId = is_string($gameId) ? $gameId : null;

        return AdminOrderResource::collection($query->paginate($status, $gameId));
    }
}
