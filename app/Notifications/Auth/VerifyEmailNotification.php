<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

final class VerifyEmailNotification extends Notification
{
    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verifica tu correo electrónico')
            ->line('Haz clic en el botón de abajo para verificar tu correo electrónico.')
            ->action('Verificar correo', $this->buildVerificationUrl($notifiable))
            ->line('Si no creaste esta cuenta, puedes ignorar este correo.')
            ->line('Este enlace expirará en '.config('auth.email_verify_ttl_minutes', 60).' minutos.');
    }

    private function buildVerificationUrl(mixed $notifiable): string
    {
        $ttl = (int) config('auth.email_verify_ttl_minutes', 60);
        $hash = sha1((string) $notifiable->getEmailForVerification());

        $signedBackendUrl = URL::temporarySignedRoute(
            'auth.email.verify',
            now()->addMinutes($ttl),
            [
                'id' => $notifiable->getKey(),
                'hash' => $hash,
            ],
        );

        $frontendBase = config('auth.email_verify_frontend_url');

        if ($frontendBase) {
            $parsed = parse_url($signedBackendUrl);
            $queryString = $parsed['query'] ?? '';

            return rtrim($frontendBase, '/').'/'.$notifiable->getKey().'/'.$hash.'?'.$queryString;
        }

        return $signedBackendUrl;
    }
}
