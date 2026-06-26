<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DTOs\Auth\SocialUserData;
use App\Exceptions\Auth\SocialAuthException;

interface SocialProviderAdapter
{
    /**
     * Returns the OAuth provider's authorization URL.
     * The plain state must be embedded in the URL and validated in the callback.
     */
    public function getRedirectUrl(string $provider, string $plainState): string;

    /**
     * Exchanges the authorization code (present in the current request) for
     * the authenticated social user's identity.
     *
     * @throws SocialAuthException when the provider is not configured
     */
    public function resolveUser(string $provider): SocialUserData;
}
