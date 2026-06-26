<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DTOs\Auth\SocialUserData;
use App\Exceptions\Auth\SocialAuthException;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;

final class SocialiteProviderAdapter implements SocialProviderAdapter
{
    public function getRedirectUrl(string $provider, string $plainState): string
    {
        $this->ensureConfigured($provider);

        return Socialite::driver($provider)
            ->stateless()
            ->with(['state' => $plainState])
            ->redirect()
            ->getTargetUrl();
    }

    public function resolveUser(string $provider): SocialUserData
    {
        $this->ensureConfigured($provider);

        $user = Socialite::driver($provider)->stateless()->user();

        return new SocialUserData(
            provider: $provider,
            providerId: (string) $user->getId(),
            email: $user->getEmail() ?: null,
            emailVerified: $this->isEmailVerified($provider, $user),
            name: $user->getName() ?? $user->getNickname() ?? '',
        );
    }

    private function isEmailVerified(string $provider, SocialiteUser $user): bool
    {
        return match ($provider) {
            // Google explicitly reports email_verified in the raw user payload.
            'google' => (bool) ($user->getRaw()['email_verified'] ?? false),
            // Facebook's standard Graph API response contains no explicit email_verified
            // field — email presence alone is not proof of verification.
            // Previously linked Facebook accounts authenticate via (provider, provider_user_id)
            // and do not require email verification on re-authentication.
            'facebook' => false,
            default => false,
        };
    }

    private function ensureConfigured(string $provider): void
    {
        $clientId = config("services.{$provider}.client_id");

        if (empty($clientId)) {
            throw SocialAuthException::missingConfiguration($provider);
        }
    }
}
