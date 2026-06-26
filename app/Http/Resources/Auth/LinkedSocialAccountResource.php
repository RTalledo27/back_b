<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use App\Models\UserSocialAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LinkedSocialAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var UserSocialAccount $account */
        $account = $this->resource;

        return [
            'provider' => $account->provider,
            'provider_email_masked' => $this->maskEmail($account->provider_email),
            'provider_email_verified' => $account->provider_email_verified_at !== null,
            'linked_at' => $account->created_at?->utc()->toIso8601String(),
            'can_unlink' => (bool) $account->getAttribute('can_unlink'),
        ];
    }

    private function maskEmail(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);

        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));

        return $visible.'***@'.$domain;
    }
}
