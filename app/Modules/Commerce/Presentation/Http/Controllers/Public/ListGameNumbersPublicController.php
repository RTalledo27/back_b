<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Public;

use App\Modules\Commerce\Application\Queries\ListGameNumbersPublicQuery;
use App\Modules\Commerce\Presentation\Http\Resources\Public\PublicGameNumberResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ListGameNumbersPublicController
{
    public function __invoke(string $slug, ListGameNumbersPublicQuery $query): AnonymousResourceCollection
    {
        $numbers = $query->forGameSlug($slug);

        if ($numbers === null) {
            throw new NotFoundHttpException('Game not found.');
        }

        return PublicGameNumberResource::collection($numbers);
    }
}
