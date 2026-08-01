<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Actions;

use App\Modules\RepeatNumberBingo\Application\DTOs\RecordGamePrizeFundingData;
use App\Modules\RepeatNumberBingo\Application\DTOs\RecordGamePrizeFundingResult;
use App\Modules\RepeatNumberBingo\Domain\Enums\GamePrizeFundingEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GamePrizeFundingStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GamePrizeFundingNotProcessable;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GamePrizeFunding;
use App\Modules\RepeatNumberBingo\Domain\Models\GamePrizeFundingDocument;
use App\Modules\RepeatNumberBingo\Domain\Models\GamePrizeFundingEvent;
use Illuminate\Support\Facades\DB;
use LogicException;

final class RecordGamePrizeFundingAction
{
    public function executeWithinTransaction(
        RecordGamePrizeFundingData $data,
    ): RecordGamePrizeFundingResult {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'RecordGamePrizeFundingAction::executeWithinTransaction requires an active database transaction.'
            );
        }

        /** @var Game $game */
        $game = Game::query()
            ->whereKey($data->gameId)
            ->lockForUpdate()
            ->firstOrFail();

        if (in_array($game->status, [GameStatus::Completed, GameStatus::Cancelled], true)) {
            throw GamePrizeFundingNotProcessable::gameStatus($game->id, $game->status->value);
        }

        /** @var GamePrizeFunding $funding */
        $funding = GamePrizeFunding::query()
            ->where('game_id', $game->id)
            ->lockForUpdate()
            ->firstOrFail();

        if (! in_array($funding->status, [
            GamePrizeFundingStatus::Unfunded,
            GamePrizeFundingStatus::LegacyUnverified,
        ], true)) {
            throw GamePrizeFundingNotProcessable::status($game->id, $funding->status->value);
        }

        if ($funding->amount_cents !== $game->prize_cents || $funding->currency !== $game->currency) {
            throw GamePrizeFundingNotProcessable::amountMismatch($game->id);
        }

        $fromStatus = $funding->status->value;
        $fundedAt = now();
        $funding->transitionTo(GamePrizeFundingStatus::Funded);
        $funding->funded_by_user_id = $data->actorUserId;
        $funding->funded_at = $fundedAt;
        $funding->idempotency_key_hash = $data->idempotencyKeyHash;
        $funding->request_fingerprint = $data->requestFingerprint;
        $funding->save();

        $document = GamePrizeFundingDocument::forceCreate([
            'id' => $data->documentId,
            'game_prize_funding_id' => $funding->id,
            'disk' => $data->documentDisk,
            'path' => $data->documentPath,
            'original_filename' => $data->documentOriginalFilename,
            'mime_type' => $data->documentMimeType,
            'size_bytes' => $data->documentSizeBytes,
            'sha256' => $data->documentSha256,
            'uploaded_by_user_id' => $data->actorUserId,
            'created_at' => $fundedAt,
        ]);

        GamePrizeFundingEvent::forceCreate([
            'game_prize_funding_id' => $funding->id,
            'event_type' => GamePrizeFundingEventType::FundingRecorded,
            'from_status' => $fromStatus,
            'to_status' => GamePrizeFundingStatus::Funded->value,
            'actor_user_id' => $data->actorUserId,
            'reason_code' => 'administrative_funding_recorded',
            'safe_metadata' => [
                'document_id' => $document->id,
                'mime_type' => $document->mime_type,
                'size_bytes' => $document->size_bytes,
            ],
            'correlation_id' => null,
            'occurred_at' => $fundedAt,
            'created_at' => $fundedAt,
        ]);

        return new RecordGamePrizeFundingResult(
            fundingId: $funding->id,
            gameId: $game->id,
            status: $funding->status->value,
            amountCents: $funding->amount_cents,
            currency: $funding->currency,
            documentId: $document->id,
            fundedAt: $fundedAt->toIso8601String(),
        );
    }
}
