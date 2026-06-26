<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Auth\LinkedSocialAccountResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class SocialAccountsController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        // Eager-load all social accounts in one query to avoid N+1.
        $socialAccounts = $user->socialAccounts()->get();

        // A method can be unlinked when at least one other method remains.
        $totalMethods = ($user->password !== null ? 1 : 0) + $socialAccounts->count();
        $canUnlink = $totalMethods > 1;

        $socialAccounts->each(fn ($account) => $account->setAttribute('can_unlink', $canUnlink));

        return LinkedSocialAccountResource::collection($socialAccounts);
    }
}
