<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\LogoutCurrentTokenAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class LogoutController extends Controller
{
    public function __invoke(Request $request, LogoutCurrentTokenAction $logout): Response
    {
        /** @var User $user */
        $user = $request->user();

        $logout->execute($user, $request->bearerToken());

        return response()->noContent();
    }
}
