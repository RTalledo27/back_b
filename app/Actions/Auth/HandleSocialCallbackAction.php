<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\DTOs\Auth\SocialCallbackResult;
use App\DTOs\Auth\SocialUserData;
use App\Exceptions\Auth\SocialAuthException;
use App\Models\OauthAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class HandleSocialCallbackAction
{
    public function __construct(private ResolveSocialIdentityAction $resolve) {}

    public function execute(string $stateHash, SocialUserData $socialUser): SocialCallbackResult
    {
        return DB::transaction(function () use ($stateHash, $socialUser): SocialCallbackResult {
            $attempt = OauthAttempt::query()
                ->where('state_hash', $stateHash)
                ->where('provider', $socialUser->provider)
                ->where('purpose', 'login')
                ->lockForUpdate()
                ->first();

            if ($attempt === null) {
                throw SocialAuthException::invalidState();
            }

            if ($attempt->isExpired()) {
                throw SocialAuthException::expiredState();
            }

            if ($attempt->hasExchangeCode() || $attempt->isConsumed()) {
                throw SocialAuthException::callbackAlreadyProcessed();
            }

            // Resolve / create the user (nested savepoint within this transaction).
            $result = $this->resolve->execute($socialUser);

            if ($result->outcome->isSuccess()) {
                $plainCode = Str::random(64);
                $exchangeTtl = (int) config('services.social_auth.exchange_ttl_seconds', 300);

                $attempt->update([
                    'exchange_code_hash' => hash('sha256', $plainCode),
                    'user_id' => $result->user->id,
                    'expires_at' => now()->addSeconds($exchangeTtl),
                ]);

                return new SocialCallbackResult($result->outcome, $plainCode);
            }

            // Non-success outcomes: close the attempt so it cannot be replayed.
            $attempt->update(['consumed_at' => now()]);

            return new SocialCallbackResult($result->outcome, null);
        });
    }
}
