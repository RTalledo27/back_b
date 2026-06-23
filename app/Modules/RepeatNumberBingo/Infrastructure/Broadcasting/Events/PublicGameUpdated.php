<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Infrastructure\Broadcasting\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Throwable;

final class PublicGameUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly string $gameSlug,
        private readonly array $payload,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('games.'.$this->gameSlug);
    }

    public function broadcastAs(): string
    {
        return 'public.game.updated.v1';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }
    }
}
