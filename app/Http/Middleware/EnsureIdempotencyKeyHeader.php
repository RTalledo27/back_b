<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Validates the Idempotency-Key header before the request reaches the
 * Controller. Does NOT claim the key — that happens after Form Request
 * validation, inside the Controller via IdempotentCommandExecutor, so
 * 401/403/422 responses never leave abandoned rows in idempotency_keys.
 */
final class EnsureIdempotencyKeyHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || $key === '') {
            throw new BadRequestHttpException('Missing required header: Idempotency-Key.');
        }

        $min = (int) config('commerce.idempotency.key.min_length', 16);
        $max = (int) config('commerce.idempotency.key.max_length', 80);
        $pattern = (string) config('commerce.idempotency.key.allowed_pattern', '/^[A-Za-z0-9_\-]+$/');

        $length = mb_strlen($key);

        if ($length < $min || $length > $max) {
            throw new BadRequestHttpException(
                "Idempotency-Key must be between {$min} and {$max} characters."
            );
        }

        if (preg_match($pattern, $key) !== 1) {
            throw new BadRequestHttpException(
                'Idempotency-Key contains invalid characters. Allowed: A-Z, a-z, 0-9, _ and -.'
            );
        }

        return $next($request);
    }
}
