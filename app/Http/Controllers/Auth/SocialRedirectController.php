<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialProvider;
use App\Exceptions\Auth\SocialAuthException;
use App\Http\Controllers\Controller;
use App\Models\OauthAttempt;
use App\Services\Auth\SocialProviderAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

final class SocialRedirectController extends Controller
{
    public function __invoke(string $provider, SocialProviderAdapter $adapter): RedirectResponse
    {
        if (SocialProvider::tryFrom($provider) === null) {
            throw SocialAuthException::invalidProvider($provider);
        }

        $plainState = Str::random(64);
        $stateTtl = (int) config('services.social_auth.state_ttl_seconds', 600);

        OauthAttempt::create([
            'provider' => $provider,
            'purpose' => 'login',
            'state_hash' => hash('sha256', $plainState),
            'expires_at' => now()->addSeconds($stateTtl),
        ]);

        return redirect($adapter->getRedirectUrl($provider, $plainState));
    }
}
