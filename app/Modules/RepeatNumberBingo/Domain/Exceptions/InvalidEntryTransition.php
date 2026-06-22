<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Exceptions;

use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\Shared\Domain\Exceptions\DomainException;

final class InvalidEntryTransition extends DomainException
{
    public static function from(EntryStatus $current, EntryStatus $next): self
    {
        return new self(
            "Cannot transition game entry from {$current->value} to {$next->value}."
        );
    }

    public static function uncontrolledStatusChange(): self
    {
        return new self(
            'GameEntry status must be changed via GameEntry::transitionTo().'
        );
    }
}
