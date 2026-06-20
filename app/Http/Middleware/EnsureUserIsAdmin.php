<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new UnauthorizedHttpException('Bearer', 'Authentication required.');
        }

        if (! method_exists($user, 'isAdmin') || ! $user->isAdmin()) {
            throw new AccessDeniedHttpException('Administrator role required.');
        }

        return $next($request);
    }
}
