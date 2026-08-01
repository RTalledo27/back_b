<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin;

use App\Modules\RepeatNumberBingo\Application\Queries\GetAdminWinnerClaimQuery;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin\AdminWinnerClaimSensitiveResource;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ShowWinnerClaimController
{
    public function __invoke(
        string $claim,
        GetAdminWinnerClaimQuery $query,
    ): AdminWinnerClaimSensitiveResource {
        $winnerClaim = $query->find($claim);

        if ($winnerClaim === null) {
            throw new NotFoundHttpException('winner_claim_not_found');
        }

        return new AdminWinnerClaimSensitiveResource($winnerClaim);
    }
}
