<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Admin;

use App\Modules\Commerce\Application\Actions\MarkWinnerPayoutPaidAction;
use App\Modules\Commerce\Application\DTOs\MarkWinnerPayoutPaidData;
use App\Modules\Commerce\Application\DTOs\WinnerPayoutCommandResult;
use App\Modules\Commerce\Application\Queries\GetWinnerPayoutQuery;
use App\Modules\Commerce\Application\Support\IdempotencyContext;
use App\Modules\Commerce\Domain\Models\WinnerPayout;
use App\Modules\Commerce\Infrastructure\Idempotency\IdempotentCommandExecutor;
use App\Modules\Commerce\Infrastructure\Storage\WinnerPayoutDocumentStorage;
use App\Modules\Commerce\Presentation\Http\Requests\Admin\MarkWinnerPayoutPaidRequest;
use App\Modules\Commerce\Presentation\Http\Resources\Admin\AdminWinnerPayoutResource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class MarkWinnerPayoutPaidController
{
    public function __invoke(MarkWinnerPayoutPaidRequest $request, WinnerPayout $payout, MarkWinnerPayoutPaidAction $action, GetWinnerPayoutQuery $query, WinnerPayoutDocumentStorage $storage, IdempotentCommandExecutor $executor): Response
    {
        Gate::authorize('execute', $payout);
        /** @var UploadedFile $file */
        $file = $request->file('document');
        $analysis = $storage->analyse($file);
        $stored = $storage->store($file, (string) $payout->id, 'pending', $analysis);
        $actor = (int) $request->user()?->getKey();
        $key = (string) $request->header('Idempotency-Key');
        $context = IdempotencyContext::make($actor, $request->method(), $request->path(), $key, ['operation' => 'winner_payout.paid', 'payout_id' => $payout->id, 'actor_user_id' => $actor, 'external_reference' => $request->externalReference(), 'document_sha256' => $analysis->sha256]);

        try {
            /** @var WinnerPayoutCommandResult $result */
            $result = $executor->execute($context, fn (): WinnerPayoutCommandResult => $action->executeWithinTransaction(new MarkWinnerPayoutPaidData(
                payoutId: (string) $payout->id,
                actorUserId: $actor,
                idempotencyKeyHash: hash('sha256', $key),
                requestFingerprint: $context->payloadSha256,
                externalReference: $request->externalReference(),
                documentId: $stored['documentId'],
                documentDisk: $stored['disk'],
                documentPath: $stored['path'],
                documentOriginalFilename: $stored['originalFilename'],
                documentMimeType: $analysis->mimeType,
                documentSizeBytes: $analysis->sizeBytes,
                documentSha256: $analysis->sha256,
            )), fn (array $payload): WinnerPayoutCommandResult => WinnerPayoutCommandResult::fromArray($payload));

            if ($result->documentId !== $stored['documentId']) {
                $storage->delete($stored['disk'], $stored['path']);
            }

            return (new AdminWinnerPayoutResource($query->execute($result->payoutId)))->response()->setStatusCode(Response::HTTP_OK);
        } catch (\Throwable $exception) {
            $storage->delete($stored['disk'], $stored['path']);
            throw $exception;
        }
    }
}
