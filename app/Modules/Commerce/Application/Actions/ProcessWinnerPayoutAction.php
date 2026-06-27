<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Actions;

use App\Modules\Commerce\Application\DTOs\ProcessWinnerPayoutData;
use App\Modules\Commerce\Application\DTOs\ProcessWinnerPayoutResult;
use App\Modules\Commerce\Domain\Events\WinnerPayoutRegistered;
use App\Modules\Commerce\Domain\Exceptions\IdempotencyKeyMismatch;
use App\Modules\Commerce\Domain\Exceptions\PayoutNotProcessable;
use App\Modules\Commerce\Domain\Models\WinnerPayout;
use App\Modules\Commerce\Domain\Models\WinnerPayoutDocument;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Admin registers a manual payout for a completed game's winner.
 *
 * Canonical lock order:
 *   1. Game         FOR UPDATE  — snapshot prize_cents/currency; validate Completed
 *   2. GameWinner   FOR UPDATE  — ensures one winner, gets user_id
 *   3. WinnerPayout FOR UPDATE  — early-return idempotency check (by game_winner_id)
 *   4. Validate invariants
 *   5. INSERT WinnerPayout
 *   6. INSERT WinnerPayoutDocument
 *   7. INSERT GameEvent (inside transaction)
 *   8. COMMIT
 *   9. Dispatch WinnerPayoutRegistered post-commit (in try/catch)
 *
 * Idempotency (state-based, table-level):
 *  - Same game_winner_id + same idempotency_key_hash + same fingerprint → return existing
 *  - Same game_winner_id + same idempotency_key_hash + different fingerprint → IdempotencyKeyMismatch
 *  - Same game_winner_id + different idempotency_key_hash → return existing (was_already_processed=true)
 *
 * The fingerprint is computed inside the action (after locking GameWinner to get its id).
 * The document file is stored BEFORE calling this action (by the controller).
 *
 * @throws PayoutNotProcessable
 * @throws IdempotencyKeyMismatch
 */
