<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Admin;

use App\Modules\Commerce\Application\Queries\GetWinnerPayoutQuery;
use App\Modules\Commerce\Domain\Models\WinnerPayout;
use App\Modules\Commerce\Presentation\Http\Resources\Admin\AdminWinnerPayoutSensitiveResource;
use Illuminate\Support\Facades\Gate;

final class ShowAdminWinnerPayoutController
{
    public function __invoke(WinnerPayout $payout, GetWinnerPayoutQuery $query): AdminWinnerPayoutSensitiveResource
    {
        Gate::authorize('view', $payout);

        return new AdminWinnerPayoutSensitiveResource($query->execute((string) $payout->getKey()));
    }
}
