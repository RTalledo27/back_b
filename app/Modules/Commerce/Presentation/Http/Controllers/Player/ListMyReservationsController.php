<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Player;

use App\Modules\Commerce\Application\Queries\ListMyReservationsQuery;
use App\Modules\Commerce\Presentation\Http\Resources\Player\PlayerReservationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListMyReservationsController
{
    public function __invoke(Request $request, ListMyReservationsQuery $query): AnonymousResourceCollection
    {
        return PlayerReservationResource::collection(
            $query->paginate((int) $request->user()?->getKey()),
        );
    }
}
