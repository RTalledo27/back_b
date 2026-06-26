<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\DTOs\Auth\ActivatePlayerData;
use App\DTOs\Auth\AuthTokenResult;
use App\Exceptions\Auth\InvalidActivationTokenException;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ActivatePlayerAction
{
    public function __construct(private IssueSanctumTokenAction $tokens) {}

    public function execute(ActivatePlayerData $data): AuthTokenResult
    {
        return DB::transaction(function () use ($data): AuthTokenResult {
            $tokenHash = hash('sha256', $data->token);

            // Read without lock first to get user_id — ensures user is locked before
            // invitation to maintain consistent lock ordering (user → invitation)
            // and prevent deadlocks with CreatePlayerInvitationAction.
            $invitationRef = UserInvitation::query()
                ->where('token_hash', $tokenHash)
                ->first();

            if ($invitationRef === null) {
                throw InvalidActivationTokenException::notFound();
            }

            $user = User::query()
                ->where('id', $invitationRef->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            $invitation = UserInvitation::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->firstOrFail();

            if ($invitation->isConsumed()) {
                throw InvalidActivationTokenException::consumed();
            }

            if ($invitation->isRevoked()) {
                throw InvalidActivationTokenException::revoked();
            }

            if ($invitation->isExpired()) {
                throw InvalidActivationTokenException::expired();
            }

            if ($user->password !== null) {
                throw InvalidActivationTokenException::alreadyActive();
            }

            $user->password = $data->password;
            $user->save();

            $invitation->consumed_at = now();
            $invitation->save();

            Log::info('auth.player_activated', ['user_id' => $user->id]);

            return $this->tokens->execute($user);
        });
    }
}
