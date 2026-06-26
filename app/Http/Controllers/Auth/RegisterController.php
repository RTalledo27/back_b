<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RegisterPlayerAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\Auth\AuthTokenResource;
use Illuminate\Http\JsonResponse;

final class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request, RegisterPlayerAction $register): JsonResponse
    {
        return (new AuthTokenResource($register->execute($request->toDto())))
            ->response()
            ->setStatusCode(201);
    }
}
