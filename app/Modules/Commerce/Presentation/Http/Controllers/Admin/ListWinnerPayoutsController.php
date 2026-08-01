<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Admin;

use App\Modules\Commerce\Application\Queries\ListWinnerPayoutsQuery;
use App\Modules\Commerce\Domain\Models\WinnerPayout;
use App\Modules\Commerce\Presentation\Http\Requests\Admin\ListWinnerPayoutsRequest;
use App\Modules\Commerce\Presentation\Http\Resources\Admin\AdminWinnerPayoutResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class ListWinnerPayoutsController
{
    public function __invoke(ListWinnerPayoutsRequest $request, ListWinnerPayoutsQuery $query): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', WinnerPayout::class);

        return AdminWinnerPayoutResource::collection($query->execute($request->validated()));
    }
}
