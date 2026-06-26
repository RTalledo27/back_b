<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ActivatePlayerAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ActivateRequest;
use App\Http\Resources\Auth\AuthTokenResource;
use Illuminate\Http\JsonResponse;

final class ActivateController extends Controller
{
    public function __invoke(ActivateRequest $request, ActivatePlayerAction $action): JsonResponse
    {
        return (new AuthTokenResource($action->execute($request->toDto())))
            ->response();
    }
}
