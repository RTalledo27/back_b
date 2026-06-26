<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\HandleSocialLinkCallbackAction;
use App\Enums\SocialLinkOutcome;
use App\Enums\SocialProvider;
use App\Exceptions\Auth\SocialAuthException;
use App\Http\Controllers\Controller;
use App\Models\OauthAttempt;
use App\Services\Auth\SocialProviderAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class SocialLinkCallbackController extends Controller
{
    public function __invoke(
        string $provider,
        Request $request,
        SocialProviderAdapter $adapter,
        HandleSocialLinkCallbackAction $handleLink,
    ): RedirectResponse {
        $frontendBase = rtrim((string) config('services.social_auth.frontend_url', ''), '/');
        $frontendCallback = $frontendBase.'/auth/social/link/callback';

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

        // Optimistic pre-check before calling the OAuth provider.
        // purpose='link' guard prevents a login-purpose attempt from being used here.
        $preCheck = OauthAttempt::query()
            ->where('state_hash', $stateHash)
            ->where('provider', $provider)
            ->where('purpose', 'link')
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

        if ($preCheck->isConsumed()) {
            return redirect($frontendCallback.'?'.http_build_query([
                'error' => 'callback_already_processed',
                'provider' => $provider,
            ]));
        }

        try {
            $socialUser = $adapter->resolveUser($provider);
        } catch (SocialAuthException $e) {
            return redirect($frontendCallback.'?'.http_build_query([
                'error' => $e->errorCode,
                'provider' => $provider,
            ]));
        } catch (\Throwable $e) {
            Log::warning('auth.social_link_callback_provider_error', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return redirect($frontendCallback.'?'.http_build_query([
                'error' => 'oauth_error',
                'provider' => $provider,
            ]));
        }

        try {
            $result = $handleLink->execute($stateHash, $socialUser);
        } catch (SocialAuthException $e) {
            return redirect($frontendCallback.'?'.http_build_query([
                'error' => $e->errorCode,
                'provider' => $provider,
            ]));
        }

        // Conflict outcomes redirect with error; success/idempotent outcomes with outcome.
        $isError = $result->outcome === SocialLinkOutcome::SocialIdentityConflict
            || $result->outcome === SocialLinkOutcome::ProviderAlreadyLinked;

        if ($isError) {
            return redirect($frontendCallback.'?'.http_build_query([
                'error' => $result->outcome->value,
                'provider' => $provider,
            ]));
        }

        return redirect($frontendCallback.'?'.http_build_query([
            'outcome' => $result->outcome->value,
            'provider' => $provider,
        ]));
    }
}
