<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Services;

use App\Modules\RepeatNumberBingo\Domain\ValueObjects\DrawCommandId;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * Produces a deterministic DrawCommandId for a scheduled engine tick.
 *
 * Algorithm: UUID v5(namespace, "$gameId:$scheduledAt->timestamp")
 *
 * The namespace is injected at construction time (from
 * config('engine.draw_command_namespace') via the IoC binding) so the
 * domain service carries no Laravel dependencies. It is validated once
 * in the constructor and then reused for every generate() call.
 */
final class EngineTickCommandIdGenerator
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(private readonly string $namespace)
    {
        if (preg_match(self::UUID_PATTERN, trim($namespace)) !== 1) {
            throw new InvalidArgumentException(
                "Invalid UUID namespace: '{$namespace}'."
            );
        }
    }

    public function generate(string $gameId, CarbonImmutable $scheduledAt): DrawCommandId
    {
        $name = $gameId.':'.$scheduledAt->timestamp;

        return new DrawCommandId(Uuid::uuid5($this->namespace, $name)->toString());
    }
}
