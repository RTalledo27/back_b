<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new UnauthorizedHttpException('Bearer', 'Authentication required.');
        }

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Debes verificar tu correo antes de realizar esta acción.',
                'code' => 'email_not_verified',
            ], 403);
        }

        return $next($request);
    }
}
