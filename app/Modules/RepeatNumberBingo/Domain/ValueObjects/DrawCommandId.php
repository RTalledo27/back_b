<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\ValueObjects;

use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidDrawCommandId;
use Stringable;

/**
 * Value Object protecting the engine's idempotency token. Independent of
 * HTTP because Phase 4 Jobs will create it directly. Accepts any RFC 4122
 * UUID (we expect v7 in practice but do not enforce the version — that
 * would couple this VO to the producer's clock choice).
 */
final readonly class DrawCommandId implements Stringable
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public string $value;

    public function __construct(string $value)
    {
        $normalized = strtolower(trim($value));

        if (preg_match(self::PATTERN, $normalized) !== 1) {
            throw InvalidDrawCommandId::notAUuid($value);
        }

        $this->value = $normalized;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
