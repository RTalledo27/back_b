<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\GameLifecycle;

use App\Modules\RepeatNumberBingo\Application\Contracts\GameStartReadinessChecker;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameNotReadyForStart;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\GameStartReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Concrete implementation of the engine's readiness port. Reads only —
 * never mutates any Commerce or RNB row. The dependency direction is
 * Commerce -> RNB (Commerce implements the interface RNB defined); the
 * engine itself does not know about Order, Payment or NumberReservation.
 *
 * Contract requirements (enforced at runtime):
 *  - the caller must have opened a DB transaction;
 *  - the caller must have already locked games(id) FOR UPDATE.
 *
 * Behaviour:
 *  - every check below is evaluated and its reason is accumulated;
 *  - all collected reasons are reported together in a single exception;
 *  - returning `GameStartReadiness` means EVERY check passed.
 */
final class CommerceGameStartReadinessChecker implements GameStartReadinessChecker
{
    /**
     * @throws GameNotReadyForStart
     */
    public function assertReadyForStart(string $gameId): GameStartReadiness
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'CommerceGameStartReadinessChecker::assertReadyForStart requires an active database transaction.'
            );
        }

        $reasons = [];

        if ($this->existsOrder($gameId, 'pending')) {
            $reasons[] = 'has_pending_orders';
        }
        if ($this->existsOrder($gameId, 'payment_submitted')) {
            $reasons[] = 'has_payment_submitted_orders';
        }
        if ($this->existsPayment($gameId, 'pending')) {
            $reasons[] = 'has_pending_payments';
        }
        if ($this->existsPayment($gameId, 'under_review')) {
            $reasons[] = 'has_under_review_payments';
        }
        if ($this->existsActiveReservation($gameId)) {
            $reasons[] = 'has_active_reservations';
        }
        if ($this->existsReservedGameNumber($gameId)) {
            $reasons[] = 'has_reserved_numbers';
        }

        $confirmedEntries = (int) DB::table('game_entries')
            ->where('game_id', $gameId)
            ->where('status', 'confirmed')
            ->count();

        if ($confirmedEntries === 0) {
            $reasons[] = 'no_confirmed_entries';
        }

        if ($reasons !== []) {
            throw GameNotReadyForStart::withReasons($reasons);
        }

        return new GameStartReadiness(
            confirmedEntriesCount: $confirmedEntries,
            verifiedAt: CarbonImmutable::now(),
        );
    }

    private function existsOrder(string $gameId, string $status): bool
    {
        return DB::table('orders')
            ->where('game_id', $gameId)
            ->where('status', $status)
            ->exists();
    }

    private function existsPayment(string $gameId, string $status): bool
    {
        return DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('orders.game_id', $gameId)
            ->where('payments.status', $status)
            ->exists();
    }

    private function existsActiveReservation(string $gameId): bool
    {
        return DB::table('number_reservations')
            ->join('orders', 'orders.id', '=', 'number_reservations.order_id')
            ->where('orders.game_id', $gameId)
            ->exists();
    }

    private function existsReservedGameNumber(string $gameId): bool
    {
        return DB::table('game_numbers')
            ->where('game_id', $gameId)
            ->where('status', 'reserved')
            ->exists();
    }
}
