<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePaymentGatewayHttpEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('payment_gateways.http_enabled', false)) {
            return response()->json(['message' => 'Not Found.'], Response::HTTP_NOT_FOUND);
        }

        return $next($request);
    }
}
