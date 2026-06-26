<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use App\DTOs\Auth\AuthTokenResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AuthTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AuthTokenResult $result */
        $result = $this->resource;

        return [
            'token_type' => $result->tokenType,
            'access_token' => $result->plainTextToken,
            'abilities' => $result->abilities,
            'user' => new AuthUserResource($result->user),
        ];
    }
}
