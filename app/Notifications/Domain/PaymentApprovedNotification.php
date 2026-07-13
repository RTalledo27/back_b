<?php

declare(strict_types=1);

namespace App\Notifications\Domain;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PaymentApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $paymentId,
        private readonly string $orderId,
    ) {}

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Tu pago ha sido aprobado')
            ->line('Tu pago ha sido aprobado exitosamente.')
            ->line('Tu orden ha sido confirmada y tus números están reservados.')
            ->line('Gracias por participar.');
    }
}
