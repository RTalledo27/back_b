<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Support;

/**
 * Validated, request-derived context used by IdempotentCommandExecutor.
 * Built by the Controller — never by Actions — so the application layer
 * stays free of HTTP concerns.
 */
final readonly class IdempotencyContext
{
    public function __construct(
        public int $userId,
        public string $method,
        public string $path,
        public string $key,
        public string $payloadSha256,
    ) {}

    /**
     * Factory that hashes the supplied canonical payload components.
     *
     * Caller is responsible for normalising the components (sorting lists,
     * stripping client-controlled fields, etc.) before passing them.
     *
     * @param  array<string, mixed>  $payloadComponents
     */
    public static function make(
        int $userId,
        string $method,
        string $path,
        string $key,
        array $payloadComponents,
    ): self {
        $json = json_encode(
            $payloadComponents,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return new self(
            userId: $userId,
            method: mb_strtoupper($method),
            path: $path,
            key: $key,
            payloadSha256: hash('sha256', $json),
        );
    }
}
