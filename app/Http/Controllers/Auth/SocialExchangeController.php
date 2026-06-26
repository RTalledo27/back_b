<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\CompleteSocialExchangeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SocialExchangeRequest;
use App\Http\Resources\Auth\AuthTokenResource;
use Illuminate\Http\JsonResponse;

final class SocialExchangeController extends Controller
{
    public function __invoke(SocialExchangeRequest $request, CompleteSocialExchangeAction $exchange): JsonResponse
    {
        return (new AuthTokenResource($exchange->execute($request->validated('code'))))
            ->response();
    }
}
