<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\DTOs\Auth\AuthTokenResult;
use App\Models\User;

final class IssueSanctumTokenAction
{
    public const TOKEN_NAME = 'local-auth';

    /**
     * Social login tokens are named "social:{provider}" and include the
     * "social_reauth" ability so that UnlinkSocialAccountAction can
     * verify a recent social authentication before allowing an unlink.
     */
    public function execute(User $user, ?string $provider = null): AuthTokenResult
    {
        $abilities = $this->abilitiesFor($user, $provider);
        $tokenName = $provider !== null ? 'social:'.$provider : self::TOKEN_NAME;
        $token = $user->createToken($tokenName, $abilities);

        return new AuthTokenResult(
            user: $user,
            plainTextToken: $token->plainTextToken,
            abilities: $abilities,
        );
    }

    /** @return list<string> */
    public function abilitiesFor(User $user, ?string $provider = null): array
    {
        $abilities = [
            'auth:logout',
            'player:access',
            'user:read',
        ];

        if ($user->isAdmin()) {
            $abilities[] = 'admin:access';
        }

        if ($provider !== null) {
            $abilities[] = 'social_reauth';
        }

        sort($abilities);

        return $abilities;
    }
}
