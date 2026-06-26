<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\UnlinkSocialAccountAction;
use App\Enums\SocialProvider;
use App\Exceptions\Auth\SocialAuthException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UnlinkSocialAccountRequest;
use Illuminate\Http\JsonResponse;

final class UnlinkSocialAccountController extends Controller
{
    public function __invoke(
        string $provider,
        UnlinkSocialAccountRequest $request,
        UnlinkSocialAccountAction $action,
    ): JsonResponse {
        if (SocialProvider::tryFrom($provider) === null) {
            throw SocialAuthException::invalidProvider($provider);
        }

        $action->execute(
            user: $request->user(),
            provider: $provider,
            currentPassword: $request->validated('current_password'),
        );

        return response()->json([
            'message' => 'Social account unlinked successfully.',
            'provider' => $provider,
        ]);
    }
}
