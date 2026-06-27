<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\Auth\PasswordResetException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

final class ResetPasswordAction
{
    /**
     * @param  array{email:string,token:string,password:string,password_confirmation:string}  $credentials
     */
    public function execute(array $credentials): void
    {
        $status = Password::reset(
            $credentials,
            function (User $user, string $password): void {
                DB::transaction(function () use ($user, $password): void {
                    $user->password = $password;

                    if ($user->email_verified_at === null) {
                        $user->forceFill(['email_verified_at' => now()]);
                    }

                    $user->save();
                    $user->tokens()->delete();
                });

                Log::info('auth.password_reset_completed', ['user_id' => $user->id]);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new PasswordResetException($status);
        }
    }
}
