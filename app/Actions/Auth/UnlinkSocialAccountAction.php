<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\Auth\SocialAuthException;
use App\Models\User;
use App\Models\UserSocialAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

final class UnlinkSocialAccountAction
{
    /**
     * Lock order (matches HandleSocialLinkCallbackAction shared segment):
     *   ① User FOR UPDATE
     *   ② advisory_lock(user_id + provider)
     *   ③ UserSocialAccount FOR UPDATE
     *
     * Reautenticación decision:
     *   - Users with a local password must supply `current_password`.
     *   - Social-only users must present the Sanctum token that was issued
     *     immediately after a social login (name "social:{provider}", ability
     *     "social_reauth", created within AUTH_SOCIAL_REAUTH_TTL_SECONDS).
     *     The provider encoded in the token name must still be linked to the user.
     */
    public function execute(User $user, string $provider, ?string $currentPassword): void
    {
        DB::transaction(function () use ($user, $provider, $currentPassword): void {
            // ① Lock the user row before any reads or writes.
            $lockedUser = User::query()
                ->where('id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            // ② Validate authentication.
            if ($lockedUser->password !== null) {
                if ($currentPassword === null || ! Hash::check($currentPassword, $lockedUser->password)) {
                    throw SocialAuthException::incorrectPassword();
                }
            } else {
                // Social-only user: verify a recent social-login Sanctum token.
                // Uses $user (original request instance) because currentAccessToken()
                // is set by Sanctum on the authenticated user model, not on a DB reload.
                $this->verifySocialReauth($user);
            }

            // ③ Advisory lock on (user_id, provider) — same key used by the link
            //    flow, so link and unlink for the same user+provider are serialised.
            DB::statement('SELECT pg_advisory_xact_lock(?)', [
                HandleSocialLinkCallbackAction::userProviderLinkKey($lockedUser->id, $provider),
            ]);

            // ④ Lock the social account to unlink.
            $socialAccount = UserSocialAccount::query()
                ->where('user_id', $lockedUser->id)
                ->where('provider', $provider)
                ->lockForUpdate()
                ->first();

            if ($socialAccount === null) {
                throw SocialAuthException::notLinked($provider);
            }

            // ⑤ Guard against removing the last authentication method.
            $otherSocialCount = UserSocialAccount::query()
                ->where('user_id', $lockedUser->id)
                ->where('provider', '!=', $provider)
                ->count();

            if ($lockedUser->password === null && $otherSocialCount === 0) {
                throw SocialAuthException::lastAuthenticationMethod();
            }

            $socialAccount->delete();

            Log::info('auth.social_unlinked', [
                'user_id' => $lockedUser->id,
                'provider' => $provider,
            ]);
        });
    }

    /**
     * Verifies that a social-only user holds a fresh social-login Sanctum token:
     *   - Must be a real PersonalAccessToken (not a TransientToken).
     *   - Must carry the "social_reauth" ability.
     *   - Token name must match "social:{provider}".
     *   - Token must have been created within AUTH_SOCIAL_REAUTH_TTL_SECONDS.
     *   - The provider encoded in the token name must still be linked to the user,
     *     preventing use of a token whose source account was subsequently removed.
     */
    private function verifySocialReauth(User $user): void
    {
        $token = $user->currentAccessToken();

        if (! ($token instanceof PersonalAccessToken)) {
            throw SocialAuthException::reauthenticationRequired();
        }

        $tokenName = (string) $token->name;

        if (! $token->can('social_reauth') || ! str_starts_with($tokenName, 'social:')) {
            throw SocialAuthException::reauthenticationRequired();
        }

        $ttl = (int) config('services.social_auth.reauth_ttl_seconds', 300);

        if ($token->created_at->lt(now()->subSeconds($ttl))) {
            throw SocialAuthException::reauthenticationRequired();
        }

        $tokenProvider = substr($tokenName, 7); // strip 'social:'

        // The provider used for the social login must still be linked. This read is
        // safe because we already hold the User row lock (step ①) — no concurrent
        // unlink can remove the token's provider account while we hold that lock.
        $providerStillLinked = UserSocialAccount::query()
            ->where('user_id', $user->id)
            ->where('provider', $tokenProvider)
            ->exists();

        if (! $providerStillLinked) {
            throw SocialAuthException::reauthenticationRequired();
        }
    }
}
