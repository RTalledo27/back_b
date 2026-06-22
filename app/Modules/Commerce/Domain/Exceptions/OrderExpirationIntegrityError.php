<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Thrown when the expiration action finds the order in an inconsistent
 * state under lock — items without matching reservations, reservations
 * not matching items, or game_numbers that aren't actually reserved.
 *
 * This is a "should never happen" guard that, if it does happen, forces
 * the whole expiration to rollback rather than partially releasing.
 */
final class OrderExpirationIntegrityError extends DomainException
{
    public static function reservationCountMismatch(string $orderId, int $items, int $reservations): self
    {
        return new self(
            "Order {$orderId} has {$items} items but {$reservations} reservations."
        );
    }

    /**
     * @param  list<string>  $missingGameNumberIds
     */
    public static function itemsAndReservationsDoNotMatch(string $orderId, array $missingGameNumberIds): self
    {
        $list = implode(', ', $missingGameNumberIds);

        return new self(
            "Order {$orderId} items and reservations refer to different game_numbers (offenders: {$list})."
        );
    }

    /**
     * @param  list<string>  $offendingIds
     */
    public static function numbersNotReserved(string $orderId, array $offendingIds): self
    {
        $list = implode(', ', $offendingIds);

        return new self(
            "Order {$orderId} expected reserved game_numbers but found other statuses: {$list}."
        );
    }
}
