<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\DTOs\Auth\AuthTokenResult;
use App\DTOs\Auth\LoginCredentialsData;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class AuthenticateUserAction
{
    public const INVALID_CREDENTIALS_MESSAGE = 'The provided credentials are invalid.';

    public function __construct(private IssueSanctumTokenAction $tokens) {}

    public function execute(LoginCredentialsData $credentials): AuthTokenResult
    {
        $user = User::query()
            ->where('email', $credentials->email)
            ->first();

        if (! $user instanceof User || $user->password === null || ! Hash::check($credentials->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => [self::INVALID_CREDENTIALS_MESSAGE],
            ]);
        }

        return DB::transaction(fn (): AuthTokenResult => $this->tokens->execute($user));
    }
}
