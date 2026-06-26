<?php

declare(strict_types=1);

namespace Tests\Support;

use App\DTOs\Auth\SocialUserData;
use App\Exceptions\Auth\SocialAuthException;
use App\Services\Auth\SocialProviderAdapter;

/**
 * Test double for SocialProviderAdapter.
 * Bind in tests via:  $this->app->instance(SocialProviderAdapter::class, new FakeSocialProviderAdapter());
 */
final class FakeSocialProviderAdapter implements SocialProviderAdapter
{
    /** @var array<string, SocialUserData> keyed by provider */
    private array $users = [];

    /** @var array<string, true> providers configured to be missing */
    private array $missingConfig = [];

    private int $resolveUserCallCount = 0;

    public function configureMissing(string $provider): void
    {
        $this->missingConfig[$provider] = true;
    }

    public function configureUser(SocialUserData $user): void
    {
        $this->users[$user->provider] = $user;
    }

    public function getResolveUserCallCount(): int
    {
        return $this->resolveUserCallCount;
    }

    public function getRedirectUrl(string $provider, string $plainState): string
    {
        if (isset($this->missingConfig[$provider])) {
            throw SocialAuthException::missingConfiguration($provider);
        }

        return "https://fake-oauth-provider.test/{$provider}/auth?state={$plainState}";
    }

    public function resolveUser(string $provider): SocialUserData
    {
        $this->resolveUserCallCount++;

        if (isset($this->missingConfig[$provider])) {
            throw SocialAuthException::missingConfiguration($provider);
        }

        if (! isset($this->users[$provider])) {
            throw new \LogicException("FakeSocialProviderAdapter: no user configured for provider [{$provider}].");
        }

        return $this->users[$provider];
    }
}
