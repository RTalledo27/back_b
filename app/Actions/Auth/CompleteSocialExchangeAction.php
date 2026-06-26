<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\DTOs\Auth\AuthTokenResult;
use App\Exceptions\Auth\SocialAuthException;
use App\Models\OauthAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class CompleteSocialExchangeAction
{
    public function __construct(private IssueSanctumTokenAction $tokens) {}

    public function execute(string $plainCode): AuthTokenResult
    {
        $codeHash = hash('sha256', $plainCode);

        return DB::transaction(function () use ($codeHash): AuthTokenResult {
            $attempt = OauthAttempt::query()
                ->where('exchange_code_hash', $codeHash)
                ->lockForUpdate()
                ->first();

            if ($attempt === null) {
                throw SocialAuthException::exchangeCodeNotFound();
            }

            if ($attempt->isExpired()) {
                throw SocialAuthException::exchangeCodeExpired();
            }

            if ($attempt->isConsumed()) {
                throw SocialAuthException::exchangeCodeConsumed();
            }

            $attempt->update(['consumed_at' => now()]);

            $user = User::query()->where('id', $attempt->user_id)->firstOrFail();

            Log::info('auth.social_exchange_completed', [
                'user_id' => $user->id,
                'provider' => $attempt->provider,
            ]);

            return $this->tokens->execute($user, $attempt->provider);
        });
    }
}
