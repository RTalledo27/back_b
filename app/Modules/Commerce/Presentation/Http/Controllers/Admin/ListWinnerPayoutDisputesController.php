<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Admin;

use App\Modules\Commerce\Application\Queries\ListWinnerPayoutDisputesQuery;
use App\Modules\Commerce\Domain\Models\WinnerPayoutDispute;
use App\Modules\Commerce\Presentation\Http\Requests\Admin\ListWinnerPayoutDisputesRequest;
use App\Modules\Commerce\Presentation\Http\Resources\Admin\AdminWinnerPayoutDisputeResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class ListWinnerPayoutDisputesController
{
    public function __invoke(ListWinnerPayoutDisputesRequest $request, ListWinnerPayoutDisputesQuery $query): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', WinnerPayoutDispute::class);

        return AdminWinnerPayoutDisputeResource::collection($query->execute($request->validated()));
    }
}
