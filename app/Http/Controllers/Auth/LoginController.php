<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\AuthenticateUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\Auth\AuthTokenResource;
use Illuminate\Http\JsonResponse;

final class LoginController extends Controller
{
    public function __invoke(LoginRequest $request, AuthenticateUserAction $authenticate): JsonResponse
    {
        return (new AuthTokenResource($authenticate->execute($request->toDto())))
            ->response();
    }
}
