<?php

declare(strict_types=1);

namespace App\Notifications\Domain;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class WinnerPayoutRegisteredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $winnerPayoutId,
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
            ->subject('El pago de tu premio ha sido registrado')
            ->line('El pago de tu premio ha sido registrado por el equipo.')
            ->line('Recibirás los fondos por el canal acordado.')
            ->line('¡Gracias por participar!');
    }
}
