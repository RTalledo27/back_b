<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Auth\AuthUserResource;
use App\Models\User;
use Illuminate\Http\Request;

final class MeController extends Controller
{
    public function __invoke(Request $request): AuthUserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new AuthUserResource($user);
    }
}
