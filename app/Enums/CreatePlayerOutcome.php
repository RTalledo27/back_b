<?php

declare(strict_types=1);

namespace App\Enums;

enum CreatePlayerOutcome: string
{
    case Invited = 'invited';
    case Reinvited = 'reinvited';
    case AlreadyRegistered = 'already_registered';
}
