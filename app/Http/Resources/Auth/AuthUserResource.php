<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AuthUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'email_verified' => $user->email_verified_at !== null,
            'email_verified_at' => $user->email_verified_at?->utc()->toIso8601String(),
            'capabilities' => [
                'can_access_admin' => $user->isAdmin(),
                'can_use_player_features' => true,
            ],
        ];
    }
}
