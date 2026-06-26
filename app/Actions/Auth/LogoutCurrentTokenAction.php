<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;

final class LogoutCurrentTokenAction
{
    public function execute(User $user, ?string $plainTextToken = null): void
    {
        if ($plainTextToken !== null) {
            $tokenValue = str_contains($plainTextToken, '|')
                ? explode('|', $plainTextToken, 2)[1]
                : $plainTextToken;

            $deleted = $user->tokens()
                ->where('token', hash('sha256', $tokenValue))
                ->delete();

            if ($deleted > 0) {

                return;
            }
        }

        $token = $user->currentAccessToken();

        if ($token !== null && method_exists($token, 'delete')) {
            $token->delete();
        }
    }
}
