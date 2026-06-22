<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Exceptions;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Raised by ApprovePaymentAction when the game is no longer accepting
 * payment confirmations (anything other than sales_open / sales_closed).
 *
 * The shape is intentionally narrow — game id, current state and the
 * states that would have been valid. No buyer info, no financial data,
 * no internal paths, no evidence references.
 */
final class GameNotAcceptingPayments extends DomainException
{
    public readonly string $gameId;

    public readonly string $currentStatus;

    /**
     * @var list<string>
     */
    public readonly array $allowedStatuses;

    /**
     * @param  list<GameStatus>  $allowedStatuses
     */
    public function __construct(string $gameId, GameStatus $currentStatus, array $allowedStatuses)
    {
        $this->gameId = $gameId;
        $this->currentStatus = $currentStatus->value;
        $this->allowedStatuses = array_values(array_map(static fn (GameStatus $s) => $s->value, $allowedStatuses));

        parent::__construct(sprintf(
            'Payment cannot be approved: game is in status "%s"; approval is only allowed in [%s].',
            $this->currentStatus,
            implode(', ', $this->allowedStatuses),
        ));
    }
}
