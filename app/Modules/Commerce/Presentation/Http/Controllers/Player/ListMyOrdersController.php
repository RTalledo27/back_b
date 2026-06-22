<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Player;

use App\Modules\Commerce\Application\Queries\ListMyOrdersQuery;
use App\Modules\Commerce\Presentation\Http\Resources\Player\PlayerOrderResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListMyOrdersController
{
    public function __invoke(Request $request, ListMyOrdersQuery $query): AnonymousResourceCollection
    {
        $status = $request->query('status');
        $status = is_string($status) ? $status : null;

        return PlayerOrderResource::collection(
            $query->paginate((int) $request->user()?->getKey(), $status),
        );
    }
}
