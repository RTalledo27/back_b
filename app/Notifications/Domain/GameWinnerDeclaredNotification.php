<?php

declare(strict_types=1);

namespace App\Notifications\Domain;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class GameWinnerDeclaredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $gameWinnerId,
        private readonly string $gameId,
    ) {}

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('¡Felicitaciones! Has ganado la partida')
            ->line('¡Felicitaciones! Has ganado la partida.')
            ->line('Pronto recibirás más información sobre tu premio.')
            ->line('¡Muchas gracias por participar!');
    }
}
