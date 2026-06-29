<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Support\Facades\Log;

final class SendEmailVerificationNotificationAction
{
    public function execute(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $user->notify(new VerifyEmailNotification);

        Log::info('auth.verification_email_sent', ['user_id' => $user->id]);
    }
}
