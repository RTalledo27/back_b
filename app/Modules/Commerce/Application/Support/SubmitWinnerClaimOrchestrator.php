<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Support;

use App\Modules\Commerce\Application\DTOs\WinnerClaimSubmissionCommandResult;
use App\Modules\Commerce\Domain\Exceptions\IdempotencyInProgress;
use App\Modules\Commerce\Domain\Exceptions\IdempotencyKeyMismatch;
use App\Modules\Commerce\Infrastructure\Idempotency\IdempotencyClaimResult;
use App\Modules\Commerce\Infrastructure\Idempotency\IdempotencyKeyStore;
use App\Modules\RepeatNumberBingo\Application\Actions\SubmitWinnerClaimAction;
use App\Modules\RepeatNumberBingo\Application\DTOs\SubmitWinnerClaimData;
use App\Modules\RepeatNumberBingo\Application\DTOs\WinnerIdentityDocumentData;
use App\Modules\RepeatNumberBingo\Infrastructure\Storage\WinnerIdentityDocumentAnalysis;
use App\Modules\RepeatNumberBingo\Infrastructure\Storage\WinnerIdentityDocumentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SubmitWinnerClaimOrchestrator
{
    public function __construct(
        private readonly IdempotencyKeyStore $keys,
        private readonly WinnerIdentityDocumentStorage $storage,
        private readonly SubmitWinnerClaimAction $action,
    ) {}

    /**
     * @param  array<string, UploadedFile>  $files
     */
    public function handle(
        SubmitWinnerClaimData $data,
        array $files,
        string $idempotencyKey,
        string $requestMethod,
        string $requestPath,
    ): WinnerClaimSubmissionCommandResult {
        $analysed = [];
        foreach ($files as $type => $file) {
            $analysed[$type] = $this->storage->analyse($file);
        }

        $context = IdempotencyContext::make(
            userId: $data->userId,
            method: $requestMethod,
            path: $requestPath,
            key: $idempotencyKey,
            payloadComponents: [
                'winner_id' => $data->winnerId,
                'legal_name_hash' => hash('sha256', mb_strtolower(trim($data->legalName))),
                'document_number_hash' => hash('sha256', preg_replace('/\s+/', '', trim($data->documentNumber)) ?? trim($data->documentNumber)),
                'document_type' => $data->documentType,
                'documents' => array_map(
                    static fn (string $type, WinnerIdentityDocumentAnalysis $analysis): array => [
                        'type' => $type,
                        'sha256' => $analysis->sha256,
                        'mime_type' => $analysis->mimeType,
                        'size_bytes' => $analysis->sizeBytes,
                    ],
                    array_keys($analysed),
                    array_values($analysed),
                ),
            ],
        );

        $claim = $this->keys->tryClaim($context);

        return match ($claim->result) {
            IdempotencyClaimResult::CompletedSamePayload => WinnerClaimSubmissionCommandResult::fromArray((array) $claim->resultPayload),
            IdempotencyClaimResult::PayloadMismatch => throw IdempotencyKeyMismatch::forKey($context->key),
            IdempotencyClaimResult::InProgress => throw IdempotencyInProgress::forKey($context->key),
            IdempotencyClaimResult::Claimed => $this->runClaimed(
                rowId: (string) $claim->rowId,
                data: $data,
                files: $files,
                analysed: $analysed,
                idempotencyKeyHash: hash('sha256', $idempotencyKey),
                requestFingerprint: $context->payloadSha256,
            ),
        };
    }

    /** @param array<string, UploadedFile> $files @param array<string, WinnerIdentityDocumentAnalysis> $analysed */
    private function runClaimed(
        string $rowId,
        SubmitWinnerClaimData $data,
        array $files,
        array $analysed,
        string $idempotencyKeyHash,
        string $requestFingerprint,
    ): WinnerClaimSubmissionCommandResult {
        $stored = [];

        try {
            foreach ($files as $type => $file) {
                $stored[$type] = $this->storage->store($file, $data->winnerId, $analysed[$type]);
            }

            $documents = [];
            foreach ($stored as $type => $storedData) {
                $analysis = $analysed[$type];
                $documents[] = new WinnerIdentityDocumentData(
                    documentType: $type,
                    documentId: $storedData['documentId'],
                    disk: $storedData['disk'],
                    path: $storedData['path'],
                    mimeType: $analysis->mimeType,
                    sizeBytes: $analysis->sizeBytes,
                    sha256: $analysis->sha256,
                );
            }

            $result = DB::transaction(function () use ($data, $documents, $rowId, $idempotencyKeyHash, $requestFingerprint): WinnerClaimSubmissionCommandResult {
                $actionResult = $this->action->executeWithinTransaction(new SubmitWinnerClaimData(
                    winnerId: $data->winnerId,
                    userId: $data->userId,
                    legalName: $data->legalName,
                    documentType: $data->documentType,
                    documentNumber: $data->documentNumber,
                    acceptedPrizeTerms: $data->acceptedPrizeTerms,
                    consentedIdentityProcessing: $data->consentedIdentityProcessing,
                    documents: $documents,
                    idempotencyKeyHash: $idempotencyKeyHash,
                    requestFingerprint: $requestFingerprint,
                ));
                $commandResult = new WinnerClaimSubmissionCommandResult($actionResult);
                $this->keys->markCompleted($rowId, $commandResult);

                return $commandResult;
            });
        } catch (Throwable $exception) {
            foreach ($stored as $storedData) {
                $this->storage->delete($storedData['disk'], $storedData['path']);
            }
            $this->keys->releaseAbandoned($rowId);

            throw $exception;
        }

        return $result;
    }
}
