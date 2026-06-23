<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\ValueObjects;

use App\Modules\RepeatNumberBingo\Domain\Enums\ActorType;
use InvalidArgumentException;

/**
 * Identifies who triggered a game engine action.
 *
 * Admin actors carry the admin user's integer ID; system actors are
 * anonymous (userId = null) and represent the automated scheduler.
 */
final readonly class GameActionActor
{
    public function __construct(
        public ActorType $type,
        public ?int $userId,
    ) {}

    public static function admin(int $userId): self
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException(
                "Admin user ID must be a positive integer, got {$userId}."
            );
        }

        return new self(ActorType::Admin, $userId);
    }

    public static function system(): self
    {
        return new self(ActorType::System, null);
    }

    public function isSystem(): bool
    {
        return $this->type === ActorType::System;
    }

    public function isAdmin(): bool
    {
        return $this->type === ActorType::Admin;
    }
}
