<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;
use BackedEnum;

final class InvalidWinnerPayoutTransition extends DomainException
{
    public function __construct(
        BackedEnum $from,
        BackedEnum $to,
    ) {
        parent::__construct("Winner payout cannot transition from '{$from->value}' to '{$to->value}'.");
    }
}
