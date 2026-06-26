<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\DTOs\Auth\CreatePlayerData;
use App\DTOs\Auth\CreatePlayerResult;
use App\Enums\CreatePlayerOutcome;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class CreatePlayerInvitationAction
{
    public const INVITATION_TTL_DAYS = 7;

    public function execute(CreatePlayerData $data): CreatePlayerResult
    {
        return DB::transaction(function () use ($data): CreatePlayerResult {
            // Serialize concurrent operations for the same normalized email using a
            // PostgreSQL transaction-scoped advisory lock. The lock is automatically
            // released on COMMIT or ROLLBACK, so it cannot be orphaned.
            //
            // This avoids the SELECT→INSERT gap without relying on catching a
            // UniqueConstraintViolationException, which leaves the transaction in an
            // aborted state in PostgreSQL (making any subsequent query inside the same
            // transaction fail with "current transaction is aborted").
            DB::statement('SELECT pg_advisory_xact_lock(?)', [self::emailAdvisoryLockKey($data->email)]);

            $user = User::query()
                ->where('email', $data->email)
                ->lockForUpdate()
                ->first();

            if ($user === null) {
                $user = new User([
                    'name' => $data->name,
                    'email' => $data->email,
                    'password' => null,
                ]);
                $user->forceFill(['role' => UserRole::Player]);
                $user->save();
            }

            if ($user->role === UserRole::Admin || $user->password !== null) {
                Log::info('auth.player_invite_skipped', [
                    'reason' => $user->role === UserRole::Admin ? 'admin_account' : 'already_registered',
                    'invited_by' => $data->invitedByUserId,
                ]);

                return new CreatePlayerResult(
                    outcome: CreatePlayerOutcome::AlreadyRegistered,
                    user: $user,
                    invitation: null,
                    plainToken: null,
                );
            }

            $outcome = $user->wasRecentlyCreated
                ? CreatePlayerOutcome::Invited
                : CreatePlayerOutcome::Reinvited;

            UserInvitation::query()
                ->where('user_id', $user->id)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $plainToken = Str::random(64);
            $tokenHash = hash('sha256', $plainToken);
            $expiresAt = now()->addDays(self::INVITATION_TTL_DAYS);

            $invitation = UserInvitation::create([
                'user_id' => $user->id,
                'invited_by_user_id' => $data->invitedByUserId,
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
            ]);

            Log::info('auth.player_invited', [
                'user_id' => $user->id,
                'invited_by' => $data->invitedByUserId,
                'outcome' => $outcome->value,
                'expires_at' => $expiresAt->toIso8601String(),
            ]);

            return new CreatePlayerResult(
                outcome: $outcome,
                user: $user,
                invitation: $invitation,
                plainToken: $plainToken,
            );
        });
    }

    /**
     * Derives a stable PostgreSQL bigint advisory lock key from the normalized email.
     *
     * Uses the first 15 hex characters (60 bits) of sha256, which always fits within
     * PHP's int64 (max 2^63−1) and PostgreSQL's bigint (same range). A namespace prefix
     * prevents accidental collisions with advisory locks from other subsystems.
     */
    public static function emailAdvisoryLockKey(string $normalizedEmail): int
    {
        $hex = hash('sha256', 'create-player:'.$normalizedEmail);

        return (int) hexdec(substr($hex, 0, 15));
    }
}
