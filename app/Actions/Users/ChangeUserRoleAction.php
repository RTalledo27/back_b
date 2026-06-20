<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Privileged role change. Bypasses $fillable on purpose via forceFill so the
 * field is unreachable from request payloads but reachable from server-side
 * code that explicitly invokes this action.
 *
 * Not exposed via HTTP in Phase 1 — invoked from tinker, seeders, or future
 * admin tooling.
 */
final class ChangeUserRoleAction
{
    public function execute(User $user, UserRole $role): User
    {
        $user->forceFill(['role' => $role])->save();

        return $user;
    }
}
