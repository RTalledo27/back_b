<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Actions;

use App\Modules\Commerce\Application\DTOs\CreateWinnerPayoutData;
use App\Modules\Commerce\Application\DTOs\WinnerPayoutCommandResult;
use App\Modules\Commerce\Application\Support\WinnerPayoutWorkflow;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutEventType;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutStatus;
use App\Modules\Commerce\Domain\Exceptions\WinnerPayoutWorkflowException;
use App\Modules\Commerce\Domain\Models\WinnerPayout;
use App\Modules\Commerce\Domain\Models\WinnerPayoutDestination;
use App\Modules\RepeatNumberBingo\Domain\Enums\GamePrizeFundingStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\WinnerClaimStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GamePrizeFunding;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaim;

final class CreateWinnerPayoutAction
{
    public function __construct(private readonly WinnerPayoutWorkflow $workflow) {}

    public function executeWithinTransaction(CreateWinnerPayoutData $data): WinnerPayoutCommandResult
    {
        $this->workflow->assertTransaction();
        $game = Game::query()->whereKey($data->gameId)->lockForUpdate()->firstOrFail();
        $funding = GamePrizeFunding::query()->where('game_id', $game->id)->lockForUpdate()->first();
        $winner = GameWinner::query()->where('game_id', $game->id)->lockForUpdate()->first();

        if ($game->status !== GameStatus::Completed) {
            throw WinnerPayoutWorkflowException::notEligible('game_not_completed');
        }
        if ($winner === null) {
            throw WinnerPayoutWorkflowException::notEligible('winner_not_found');
        }
        if ($funding === null || $funding->status !== GamePrizeFundingStatus::Reserved) {
            throw WinnerPayoutWorkflowException::notEligible('funding_not_reserved');
        }
        if ($funding->amount_cents !== $game->prize_cents || $funding->currency !== $game->currency) {
            throw WinnerPayoutWorkflowException::notEligible('funding_mismatch');
        }

        $claim = WinnerClaim::query()->where('game_winner_id', $winner->id)->lockForUpdate()->first();
        if ($claim === null || $claim->status !== WinnerClaimStatus::Verified || (int) $claim->winner_user_id !== (int) $winner->user_id) {
            throw WinnerPayoutWorkflowException::notEligible('claim_not_verified');
        }

        $existing = WinnerPayout::query()->where('game_winner_id', $winner->id)->lockForUpdate()->first();
        if ($existing !== null) {
            throw $existing->status === WinnerPayoutStatus::LegacyRegistered
                ? WinnerPayoutWorkflowException::legacyRecord()
                : WinnerPayoutWorkflowException::notEligible('payout_already_exists');
        }

        $now = now();
        $payout = WinnerPayout::create([
            'game_winner_id' => $winner->id,
            'game_id' => $game->id,
            'winner_claim_id' => $claim->id,
            'user_id' => $winner->user_id,
            'created_by_user_id' => $data->actorUserId,
            'amount_cents' => $game->prize_cents,
            'currency' => $game->currency,
            'method' => 'manual',
            'status' => WinnerPayoutStatus::Draft,
            'idempotency_key_hash' => $data->idempotencyKeyHash,
            'request_fingerprint' => $data->requestFingerprint,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $destination = WinnerPayoutDestination::create([
            'winner_payout_id' => $payout->id,
            'version' => 1,
            'method' => $data->destination->method,
            'destination_payload_encrypted' => $data->destination->payload,
            'destination_masked' => $data->destination->masked,
            'created_by_user_id' => $data->actorUserId,
            'created_at' => $now,
        ]);

        $payout->current_destination_id = $destination->id;
        $payout->save();

        $this->workflow->recordEvent($payout, WinnerPayoutEventType::PayoutCreated, null, WinnerPayoutStatus::Draft->value, $data->actorUserId, 'admin');
        $this->workflow->recordEvent($payout, WinnerPayoutEventType::DestinationAdded, WinnerPayoutStatus::Draft->value, WinnerPayoutStatus::Draft->value, $data->actorUserId, 'admin', null, ['version' => 1, 'method' => $data->destination->method]);

        return $this->workflow->result($payout);
    }
}
