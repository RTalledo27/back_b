<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\HandleSocialCallbackAction;
use App\Enums\SocialProvider;
use App\Exceptions\Auth\SocialAuthException;
use App\Http\Controllers\Controller;
use App\Models\OauthAttempt;
use App\Services\Auth\SocialProviderAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class SocialCallbackController extends Controller
{
    public function __invoke(
        string $provider,
        Request $request,
        SocialProviderAdapter $adapter,
        HandleSocialCallbackAction $handleCallback,
    ): RedirectResponse {
        $frontendBase = rtrim((string) config('services.social_auth.frontend_url', ''), '/');
        $frontendCallback = $frontendBase.'/auth/social/callback';

        // OAuth provider reported an error (e.g. user cancelled).
        if ($request->has('error') || $request->missing('code')) {
            return redirect($frontendCallback.'?'.http_build_query([
                'error' => 'oauth_error',
                'provider' => $provider,
            ]));
        }

        if (SocialProvider::tryFrom($provider) === null) {
            return redirect($frontendCallback.'?'.http_build_query([
                'error' => 'invalid_provider',
                'provider' => $provider,
            ]));
        }

        $plainState = (string) $request->input('state', '');
        $stateHash = hash('sha256', $plainState);

        // Optimistic pre-check before calling the OAuth provider (expensive HTTP call).
        // purpose='login' ensures a link-purpose attempt cannot be replayed here.
        $preCheck = OauthAttempt::query()
            ->where('state_hash', $stateHash)
            ->where('provider', $provider)
            ->where('purpose', 'login')
            ->first();

        if ($preCheck === null) {
            return redirect($frontendCallback.'?'.http_build_query([
                'error' => 'invalid_state',
                'provider' => $provider,
            ]));
        }

        if ($preCheck->isExpired()) {
            return redirect($frontendCallback.'?'.http_build_query([
                'error' => 'expired_state',
                'provider' => $provider,
            ]));
        }

        if ($preCheck->hasExchangeCode() || $preCheck->isConsumed()) {
            return redirect($frontendCallback.'?'.http_build_query([
                'error' => 'callback_already_processed',
                'provider' => $provider,
            ]));
        }

        // Exchange the authorization code with the OAuth provider.
        try {
            $socialUser = $adapter->resolveUser($provider);
        } catch (SocialAuthException $e) {
            return redirect($frontendCallback.'?'.http_build_query([
                'error' => $e->errorCode,
                'provider' => $provider,
            ]));
        } catch (\Throwable $e) {
            Log::warning('auth.social_callback_provider_error', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return redirect($frontendCallback.'?'.http_build_query([
                'error' => 'oauth_error',
                'provider' => $provider,
            ]));
        }

        // Transactional: validate state (with lock), resolve identity, update attempt.
        try {
            $result = $handleCallback->execute($stateHash, $socialUser);
        } catch (SocialAuthException $e) {
            return redirect($frontendCallback.'?'.http_build_query([
                'error' => $e->errorCode,
                'provider' => $provider,
            ]));
        }

        if (! $result->outcome->isSuccess()) {
            return redirect($frontendCallback.'?'.http_build_query([
                'error' => $result->outcome->value,
                'provider' => $provider,
            ]));
        }

        // Plain exchange code goes to the frontend; the Sanctum token is only
        // returned via POST /auth/social/exchange (never in a URL or query string).
        return redirect($frontendCallback.'?'.http_build_query([
            'code' => $result->plainCode,
            'provider' => $provider,
        ]));
    }
}
