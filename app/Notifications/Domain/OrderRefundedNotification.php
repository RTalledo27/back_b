<?php

declare(strict_types=1);

namespace App\Notifications\Domain;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class OrderRefundedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $orderId,
        private readonly string $refundId,
    ) {}

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Tu reembolso ha sido procesado')
            ->line('Tu reembolso ha sido procesado exitosamente.')
            ->line('Los fondos serán devueltos por el canal acordado.')
            ->line('Gracias por tu comprensión.');
    }
}
