<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Auth\CreatePlayerInvitationAction;
use App\Enums\CreatePlayerOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreatePlayerRequest;
use App\Http\Resources\Admin\PlayerInvitationResource;
use Illuminate\Http\JsonResponse;

final class CreatePlayerController extends Controller
{
    public function __invoke(CreatePlayerRequest $request, CreatePlayerInvitationAction $action): JsonResponse
    {
        $result = $action->execute($request->toDto());

        $statusCode = $result->outcome === CreatePlayerOutcome::AlreadyRegistered ? 200 : 201;

        return (new PlayerInvitationResource($result))
            ->response()
            ->setStatusCode($statusCode);
    }
}