final class ProcessWinnerPayoutAction
{
    public function execute(ProcessWinnerPayoutData $data): ProcessWinnerPayoutResult
    {
        $result = DB::transaction(
            fn (): ProcessWinnerPayoutResult => $this->executeWithinTransaction($data),
        );

        if (! $result->wasAlreadyProcessed) {
            try {
                WinnerPayoutRegistered::dispatch(
                    $result->payoutId,
                    $result->gameWinnerId,
                    $result->gameId,
                    $result->winnerUserId,
                    $result->actorUserId,
                    $result->amountCents,
                    $result->currency,
                    $result->externalReference,
                    $result->processedAt,
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $result;
    }

    public function executeWithinTransaction(ProcessWinnerPayoutData $data): ProcessWinnerPayoutResult
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ProcessWinnerPayoutAction::executeWithinTransaction requires an active database transaction.');
        }

        // Step 1: Game FOR UPDATE (snapshot prize_cents/currency; validate Completed)
        $game = Game::query()->whereKey($data->gameId)->lockForUpdate()->firstOrFail();

        // Step 2: GameWinner FOR UPDATE
        $winner = GameWinner::query()->where('game_id', $game->id)->lockForUpdate()->first();

        if ($winner === null) {
            throw PayoutNotProcessable::winnerNotFound($game->id);
        }

        // Step 3: WinnerPayout FOR UPDATE (early-return idempotency check)
        $existing = WinnerPayout::query()->where('game_winner_id', $winner->id)->lockForUpdate()->first();

        if ($existing !== null) {
            return $this->resolveExistingPayout($existing, $data, $winner);
        }

        // Step 4: Validate invariants (AFTER idempotency check)
        if ($game->status !== GameStatus::Completed) {
            throw PayoutNotProcessable::gameNotCompleted((string) $game->id, $game->status->value);
        }

        if ($game->prize_cents <= 0) {
            throw PayoutNotProcessable::prizeAmountInvalid((string) $game->id, $game->prize_cents);
        }

        // Compute fingerprint now that we have game_winner_id under lock
        $requestFingerprint = $this->computeFingerprint($data, (string) $winner->id);

        $processedAt = now();

        // Step 5: INSERT WinnerPayout
        $payout = WinnerPayout::create([
            'game_winner_id' => $winner->id,
            'game_id' => $game->id,
            'user_id' => $winner->user_id,
            'amount_cents' => $game->prize_cents,
            'currency' => $game->currency,
            'method' => 'manual',
            'external_reference' => $data->externalReference,
            'notes' => $data->notes,
            'idempotency_key_hash' => $data->idempotencyKeyHash,
            'request_fingerprint' => $requestFingerprint,
            'processed_by_user_id' => $data->actorUserId,
            'processed_at' => $processedAt,
            'created_at' => $processedAt,
        ]);

        // Step 6: INSERT WinnerPayoutDocument
        $document = WinnerPayoutDocument::create([
            'payout_id' => $payout->id,
            'disk' => $data->documentDisk,
            'path' => $data->documentPath,
            'original_filename' => $data->documentOriginalFilename,
            'mime_type' => $data->documentMimeType,
            'size_bytes' => $data->documentSizeBytes,
            'sha256' => $data->documentSha256,
            'uploaded_by' => $data->actorUserId,
            'created_at' => $processedAt,
        ]);

        // Step 7: INSERT GameEvent (inside transaction)
        GameEvent::create([
            'game_id' => $game->id,
            'type' => GameEventType::PayoutPaid,
            'payload' => [
                'payout_id' => $payout->id,
                'game_winner_id' => $winner->id,
                'game_id' => $game->id,
                'winner_user_id' => $winner->user_id,
                'actor_user_id' => $data->actorUserId,
                'amount_cents' => $game->prize_cents,
                'currency' => $game->currency,
                'external_reference' => $data->externalReference,
            ],
            'actor_user_id' => $data->actorUserId,
            'occurred_at' => $processedAt,
        ]);

        return new ProcessWinnerPayoutResult(
            payoutId: $payout->id,
            gameWinnerId: (string) $winner->id,
            gameId: (string) $game->id,
            winnerUserId: (int) $winner->user_id,
            actorUserId: $data->actorUserId,
            amountCents: $payout->amount_cents,
            currency: $payout->currency,
            method: $payout->method,
            externalReference: $payout->external_reference,
            notes: $payout->notes,
            processedAt: $payout->processed_at->toIso8601String(),
            createdAt: $payout->created_at->toIso8601String(),
            documentId: $document->id,
            documentOriginalFilename: $document->original_filename,
            documentMimeType: $document->mime_type,
            documentSizeBytes: $document->size_bytes,
            documentCreatedAt: $document->created_at->toIso8601String(),
            wasAlreadyProcessed: false,
        );
    }

    private function resolveExistingPayout(WinnerPayout $existing, ProcessWinnerPayoutData $data, GameWinner $winner): ProcessWinnerPayoutResult
    {
        // Same key hash: validate fingerprint
        if (hash_equals($existing->idempotency_key_hash, $data->idempotencyKeyHash)) {
            $requestFingerprint = $this->computeFingerprint($data, (string) $winner->id);
            if (! hash_equals($existing->request_fingerprint, $requestFingerprint)) {
                throw IdempotencyKeyMismatch::forKey($data->idempotencyKeyHash);
            }
        }
        // Different key hash: payout already exists from another request → return with was_already_processed=true

        return $this->buildResultFromExistingPayout($existing);
    }

    private function buildResultFromExistingPayout(WinnerPayout $existing): ProcessWinnerPayoutResult
    {
        $document = WinnerPayoutDocument::query()->where('payout_id', $existing->id)->firstOrFail();

        return new ProcessWinnerPayoutResult(
            payoutId: $existing->id,
            gameWinnerId: $existing->game_winner_id,
            gameId: $existing->game_id,
            winnerUserId: $existing->user_id,
            actorUserId: $existing->processed_by_user_id,
            amountCents: $existing->amount_cents,
            currency: $existing->currency,
            method: $existing->method,
            externalReference: $existing->external_reference,
            notes: $existing->notes,
            processedAt: $existing->processed_at->toIso8601String(),
            createdAt: $existing->created_at->toIso8601String(),
            documentId: $document->id,
            documentOriginalFilename: $document->original_filename,
            documentMimeType: $document->mime_type,
            documentSizeBytes: $document->size_bytes,
            documentCreatedAt: $document->created_at->toIso8601String(),
            wasAlreadyProcessed: true,
        );
    }

    private function computeFingerprint(ProcessWinnerPayoutData $data, string $gameWinnerId): string
    {
        return hash('sha256', implode("\n", [
            'operation=winner_payout',
            'game_id='.$data->gameId,
            'game_winner_id='.$gameWinnerId,
            'actor_user_id='.$data->actorUserId,
            'external_reference='.mb_strtolower(trim($data->externalReference)),
            'notes='.mb_strtolower(trim($data->notes ?? '')),
            'document_sha256='.$data->documentSha256,
        ]));
    }
}
