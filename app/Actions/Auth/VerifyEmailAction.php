<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\Auth\EmailVerificationException;
use App\Models\User;
use Illuminate\Support\Facades\Log;

final class VerifyEmailAction
{
    public function execute(User $user, string $id, string $hash): void
    {
        if ((string) $user->getKey() !== $id) {
            throw new EmailVerificationException('id mismatch');
        }

        if (! hash_equals(sha1((string) $user->getEmailForVerification()), $hash)) {
            throw new EmailVerificationException('hash mismatch');
        }

        if ($user->hasVerifiedEmail()) {
            return;
        }

        $user->forceFill(['email_verified_at' => now()])->save();

        Log::info('auth.email_verified', ['user_id' => $user->id]);
    }
}
