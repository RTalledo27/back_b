<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Support;

use App\Modules\Commerce\Application\Actions\SubmitPaymentEvidenceAction;
use App\Modules\Commerce\Application\DTOs\SubmitPaymentEvidenceData;
use App\Modules\Commerce\Application\DTOs\SubmitPaymentEvidenceResult;
use App\Modules\Commerce\Domain\Events\PaymentEvidenceSubmitted;
use App\Modules\Commerce\Domain\Exceptions\IdempotencyInProgress;
use App\Modules\Commerce\Domain\Exceptions\IdempotencyKeyMismatch;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Infrastructure\Idempotency\IdempotencyClaimResult;
use App\Modules\Commerce\Infrastructure\Idempotency\IdempotencyKeyStore;
use App\Modules\Commerce\Infrastructure\Storage\EvidenceAnalysis;
use App\Modules\Commerce\Infrastructure\Storage\PaymentEvidenceStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Specialised idempotent orchestrator for the payment-evidence flow.
 *
 * Differs from IdempotentCommandExecutor in that the file is written to
 * the private disk BEFORE the business transaction opens — long I/O must
 * never block PostgreSQL row locks.
 *
 * Failure model:
 *  - Any throwable from analyse() or the storage write happens before
 *    the transaction; the key is released and the exception propagates.
 *  - Any throwable from inside DB::transaction (Action throws OR commit
 *    fails) triggers compensation: delete stored file, release key,
 *    rethrow the ORIGINAL exception. Compensation steps that themselves
 *    fail are reported via report() but never replace the original.
 *  - PaymentEvidenceSubmitted is dispatched AFTER DB::transaction
 *    returns successfully. A failing listener is reported via report()
 *    and does NOT trigger compensation — the business commit is durable.
 *
 * Replay (CompletedSamePayload):
 *  - analyse() still runs because the file SHA-256 + MIME + size are
 *    part of the canonical idempotency hash. The guarantee is "zero new
 *    writes to storage", not "zero file inspection".
 */
final class SubmitPaymentEvidenceOrchestrator
{
    public function __construct(
        private readonly IdempotencyKeyStore $keys,
        private readonly PaymentEvidenceStorage $storage,
        private readonly SubmitPaymentEvidenceAction $action,
    ) {}

    public function handle(
        SubmitPaymentEvidenceData $data,
        UploadedFile $uploadedFile,
        string $idempotencyKey,
        string $requestMethod,
        string $requestPath,
    ): SubmitPaymentEvidenceResult {
        // Analysis is required even on replay — the file fingerprint is
        // part of the canonical idempotency hash. No file is *written* yet.
        $analysis = $this->storage->analyse($uploadedFile);

        $context = IdempotencyContext::make(
            userId: $data->userId,
            method: $requestMethod,
            path: $requestPath,
            key: $idempotencyKey,
            payloadComponents: [
                'order_id' => $data->orderId,
                'file_sha256' => $analysis->sha256,
                'mime_type' => $analysis->mimeType,
                'size_bytes' => $analysis->sizeBytes,
            ],
        );

        $claim = $this->keys->tryClaim($context);

        return match ($claim->result) {
            IdempotencyClaimResult::CompletedSamePayload => SubmitPaymentEvidenceResult::fromArray(
                (array) $claim->resultPayload,
            ),
            IdempotencyClaimResult::PayloadMismatch => throw IdempotencyKeyMismatch::forKey($context->key),
            IdempotencyClaimResult::InProgress => throw IdempotencyInProgress::forKey($context->key),
            IdempotencyClaimResult::Claimed => $this->runClaimed(
                rowId: (string) $claim->rowId,
                data: $data,
                uploadedFile: $uploadedFile,
                analysis: $analysis,
            ),
        };
    }

    private function runClaimed(
        string $rowId,
        SubmitPaymentEvidenceData $data,
        UploadedFile $uploadedFile,
        EvidenceAnalysis $analysis,
    ): SubmitPaymentEvidenceResult {
        $paymentId = (string) Payment::query()
            ->where('order_id', $data->orderId)
            ->value('id');

        // Long filesystem write happens here — explicitly outside the
        // database transaction.
        try {
            $stored = $this->storage->store($uploadedFile, $paymentId, $analysis);
        } catch (Throwable $e) {
            $this->safelyReleaseKey($rowId, $e);

            throw $e;
        }

        try {
            $result = DB::transaction(function () use ($data, $stored, $rowId): SubmitPaymentEvidenceResult {
                $actionResult = $this->action->executeWithinTransaction($data, $stored);

                $this->keys->markCompleted($rowId, $actionResult);

                return $actionResult;
            });
        } catch (Throwable $e) {
            // Pre-commit failure path. Rollback already happened inside
            // DB::transaction. Compensate the stored file and free the
            // idempotency slot. Compensation errors are reported but must
            // NOT replace the original cause.
            $this->safelyDeleteStoredFile($stored->disk, $stored->path, $e);
            $this->safelyReleaseKey($rowId, $e);

            throw $e;
        }

        // ----------------------------------------------------------------
        // From this point onwards the COMMIT has succeeded. Any failure
        // below must NOT delete the file or release the key, otherwise we
        // would corrupt a durable result.
        // ----------------------------------------------------------------

        $createdNewDocument = $result->documentId === $stored->documentId;

        if (! $createdNewDocument) {
            // File-content idempotency: the Action returned an existing
            // PaymentDocument. The just-stored file is a duplicate copy of
            // an already-recorded one — safe to delete. This is NOT
            // compensation, it is cleanup of an extra physical file.
            $this->safelyDeleteStoredFile(
                $stored->disk,
                $stored->path,
                cause: null,
            );

            return $result;
        }

        // Fresh submission → fire the after-commit domain event. A
        // throwing listener is logged via report() and the durable
        // business result is returned to the caller.
        try {
            PaymentEvidenceSubmitted::dispatch(
                $result->orderId,
                $result->paymentId,
                $result->documentId,
                $data->userId,
            );
        } catch (Throwable $dispatchError) {
            report($dispatchError);
        }

        return $result;
    }

    private function safelyDeleteStoredFile(string $disk, string $path, ?Throwable $cause): void
    {
        try {
            $this->storage->delete($disk, $path);
        } catch (Throwable $cleanupError) {
            // Never let cleanup overwrite the original failure cause.
            report($cleanupError);
        }
    }

    private function safelyReleaseKey(string $rowId, ?Throwable $cause): void
    {
        try {
            $this->keys->releaseAbandoned($rowId);
        } catch (Throwable $releaseError) {
            report($releaseError);
        }
    }
}
