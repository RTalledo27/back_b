<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

final class SendPasswordResetLinkAction
{
    public function execute(string $email): void
    {
        $status = Password::sendResetLink(['email' => $email]);

        if ($status === Password::RESET_LINK_SENT) {
            $user = User::query()->where('email', $email)->first();
            Log::info('auth.password_reset_requested', ['user_id' => $user?->id]);
        }
    }
}
