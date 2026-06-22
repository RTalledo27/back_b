<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

/**
 * Marker contract for the value returned by an idempotent Commerce Action.
 *
 * Implementations must be serializable to a flat associative array and
 * reconstructible via a static `fromArray()` factory (named at the call
 * site — see IdempotentCommandExecutor::execute()'s `hydrate` callback).
 */
interface CommandResult
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
