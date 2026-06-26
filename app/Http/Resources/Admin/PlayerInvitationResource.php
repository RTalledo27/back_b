<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\DTOs\Auth\CreatePlayerResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PlayerInvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CreatePlayerResult $result */
        $result = $this->resource;

        return [
            'outcome' => $result->outcome->value,
            'user' => [
                'id' => $result->user->id,
                'name' => $result->user->name,
                'email' => $result->user->email,
                'role' => $result->user->role->value,
            ],
            'invitation' => $result->invitation !== null ? [
                'id' => $result->invitation->id,
                'expires_at' => $result->invitation->expires_at->utc()->toIso8601String(),
            ] : null,
            'plain_token' => $this->when(
                app()->environment('testing', 'local') && $result->plainToken !== null,
                fn () => $result->plainToken,
            ),
        ];
    }
}
