<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Admin;

use App\Modules\Commerce\Application\Queries\GetWinnerPayoutDisputeQuery;
use App\Modules\Commerce\Domain\Models\WinnerPayoutDispute;
use App\Modules\Commerce\Presentation\Http\Resources\Admin\AdminWinnerPayoutDisputeSensitiveResource;
use Illuminate\Support\Facades\Gate;

final class ShowWinnerPayoutDisputeController
{
    public function __invoke(WinnerPayoutDispute $dispute, GetWinnerPayoutDisputeQuery $query): AdminWinnerPayoutDisputeSensitiveResource
    {
        Gate::authorize('view', $dispute);

        return new AdminWinnerPayoutDisputeSensitiveResource($query->execute((string) $dispute->id));
    }
}
