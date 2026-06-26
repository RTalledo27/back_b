<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\DTOs\Auth\AuthTokenResult;
use App\DTOs\Auth\RegisterPlayerData;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RegisterPlayerAction
{
    public function __construct(private IssueSanctumTokenAction $tokens) {}

    public function execute(RegisterPlayerData $data): AuthTokenResult
    {
        return DB::transaction(function () use ($data): AuthTokenResult {
            $user = new User([
                'name' => $data->name,
                'email' => $data->email,
                'password' => $data->password,
            ]);

            $user->forceFill([
                'role' => UserRole::Player,
                'email_verified_at' => null,
            ]);

            $user->save();

            return $this->tokens->execute($user);
        });
    }
}
