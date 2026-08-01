<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Player;

use App\Modules\RepeatNumberBingo\Application\Queries\GetPlayerWinnerClaimQuery;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Player\PlayerWinnerClaimResource;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ShowMyWinnerClaimController
{
    public function __invoke(
        Request $request,
        GameWinner $winner,
        GetPlayerWinnerClaimQuery $query,
    ): PlayerWinnerClaimResource {
        $claim = $query->findForUser((string) $winner->getKey(), (int) $request->user()?->getKey());

        if ($claim === null) {
            throw new NotFoundHttpException('winner_not_found');
        }

        return new PlayerWinnerClaimResource($claim);
    }
}
