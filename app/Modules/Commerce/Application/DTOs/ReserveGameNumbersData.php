<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

use InvalidArgumentException;

/**
 * Validated input for ReserveGameNumbersAction.
 *
 * Intentionally minimal: price, currency, TTL, totals and expiration come
 * from the locked Game row and from config('commerce.reservation.*'). The
 * client never controls any of those values.
 */
final readonly class ReserveGameNumbersData
{
    /**
     * @param  list<string>  $gameNumberIds
     */
    public function __construct(
        public string $gameId,
        public int $userId,
        public array $gameNumberIds,
    ) {
        if ($gameNumberIds === []) {
            throw new InvalidArgumentException('gameNumberIds must contain at least one id.');
        }

        foreach ($gameNumberIds as $id) {
            if (! is_string($id) || $id === '') {
                throw new InvalidArgumentException('gameNumberIds must contain non-empty strings.');
            }
        }
    }
}
