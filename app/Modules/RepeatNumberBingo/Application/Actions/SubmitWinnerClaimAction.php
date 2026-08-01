<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Actions;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Application\DTOs\SubmitWinnerClaimData;
use App\Modules\RepeatNumberBingo\Application\DTOs\WinnerClaimSubmissionResult;
use App\Modules\RepeatNumberBingo\Application\DTOs\WinnerIdentityDocumentData;
use App\Modules\RepeatNumberBingo\Domain\Enums\WinnerClaimEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\WinnerClaimStatus;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\WinnerClaimNotProcessable;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaim;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaimEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerIdentityDocument;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerIdentityProfile;
use Illuminate\Support\Facades\DB;
use LogicException;

final class SubmitWinnerClaimAction
{
    public function executeWithinTransaction(SubmitWinnerClaimData $data): WinnerClaimSubmissionResult
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'SubmitWinnerClaimAction::executeWithinTransaction requires an active database transaction.'
            );
        }

        $gameId = (string) GameWinner::query()->whereKey($data->winnerId)->value('game_id');
        if ($gameId === '') {
            throw WinnerClaimNotProcessable::ownership();
        }

        Game::query()->whereKey($gameId)->lockForUpdate()->firstOrFail();
        $winner = GameWinner::query()->whereKey($data->winnerId)->lockForUpdate()->firstOrFail();

        if ((int) $winner->user_id !== $data->userId) {
            throw WinnerClaimNotProcessable::ownership();
        }

        $user = User::query()->findOrFail($data->userId);
        if (! $user->hasVerifiedEmail()) {
            throw WinnerClaimNotProcessable::emailNotVerified();
        }

        $claim = WinnerClaim::query()
            ->where('game_winner_id', $winner->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($claim->claim_window_started_at === null || $claim->expires_at === null) {
            throw WinnerClaimNotProcessable::claimWindowNotStarted($claim->id);
        }
        if ($claim->expires_at->isPast()) {
            throw WinnerClaimNotProcessable::expired($claim->id);
        }
        if ($claim->status !== WinnerClaimStatus::PendingClaim) {
            throw WinnerClaimNotProcessable::status($claim->id, $claim->status->value);
        }
        if (! $data->acceptedPrizeTerms || ! $data->consentedIdentityProcessing) {
            throw new LogicException('Prize terms and identity processing consent are required.');
        }

        $allowedTypes = array_map('strval', (array) config('winner_claim.identity.document_types', []));
        if (! in_array($data->documentType, $allowedTypes, true)) {
            throw new LogicException('Identity document type is not allowed.');
        }

        $maxDocuments = (int) config('winner_claim.identity.max_documents', 3);
        if ($data->documents === [] || count($data->documents) > $maxDocuments) {
            throw WinnerClaimNotProcessable::missingDocuments($claim->id);
        }

        $now = now();
        WinnerIdentityProfile::create([
            'winner_claim_id' => $claim->id,
            'document_type' => $data->documentType,
            'legal_name_encrypted' => trim($data->legalName),
            'document_number_encrypted' => trim($data->documentNumber),
            'document_number_masked' => $this->maskDocumentNumber($data->documentNumber),
            'accepted_prize_terms_at' => $now,
            'consented_identity_processing_at' => $now,
        ]);

        foreach ($data->documents as $document) {
            /** @var WinnerIdentityDocumentData $document */
            WinnerIdentityDocument::create([
                'id' => $document->documentId,
                'winner_claim_id' => $claim->id,
                'document_type' => $document->documentType,
                'disk' => $document->disk,
                'path' => $document->path,
                'mime_type' => $document->mimeType,
                'size_bytes' => $document->sizeBytes,
                'sha256' => $document->sha256,
                'uploaded_by_user_id' => $data->userId,
                'created_at' => $now,
            ]);
        }

        $claim->transitionTo(WinnerClaimStatus::IdentityPending);
        $claim->claimed_at = $now;
        $claim->identity_submitted_at = $now;
        $claim->idempotency_key_hash = $data->idempotencyKeyHash;
        $claim->request_fingerprint = $data->requestFingerprint;
        $claim->save();

        WinnerClaimEvent::create([
            'winner_claim_id' => $claim->id,
            'event_type' => WinnerClaimEventType::ClaimSubmitted,
            'from_status' => WinnerClaimStatus::PendingClaim->value,
            'to_status' => WinnerClaimStatus::IdentityPending->value,
            'actor_user_id' => $data->userId,
            'actor_type' => 'winner',
            'reason_code' => 'winner_claim_submitted',
            'safe_metadata' => [
                'document_count' => count($data->documents),
                'document_types' => array_values(array_map(
                    static fn (WinnerIdentityDocumentData $document): string => $document->documentType,
                    $data->documents,
                )),
            ],
            'correlation_id' => null,
            'occurred_at' => $now,
            'created_at' => $now,
        ]);

        return new WinnerClaimSubmissionResult(
            claimId: $claim->id,
            claimReference: $claim->claim_reference,
            status: $claim->status->value,
            identitySubmittedAt: $now->utc()->toIso8601String(),
            documentCount: count($data->documents),
        );
    }

    private function maskDocumentNumber(string $documentNumber): string
    {
        $normalized = preg_replace('/\s+/', '', trim($documentNumber)) ?? trim($documentNumber);
        $visible = mb_substr($normalized, -4);

        return str_repeat('*', max(0, mb_strlen($normalized) - 4)).$visible;
    }
}
