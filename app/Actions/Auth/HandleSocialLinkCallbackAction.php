<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\DTOs\Auth\SocialLinkResult;
use App\DTOs\Auth\SocialUserData;
use App\Enums\SocialLinkOutcome;
use App\Exceptions\Auth\SocialAuthException;
use App\Models\OauthAttempt;
use App\Models\User;
use App\Models\UserSocialAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class HandleSocialLinkCallbackAction
{
    /**
     * Global lock order (prevents deadlocks with UnlinkSocialAccountAction):
     *
     *   ① OauthAttempt FOR UPDATE
     *   ② User FOR UPDATE (initiated_by_user_id)
     *   ③ advisory_lock(user_id + provider)
     *   ④ UserSocialAccount FOR UPDATE by (user_id, provider)  ← early exit for provider conflicts
     *   ⑤ advisory_lock(provider + provider_user_id)
     *   ⑥ UserSocialAccount FOR UPDATE by (provider, provider_user_id) ← identity conflict check
     *   ⑦ INSERT UserSocialAccount
     *
     * UnlinkSocialAccountAction follows the same order for the shared segment:
     *   User FOR UPDATE → advisory_lock(user_id + provider) → UserSocialAccount FOR UPDATE
     *
     * Both sequences lock User before any social account row. No cycle is possible.
     */
    public function execute(string $stateHash, SocialUserData $socialUser): SocialLinkResult
    {
        return DB::transaction(function () use ($stateHash, $socialUser): SocialLinkResult {
            // ① Lock the link attempt (purpose guard included in the query).
            $attempt = OauthAttempt::query()
                ->where('state_hash', $stateHash)
                ->where('provider', $socialUser->provider)
                ->where('purpose', 'link')
                ->lockForUpdate()
                ->first();

            if ($attempt === null) {
                throw SocialAuthException::invalidState();
            }

            if ($attempt->isExpired()) {
                throw SocialAuthException::expiredState();
            }

            if ($attempt->isConsumed()) {
                throw SocialAuthException::callbackAlreadyProcessed();
            }

            $initiatedByUserId = (int) $attempt->initiated_by_user_id;

            // ② Lock the initiating user row — always before any social account lock.
            $user = User::query()
                ->where('id', $initiatedByUserId)
                ->lockForUpdate()
                ->firstOrFail();

            // ③ Advisory lock on (user_id, provider) — serialises concurrent link
            //    and unlink operations for the same user+provider pair.
            DB::statement('SELECT pg_advisory_xact_lock(?)', [
                self::userProviderLinkKey($user->id, $socialUser->provider),
            ]);

            // ④ Check whether this user already has this provider linked.
            $existingProviderAccount = UserSocialAccount::query()
                ->where('user_id', $user->id)
                ->where('provider', $socialUser->provider)
                ->lockForUpdate()
                ->first();

            if ($existingProviderAccount !== null) {
                $attempt->update(['consumed_at' => now()]);

                // Same identity already linked to this user → idempotent success.
                if ($existingProviderAccount->provider_user_id === $socialUser->providerId) {
                    Log::info('auth.social_link_already_linked', [
                        'user_id' => $user->id,
                        'provider' => $socialUser->provider,
                    ]);

                    return new SocialLinkResult(SocialLinkOutcome::AlreadyLinked, $existingProviderAccount);
                }

                // Different identity → user already has this provider linked elsewhere.
                return new SocialLinkResult(SocialLinkOutcome::ProviderAlreadyLinked, null);
            }

            // ⑤ Advisory lock on the social identity — prevents two concurrent
            //    operations from linking the same (provider, provider_user_id) to
            //    different users simultaneously.
            DB::statement('SELECT pg_advisory_xact_lock(?)', [
                ResolveSocialIdentityAction::socialIdentityLockKey($socialUser->provider, $socialUser->providerId),
            ]);

            // ⑥ Check whether this identity is already linked to another user.
            $existingAccount = UserSocialAccount::query()
                ->where('provider', $socialUser->provider)
                ->where('provider_user_id', $socialUser->providerId)
                ->lockForUpdate()
                ->first();

            if ($existingAccount !== null) {
                // Only reachable when the identity belongs to a different user
                // (same-user case was caught at step ④).
                $attempt->update(['consumed_at' => now()]);

                return new SocialLinkResult(SocialLinkOutcome::SocialIdentityConflict, null);
            }

            // ⑦ All clear — create the link.
            $normalizedEmail = $socialUser->normalizedEmail();

            $socialAccount = UserSocialAccount::create([
                'user_id' => $user->id,
                'provider' => $socialUser->provider,
                'provider_user_id' => $socialUser->providerId,
                'provider_email' => $normalizedEmail,
                'provider_email_verified_at' => ($socialUser->emailVerified && $normalizedEmail !== null)
                    ? now()
                    : null,
            ]);

            $attempt->update(['consumed_at' => now()]);

            Log::info('auth.social_linked', [
                'user_id' => $user->id,
                'provider' => $socialUser->provider,
            ]);

            return new SocialLinkResult(SocialLinkOutcome::SocialLinked, $socialAccount);
        });
    }

    /**
     * Derives a stable PG bigint advisory lock key for a (user_id, provider) pair.
     * Shared with UnlinkSocialAccountAction to enforce the global lock order.
     */
    public static function userProviderLinkKey(int $userId, string $provider): int
    {
        $hex = hash('sha256', 'link-provider:'.$userId.':'.$provider);

        return (int) hexdec(substr($hex, 0, 15));
    }
}
