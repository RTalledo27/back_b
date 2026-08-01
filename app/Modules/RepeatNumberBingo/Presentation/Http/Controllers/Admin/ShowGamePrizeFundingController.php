<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Controllers\Admin;

use App\Modules\RepeatNumberBingo\Application\Queries\GetGamePrizeFundingQuery;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin\AdminGamePrizeFundingResource;
use Illuminate\Support\Facades\Gate;

final class ShowGamePrizeFundingController
{
    public function __invoke(
        Game $game,
        GetGamePrizeFundingQuery $query,
    ): AdminGamePrizeFundingResource {
        Gate::authorize('viewPrizeFunding', $game);

        return new AdminGamePrizeFundingResource(
            $query->execute((string) $game->getKey()),
        );
    }
}
