<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\DTOs\Auth\SocialAuthResult;
use App\DTOs\Auth\SocialUserData;
use App\Enums\SocialAuthOutcome;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserSocialAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ResolveSocialIdentityAction
{
    public function execute(SocialUserData $data): SocialAuthResult
    {
        return DB::transaction(function () use ($data): SocialAuthResult {
            // Serialize concurrent callbacks for the same social identity.
            // Prevents duplicate UserSocialAccount / User creation without relying
            // on catching UniqueConstraintViolationException (which aborts the PG
            // transaction and makes any subsequent query fail).
            DB::statement('SELECT pg_advisory_xact_lock(?)', [self::socialIdentityLockKey($data->provider, $data->providerId)]);

            $socialAccount = UserSocialAccount::query()
                ->where('provider', $data->provider)
                ->where('provider_user_id', $data->providerId)
                ->lockForUpdate()
                ->first();

            if ($socialAccount !== null) {
                $user = User::query()->where('id', $socialAccount->user_id)->firstOrFail();

                Log::info('auth.social_authenticated', [
                    'user_id' => $user->id,
                    'provider' => $data->provider,
                ]);

                return new SocialAuthResult(SocialAuthOutcome::Authenticated, $user);
            }

            if (! $data->hasVerifiedEmail()) {
                return new SocialAuthResult(SocialAuthOutcome::VerifiedEmailRequired, null);
            }

            $normalizedEmail = (string) $data->normalizedEmail();

            // Serialize concurrent callbacks that share an email (different social
            // identities).  Uses the same email-lock namespace as
            // CreatePlayerInvitationAction so both paths contend on the same key.
            DB::statement('SELECT pg_advisory_xact_lock(?)', [CreatePlayerInvitationAction::emailAdvisoryLockKey($normalizedEmail)]);

            $existingUser = User::query()
                ->where('email', $normalizedEmail)
                ->lockForUpdate()
                ->first();

            if ($existingUser !== null) {
                return new SocialAuthResult(SocialAuthOutcome::AccountLinkRequired, null);
            }

            $user = new User([
                'name' => $data->name ?: $normalizedEmail,
                'email' => $normalizedEmail,
                'password' => null,
            ]);
            $user->forceFill(['role' => UserRole::Player]);

            // Mark email as verified only when the adapter explicitly confirmed it.
            if ($data->hasVerifiedEmail()) {
                $user->forceFill(['email_verified_at' => now()]);
            }

            $user->save();

            UserSocialAccount::create([
                'user_id' => $user->id,
                'provider' => $data->provider,
                'provider_user_id' => $data->providerId,
                'provider_email' => $normalizedEmail,
                'provider_email_verified_at' => $data->emailVerified ? now() : null,
            ]);

            Log::info('auth.social_user_created', [
                'user_id' => $user->id,
                'provider' => $data->provider,
            ]);

            return new SocialAuthResult(SocialAuthOutcome::Created, $user);
        });
    }

    /**
     * Derives a stable PostgreSQL bigint advisory lock key for a social identity.
     * First 15 hex characters of sha256 → 60 bits, always within PHP int64 and PG bigint.
     */
    public static function socialIdentityLockKey(string $provider, string $providerId): int
    {
        $hex = hash('sha256', 'social:'.$provider.':'.$providerId);

        return (int) hexdec(substr($hex, 0, 15));
    }
}
